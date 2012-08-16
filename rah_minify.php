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

	register_callback(array('rah_minify', 'handler'), 'textpattern');
	register_callback(array('rah_minify', 'handler'), 'admin_side', 'body_end');

/**
 * Minify CSS and JavaScript files when the site is in debugging or testing mode.
 */

class rah_minify {

	/**
	 * @var obj Stores instances
	 */

	static public $instance = NULL;
	
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
	 * Gets an instance of the class
	 */

	static public function get() {
		
		if(self::$instance === NULL) {
			self::$instance = new rah_minify();
		}
		
		return self::$instance;
	}
	
	/** 
	 * Handles callback
	 * @param string $event Callback event
	 */
	
	static public function handler($event='') {
		
		global $rah_minify, $production_status;
		
		if(!$rah_minify || ($event == 'textpattern' && $production_status == 'live'))
			return;
		
		self::get();
	}

	/**
	 * Collects updated files
	 */

	public function __construct() {
	
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
		
		if(defined('rah_minify_yui') && rah_minify_yui && function_exists('exec') && file_exists(rah_minify_yui)) {
			$this->yui = rah_minify_yui;
		}
		
		$this->java = defined('rah_minify_java_cmd') ? 
			rah_minify_java_cmd : 'export DYLD_LIBRARY_PATH=""; java';
		
		foreach($this->stack as $to => $paths) {
			
			if(in_array($to, $this->read)) {
				$this->process($to, $paths);
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
	 * @param string $to
	 * @param array $paths
	 */
	
	private function process($to, $paths) {
		
		$write = array();
		$less = NULL;
		
		foreach($paths as $path) {
			$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
			$data = array();
			
			if($ext == 'js') {
				
				if($this->yui && $this->java) {
					@exec($this->java . ' -jar ' . $this->yui . ' ' . $path, $data);
					$data = implode('', (array) $data);
				}
				
				elseif(class_exists('JSMin')) {
					$data = JSMin::minify(file_get_contents($path));
				}
				
				else {
					trace_add('[rah_minify: no JavaScript compressor configured]');
					return;
				}
			}
			
			else {
				$data = file_get_contents($path);
			}
			
			if($ext == 'less' && !$less) {
				
				if(!class_exists('lessc')) {
					trace_add('[rah_minify: lessc class is unavailable]');
					return;
				}
				
				$less = new lessc();
			}
			
			$write[] = (string) $data;
		}
		
		$write = implode(n, $write);
		
		if($less) {
			try {
				@$write = $less->parse($write);
			}
			catch(exception $e) {
				trace_add('[rah_minify: LESSPHP said "'.$e->getMessage().'"]');
				return;
			}
		}
		
		if($less || $ext == 'css') {
		
			if(!class_exists('Minify_CSS_Compressor')) {
				trace_add('[rah_minify: Minify_CSS_Compressor class is unavailable]');
				return;
			}
		
			$write = Minify_CSS_Compressor::process($write);
		}
		
		$write = trim($write);
		
		if(file_put_contents($to, $write) === false) {
			trace_add('[rah_minify: writing to '.basename($to).' failed]');
			return;
		}
		
		touch($to);
		trace_add('[rah_minify: '.basename($to).' updated]');
		
		$v = dirname($to).'/v';
			
		if(!file_exists($v) || !is_writable($v) || !is_dir($v)) {
			return;
		}
		
		clearstatcache();
		$to = $v.'/'.basename($to, $ext).'.'.filemtime($to).'.'.$ext;
		
		if(file_exists($to)) {
			trace_add('[rah_minify: versioned '.basename($to).' already exists]');
			return;
		}
		
		if(file_put_contents($to, $write) === false) {
			trace_add('[rah_minify: writing to '.basename($to).' failed]');
			return;
		}
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