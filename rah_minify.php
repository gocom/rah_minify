<?php

/**
 * Rah_minify plugin for Textpattern CMS.
 *
 * @author Jukka Svahn
 * @date 2011-
 * @license GNU GPLv2
 * @link https://github.com/gocom/rah_minify
 *
 * Copyright (C) 2011 Jukka Svahn <http://rahforum.biz>
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	new rah_minify();

class rah_minify {
	
	static public $version = '0.1';
	
	/**
	 * @var array List files set for compression
	 */
	
	protected $files = array();
	
	/**
	 * @var array Stack of queued files for processing
	 */
	
	protected $stack = array();
	
	/**
	 * @var array Files available for reading
	 */
	
	protected $read = array();
	
	/**
	 * @var string Path to YUIcompressor
	 */
	
	public $yui;
	
	/**
	 * @var string Java command
	 */
	
	public $java = 'export DYLD_LIBRARY_PATH=""; java';
	
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
				'files' => array('rah_minify_files', ''),
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
		
		if($event == 'textpattern' && $production_status == 'live') {
			return;
		}
		
		foreach(array('versions', 'files', 'yui', 'java') as $name) {
			$this->$name = get_pref(__CLASS__.'_'.$name);
		}
		
		if(trim($this->files)) {
			$files = array();
			
			foreach(do_list($this->files, n) as $file) {
				$file = explode(' ', $file);
				$target = array_pop($file);
				$files[implode(' ', $file)] = $target;
			}
			
			$this->files = $files;
		}
		
		$this->files = array_merge((array) $rah_minify, is_array($this->files) ? $this->files : array());
		
		if(!$this->files) {
			return;
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
	
		foreach($this->files as $path => $to) {
			
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
				$this->create_version();
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
			trace_add('[rah_minify: writing to '.basename($this->target).' failed]');
			return;
		}
		
		touch($this->target);
		trace_add('[rah_minify: '.basename($this->target).' updated]');
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
		$name = 'v.'.basename($this->target, '.'.$ext).'.'.filemtime($this->target).'.'.$ext;
		$to = dirname($this->target).'/'.$name;
		
		if(file_exists($to)) {
			trace_add('[rah_minify: versioned '.$name.' already exists]');
			return;
		}
		
		if(file_put_contents($to, $this->output) === false) {
			trace_add('[rah_minify: writing to '.$name.' failed]');
			return;
		}
		
		trace_add('[rah_minify: created '.$name.']');
	}
	
	/**
	 * Compress JavaScript
	 */
	
	protected function compress_js() {
		
		if($this->yui) {
			if(file_put_contents($this->target, $this->input) !== false) {
				$data = array();
				exec($this->java . ' -jar ' . $this->yui . ' ' . $this->target, $data);
				$this->output = implode('', (array) $data);
			}
			
			return;
		}
		
		$this->output = rah_minify_JSMin::minify($this->input);
	}

	/**
	 * Compress CSS
	 */

	protected function compress_css() {
		$cssmin = new rah_minify_CSSmin(false);
		$this->output = $cssmin->run($this->input);
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
	
	protected function format_path($path) {
		
		if(strpos($path, './') === 0) {
			return txpath . '/' . substr($path, 2);
		}
		
		if(strpos($path, '../') === 0) {
			return dirname(txpath) . '/' . substr($path, 3);
		}
		
		return $path;
	}
}

/**
 * Files option
 * @param string $name
 * @param string $value
 * @return string HTML
 */

	function rah_minify_files($name, $value) {
		return text_area($name, 100, 300, $value, $name);
	}
?>