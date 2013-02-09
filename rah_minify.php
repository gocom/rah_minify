<?php

/**
 * Rah_minify plugin for Textpattern CMS.
 *
 * @author  Jukka Svahn
 * @date    2011-
 * @license GNU GPLv2
 * @link    https://github.com/gocom/rah_minify
 *
 * Copyright (C) 2011 Jukka Svahn http://rahforum.biz
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	new rah_minify();

/**
 * The plugin class.
 */

class rah_minify
{
	/**
	 * Version number.
	 *
	 * @var string
	 */

	public $version = '0.1';

	/**
	 * List files set for compression.
	 *
	 * @var array
	 */

	protected $files = array();

	/**
	 * Stack of queued files for processing.
	 *
	 * @var array
	 */

	protected $stack = array();

	/**
	 * Files available for reading.
	 *
	 * @var array
	 */

	protected $read = array();

	/**
	 * Turns versioning on.
	 *
	 * @var bool
	 */

	protected $versions = false;

	/**
	 * Use Google Closure Compiler Service for JavaScript.
	 *
	 * @var bool
	 */

	protected $closure = false;

	/**
	 * Run the source with Textpattern tag parser.
	 *
	 * @var bool
	 */

	protected $parse = false;

	/**
	 * Current file path.
	 *
	 * @var string
	 */

	protected $source;

	/**
	 * Target output file.
	 *
	 * @var string
	 */

	protected $target;

	/**
	 * The current file's compressed output.
	 *
	 * @var string
	 */

	protected $output;

	/**
	 * Incoming data for compression.
	 *
	 * @var string
	 */

	protected $input;

	/**
	 * Installer.
	 *
	 * @param string $event Plugin-lifecycle event
	 * @param string $step  Plugin-lifecycle step
	 */

	public function install($event = '', $step = '')
	{
		global $prefs;

		if ($step == 'deleted')
		{
			safe_delete(
				'txp_prefs',
				"name like 'rah\_minify\_%'"
			);

			return;
		}

		if ((string) get_pref('rah_minify_version') === $this->version)
		{
			return;
		}

		$position = 250;

		foreach(
			array(
				'files'    => array('pref_longtext_input', ''),
				'versions' => array('yesnoradio', 0),
				'closure'  => array('yesnoradio', 0),
				'parse'    => array('yesnoradio', 0),
			) as $name => $val
		)
		{
			$n =  'rah_minify_'.$name;

			if (!isset($prefs[$n]))
			{
				set_pref($n, $val[1], 'rah_minify', PREF_ADVANCED, $val[0], $position);
				$prefs[$n] = $val[1];
			}

			$position++;
		}

		set_pref('rah_minify_version', $this->version, 'rah_minify', PREF_HIDDEN, '', PREF_PRIVATE);
	}

	/**
	 * Constructor.
	 */

	public function __construct()
	{
		global $event;
		add_privs('prefs.rah_minify', '1,2');
		register_callback(array($this, 'install'), 'plugin_lifecycle.rah_minify');
		register_callback(array($this, 'handler'), $event ? $event : 'textpattern');
	}

	/**
	 * Handles initialization.
	 *
	 * @param string $event Callback event
	 */

	public function handler($event = '')
	{
		global $rah_minify, $production_status;

		if ($event == 'textpattern' && $production_status == 'live')
		{
			return;
		}

		foreach (array('versions', 'files', 'closure', 'parse') as $name)
		{
			$this->$name = get_pref('rah_minify_'.$name, $this->$name);
		}

		if (trim($this->files))
		{
			$files = array();

			foreach (do_list($this->files, n) as $file)
			{
				$file = explode(' ', $file);
				$target = array_pop($file);
				$files[implode(' ', $file)] = $target;
			}

			$this->files = $files;
		}

		$this->files = array_merge(
			(array) $rah_minify,
			is_array($this->files) ? $this->files : array()
		);

		if (!$this->files)
		{
			return;
		}

		$this->collect_files();
	}

	/**
	 * Collects and updates modified files.
	 */

