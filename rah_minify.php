<?php

/**
 * Rah_minify plugin for Textpattern CMS.
 *
 * @author Jukka Svahn
 * @date 2011-
 * @license GNU GPLv2
 * @link https://github.com/gocom/rah_minify
 *
 * The plugin minifies CSS and JavaScript whiles while
 * the site is in debugging mode.
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
		register_callback('rah_minify', 'textpattern');
	}
	elseif(@txpinterface == 'admin') {
		register_callback('rah_minify', 'admin_side', 'body_end');
	}

/**
 * Minify CSS and JavaScript files when the site is in debugging or testing mode.
 * @param string $event Callback event
 */

	function rah_minify($event='') {
		
		global $rah_minify, $production_status;
		
		if(!$rah_minify || ($event == 'textpattern' && $production_status == 'live'))
			return;
		
		$write = array();
		
		foreach($rah_minify as $path => $to) {
			
			if(!file_exists($path) || !is_file($path) || !is_readable($to)) {
				trace_add('[rah_minify: '.basename($path).' source can not be read]');
				continue;
			}
			
			if(file_exists($to)) {
				
				if(!is_file($to) || !is_writable($to)) {
					trace_add('[rah_minify: '.basename($to).' is not writeable]');
					continue;
				}
				
				$time = filemtime($to);
				
				if($time >= filemtime($path)) {
					trace_add('[rah_minify: '.basename($to).' ('.$time.') is up to date]');
					continue;
				}
			}
		
			$ext = pathinfo($path, PATHINFO_EXTENSION);
			$data = file_get_contents($path);
			
			if($ext == 'js') {
				
				if(defined('rah_minify_yui') && file_exists(rah_minify_yui)) {
					@exec('java -jar ' . rah_minify_yui . ' ' . $path, $data);
				}
				
				elseif(class_exists('JSMin')) {
					$data = JSMin::minify($data);
				}
			}
			
			if($ext == 'less' && class_exists('lessc')) {
				$ext = 'css';
				$less = new lessc($path);
				$data = $less->parse();
			}
			
			if($ext == 'css' && class_exists('Minify_CSS_Compressor')) {
				$data = Minify_CSS_Compressor::process($data);
			}
			
			$write[$to][] = $data;
		}
		
		foreach($write as $to => $data) {
			file_put_contents($to, implode(n, $data));
			trace_add('[rah_minify: '.basename($to).' updated]');
		}
	}
?>