<?php

/**
 * Rah_minify plugin for Textpattern CMS.
 *
 * @author Jukka Svahn
 * @date 2011-
 * @license GNU GPLv2
 * @link https://github.com/gocom/rah_minify
 *
 * The plugin minifies CSS and JavaScript files when
 * the site is in debugging or testing mode.
 *
 * Requires Textpattern v4.4.1 or newer. Minify_CSS_Compressor class 
 * (Compressor.php) from "Minify" http://code.google.com/p/minify. Either
 * YUIcompressor (java) or JSMin-php https://github.com/rgrove/jsmin-php
 *
 * Copyright (C) 2011 Jukka Svahn <http://rahforum.biz>
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	new rah_minify();

class rah_minify {
	
	static public $version = '0.1';
	
	/**
	 * @var array Stack of queued files for processing
	 */
	
	private $stack = array();
	
	/**
	 * @var array Files available for reading
	 */
	
	private $read = array();
	
	/**
	 * @var string Path to YUIcompressor
	 */
	
	public $yui;
	
	/**
	 * @var string Java command
	 */
	
	public $java;
	
	/**
	 * @var bool Turns versioning on
	 */
	
	public $versions = false;
	
	/**
	 * @var string Current file path
	 */
	
	protected $source;
	
	/**
	 * @var string Target output file
	 */
	
	protected $target;
	
	/**
	 * @var string Current files compressed output
	 */
	
	protected $output;
	
	/**
	 * @var string Incoming data for compression
	 */
	
	protected $input;
	
	/**
	 * Installer
	 * @param string $event Admin-side event.
	 * @param string $step Admin-side, plugin-lifecycle step.
	 */

	static public function install($event='', $step='') {
		
		global $prefs;
		
		if($step == 'deleted') {
			
			safe_delete(
				'txp_prefs',
				"name like 'rah\_minify\_%'"
			);
			
			return;
		}
		
		if((string) get_pref(__CLASS__.'_version') === self::$version) {
			return;
		}
		
		$position = 250;
		
		foreach(
			array(
				'yui' => array('text_input', ''),
				'java' => array('text_input', 'java'),
				'versions' => array('yesnoradio', 0),
			) as $name => $val
		) {
			$n =  __CLASS__.'_'.$name;
			
			if(!isset($prefs[$n])) {
				set_pref($n, $val[1], __CLASS__, PREF_ADVANCED, $val[0], $position);
				$prefs[$n] = $val[1];
			}
			
			$position++;
		}
		
		set_pref(__CLASS__.'_version', self::$version, __CLASS__, PREF_HIDDEN, '', PREF_PRIVATE);
		$prefs[__CLASS__.'_version'] = self::$version;
	}
	
	/**
	 * Constructor
	 */
	
	public function __construct() {
		global $event;
		register_callback(array(__CLASS__, 'install'), 'plugin_lifecycle.'.__CLASS__);
		register_callback(array($this, 'handler'), $event ? $event : 'textpattern');
	}
	
	/** 
	 * Handles callback
	 * @param string $event Callback event
	 */
	
	public function handler($event='') {
		
		global $rah_minify, $production_status;
		
		if(!$rah_minify || ($event == 'textpattern' && $production_status == 'live')) {
			return;
		}
		
		foreach(array('yui', 'java', 'versions') as $name) {
			$this->$name = get_pref(__CLASS__.'_'.$name);
		}
		
		if(!$this->java || !$this->yui || !function_exists('exec')) {
			$this->yui = null;
		}
		
		$this->collect_files();
	}

	/**
	 * Collects updated files
	 */

	public function collect_files() {
	
		global $rah_minify;
	
		foreach($rah_minify as $path => $to) {
			
			$to = $this->format_path($to);
			$path = $this->format_path($path);
		
			if(!file_exists($path) || !is_file($path) || !is_readable($path)) {
				trace_add('[rah_minify: '.basename($path).' (source) can not be read]');
				continue;
			}
			
			$this->stack[$to][] = $path;
			
			if(in_array($to, $this->read)) {
				continue;
			}
			
			if(file_exists($to)) {
				
				if(!is_file($to) || !is_writable($to) || !is_readable($to)) {
					trace_add('[rah_minify: '.basename($path).' -> '.basename($to).' (target) is not writeable]');
					continue;
				}
				
				$time = filemtime($to);
				
				if($time >= filemtime($path)) {
					continue;
				}
			}
			
			$this->read[] = $to;
		}
		
		foreach($this->stack as $to => $paths) {
			
			if(in_array($to, $this->read)) {
				$this->source = $paths;
				$this->target = $to;
				$this->process();
			}
			
			else {
				trace_add('[rah_minify: '.basename($to).' is up to date]');
			}
		}
		
		if($this->read) {
			update_lastmod();
		}
	}
	
	/**
	 * Process and minify files
	 */
	
	protected function process() {
		
		foreach((array) $this->source as $path) {
			$data[] = file_get_contents($path);
		}
		
		$this->input = implode(n, $data);
		$this->output = '';
		
		$method = 'compress_'.strtolower(pathinfo($path, PATHINFO_EXTENSION));
		
		if(method_exists($this, $method)) {
			$this->$method();
		}
		
		$this->output = trim($this->output);
		
		if(file_put_contents($this->target, $this->output) === false) {
			trace_add('[rah_minify: writing to '.basename($to).' failed]');
			return;
		}
		
		touch($this->target);
		trace_add('[rah_minify: '.basename($this->target).' updated]');
		$this->create_version();
	}
	
	/**
	 * Versioning
	 */
	
	protected function create_version() {
		
		if(!$this->versions) {
			return;
		}
		
		clearstatcache();
		
		$ext = pathinfo($this->target, PATHINFO_EXTENSION);
		$to = dirname($this->target).'/v.'.basename($this->target, '.'.$ext).'.'.filemtime($this->target).'.'.$ext;
		
		if(file_exists($to)) {
			trace_add('[rah_minify: versioned '.basename($to).' already exists]');
		}
		
		else if(file_put_contents($to, $write) === false) {
			trace_add('[rah_minify: writing to '.basename($to).' failed]');
		}
	}
	
	/**
	 * Compress JavaScript
	 */
	
	protected function compress_js() {
		
		if($this->yui) {
			file_put_contents($this->target, implode(n, $this->input));
			exec($this->java . ' -jar ' . $this->yui . ' ' . $this->target, $data);
			$this->output = implode('', (array) $data);
		}
		
		elseif(class_exists('JSMin')) {
			$this->output = JSMin::minify($this->input);
		}
		
		else {
			trace_add('[rah_minify: no JavaScript compressor configured]');
		}
	}
	
	/**
	 * Compress CSS
	 */
	
	protected function compress_css() {
		
		if(!class_exists('Minify_CSS_Compressor')) {
			trace_add('[rah_minify: Minify_CSS_Compressor class is unavailable]');
			return;
		}
		
		$this->output = Minify_CSS_Compressor::process($this->input);
	}
	
	/**
	 * Process and compress LESS
	 */
	
	protected function compress_less() {
	
		if(!class_exists('lessc')) {
			trace_add('[rah_minify: lessc class is unavailable]');
			return;
		}
		
		$less = new lessc();
		
		try {
			$this->output = $less->parse($this->input);
		}
		
		catch(exception $e) {
			trace_add('[rah_minify: LESSPHP said "'.$e->getMessage().'"]');
			return;
		}
		
		$this->input = $this->output;
		$this->compress_css();
	}
	
	/**
	 * Formats paths
	 * @param string $path
	 * @return string Path
	 */
	
	private function format_path($path) {
		
		if(strpos($path, './') === 0) {
			return txpath . '/' . substr($path, 2);
		}
		
		if(strpos($path, '../') === 0) {
			return dirname(txpath) . '/' . substr($path, 3);
		}
		
		return $path;
	}
}

?>