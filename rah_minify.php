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

	if(@txpinterface == 'public') {
		register_callback(array('rah_minify', 'get'), 'textpattern');
	}
	elseif(@txpinterface == 'admin') {
		register_callback(array('rah_minify', 'get'), 'admin_side', 'body_end');
	}

/**
 * Minify CSS and JavaScript files when the site is in debugging or testing mode.
 * @param string $event Callback event
 */

class rah_minify {

	private $stack = array();
	private $read = array();
	private $yui;
	private $java;
	
	/**
	 * Gets a new instance
	 * @param string $event Callback event
	 */

	static public function get($event='') {
		
		global $rah_minify, $production_status;
		
		if(!$rah_minify || ($event == 'textpattern' && $production_status == 'live'))
			return;
		
		new rah_minify();
	}

	/**
	 * Collects updated files
	 */

	public function __construct() {
	
		global $rah_minify;
	
		foreach($rah_minify as $path => $to) {
		
			if(!file_exists($path) || !is_file($path) || !is_readable($to)) {
				trace_add('[rah_minify: '.basename($path).' (source) can not be read]');
				continue;
			}
			
			$this->stack[$to][] = $path;
			
			if(file_exists($to)) {
				
				if(!is_file($to) || !is_writable($to)) {
					trace_add('[rah_minify: '.basename($to).' (target) is not writeable]');
					continue;
				}
				
				$time = filemtime($to);
				
				if($time >= filemtime($path)) {
					trace_add('[rah_minify: '.basename($to).' ('.$time.') is up to date]');
					continue;
				}
			}
			
			$this->read[] = $to;
		}
		
		if(!$this->read) {
			return;
		}
		
		if(defined('rah_minify_yui') && rah_minify_yui && file_exists(rah_minify_yui)) {
			$this->yui = rah_minify_yui;
		}
		
		$this->java = defined('rah_minify_java_cmd') ? 
			rah_minify_java_cmd : 'export DYLD_LIBRARY_PATH=""; java';
		
		foreach($this->read as $stack) {
			$this->process($stack, $this->stack[$stack]);
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
			$ext = pathinfo($path, PATHINFO_EXTENSION);
			$data = array();
			
			if($ext == 'js') {
				
				if($this->yui) {
					@exec($this->java . ' -jar ' . $this->yui . ' ' . $path, $data);
					$data = implode('', (array) $data);
				}
				
				elseif(class_exists('JSMin')) {
					$data = JSMin::minify(file_get_contents($path));
				}
			}
			
			else {
				$data = file_get_contents($path);
			}
			
			if($ext == 'less' && !$less && class_exists('lessc')) {
				$less = new lessc();
			}
			
			$write[] = (string) $data;
		}
		
		$write = implode(n, $write);
		
		if($less) {
			$write = $less->parse($write);
		}
		
		if(($less || $ext == 'css') && class_exists('Minify_CSS_Compressor')) {
			$write = Minify_CSS_Compressor::process($write);
		}
		
		file_put_contents($to, $write);
		trace_add('[rah_minify: '.basename($to).' updated]');
	}
}

?>