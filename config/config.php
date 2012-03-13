<?php

/**
 * This is an example configuration file for rah_minify plugin.
 * Following should be placed to Textpattern's config file
 * (i.e. /textpattern/config.php).
 *
 * Replace all the example filepaths to with real ones. Note that
 * used paths should be absolute (relative can cause problems). 
 * To build dynamic (i.e. relative) paths, use __FILE__ or txpath constant.
 */

/**
 * Include required libraries, Minify's Minify_CSS_Compressor class 
 * (css/compress.php), JSMin-php's JSmin class (jsmin.php) and lessphp.
 * @link https://github.com/mrclay/minify
 * @link https://github.com/rgrove/jsmin-php
 * @link https://github.com/leafo/lessphp
 */

include '/absolute/path/to/minify_css_compressor.php';
include '/absolute/path/to/jsmin.php';
include '/absolute/path/to/lessc.inc.php';

/**
 * Path to YUIcompressor.jar
 * @const rah_minify_yui 
 * @link https://github.com/yui/yuicompressor/
 */

define('rah_minify_yui', '/absolute/path/to/yuicompressor.jar');

/**
 * Sets the files that should be processed and minified.
 * @global array $rah_minify
 */

$rah_minify = array(
	'/path/to/foo.js' => '/path/to/min.foo.js',
	'/path/to/bar.css' => '/path/to/min.bar.css',
	'/path/to/hf.less' => '/path/to/min.hf.css',
);

?>