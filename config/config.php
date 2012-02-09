<?php

/**
 * This is example configuration file for rah_minify.
 * Above should be placed to Textpattern's config file
 * (i.e. /textpattern/config.php).
 *
 * Replace all the example filepaths to with real ones.
 */

include '/path/to/minify_css_compressor.php';
include '/path/to/jsmin.php';

/**
 * Sets YUIcompressor.jar's location
 */

define('rah_minify_yui', '/path/to/yuicompressor.php');

/**
 * Sets the files that are compressed.
 */

$rah_minify = array(
	'/path/to/foo.js' => '/path/to/min.foo.js',
	'/path/to/bar.css' => '/path/to/min.bar.css',
);

?>