	protected function collect_files()
	{
		foreach ($this->files as $path => $to)
		{
			$to = $this->format_path($to);
			$path = $this->format_path($path);

			if (!file_exists($path) || !is_file($path) || !is_readable($path))
			{
				trace_add('[rah_minify: '.basename($path).' (source) can not be read]');
				continue;
			}

			$this->stack[$to][] = $path;

			if (in_array($to, $this->read))
			{
				continue;
			}

			if (file_exists($to))
			{
				if (!is_file($to) || !is_writable($to) || !is_readable($to))
				{
					trace_add('[rah_minify: '.basename($path).' -> '.basename($to).' (target) is not writeable]');
					continue;
				}

				$time = filemtime($to);

				if ($time >= filemtime($path))
				{
					continue;
				}
			}

			$this->read[] = $to;
		}

		foreach ($this->stack as $to => $paths)
		{	
			if (in_array($to, $this->read))
			{
				$this->source = $paths;
				$this->target = $to;
				$this->process();
				$this->create_version();
			}
			else
			{
				trace_add('[rah_minify: '.basename($to).' is up to date]');
			}
		}

		if ($this->read)
		{
			update_lastmod();
		}
	}

	/**
	 * Processes and minifies files.
	 */

	protected function process()
	{	
		foreach ((array) $this->source as $path)
		{
			$data[] = file_get_contents($path);
		}

		$this->input = implode(n, $data);
		$this->output = '';
		$this->parse();

		$method = 'compress_'.strtolower(pathinfo($path, PATHINFO_EXTENSION));

		if (method_exists($this, $method))
		{
			$this->$method();
		}

		$this->output = trim($this->output);
		
		if (file_put_contents($this->target, $this->output) === false)
		{
			trace_add('[rah_minify: writing to '.basename($this->target).' failed]');
			return;
		}

		touch($this->target);
		trace_add('[rah_minify: '.basename($this->target).' updated]');
	}

	/**
	 * Creates stamped versions.
	 */

	protected function create_version()
	{	
		if (!$this->versions)
		{
			return;
		}

		clearstatcache();

		$ext = pathinfo($this->target, PATHINFO_EXTENSION);
		$name = 'v.'.basename($this->target, '.'.$ext).'.'.filemtime($this->target).'.'.$ext;
		$to = dirname($this->target).'/'.$name;

		if (file_exists($to))
		{
			trace_add('[rah_minify: versioned '.$name.' already exists]');
			return;
		}

		if (file_put_contents($to, $this->output) === false)
		{
			trace_add('[rah_minify: writing to '.$name.' failed]');
			return;
		}

		trace_add('[rah_minify: created '.$name.']');
	}

	/**
	 * Parse Textpattern's tag markup in input.
	 */

	protected function parse()
	{
		if ($this->parse)
		{
			include_once txpath . '/publish.php';
			$this->input = parse($this->input);
		}
	}

	/**
	 * Compresses JavaScript files.
	 */

	protected function compress_js()
	{
		if ($this->closure)
		{
			$this->run_closure();
			return;
		}

		$this->output = rah_minify_JSMin::minify($this->input);
	}

	/**
	 * Compress CSS files.
	 */

	protected function compress_css()
	{
		$cssmin = new rah_minify_CSSmin(false);
		$this->output = $cssmin->run($this->input);
	}

	/**
	 * Processes and compresses LESS files.
	 */

	protected function compress_less()
	{
		if (!class_exists('lessc'))
		{
			trace_add('[rah_minify: lessc class is unavailable]');
			return;
		}

		$less = new lessc();

		try
		{
			$this->output = $less->parse($this->input);
		}
		catch (exception $e)
		{
			trace_add('[rah_minify: LESSPHP said "'.$e->getMessage().'"]');
			return;
		}

		$this->input = $this->output;
		$this->compress_css();
	}

	/**
	 * Closure compiler.
	 */

	protected function run_closure()
	{
		if (!function_exists('curl_init'))
		{
			trace_add('[rah_minify: cURL is not installed]');
			return;
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'http://closure-compiler.appspot.com/compile');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_FAILONERROR, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 90);
		curl_setopt($ch, CURLOPT_TIMEOUT, 90);
		curl_setopt($ch, CURLOPT_POSTFIELDS,
			'output_info=compiled_code'.
			'&output_format=text'.
			'&compilation_level=SIMPLE_OPTIMIZATIONS'.
			'&js_code='.urlencode($this->input)
		);

		$this->output = curl_exec($ch);
		$error = curl_errno($ch);
		curl_close($ch);

		if ($this->output === false || $error)
		{
			trace_add('[rah_minify: unable connect to Closure Compiler Service API ('.$error.')]');
		}
	}

	/**
	 * Formats a path.
	 *
	 * @param  string $path The path
	 * @return string Formatted path
	 */

	protected function format_path($path)
	{
		if (strpos($path, './') === 0)
		{
			return txpath . '/' . substr($path, 2);
		}

		if (strpos($path, '../') === 0)
		{
			return dirname(txpath) . '/' . substr($path, 3);
		}

		return $path;
	}
}