<?php

/**
 * Rah_minify plugin for Textpattern CMS.
 *
 * @author  Jukka Svahn
 * @license GNU GPLv2
 * @link    https://github.com/gocom/rah_minify
 *
 * Copyright (C) 2013 Jukka Svahn http://rahforum.biz
 * Licensed under GNU General Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

class rah_minify
{
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
	 */

	public function install()
	{
		$position = 250;

		foreach(
			array(
				'key'      => array('text_input', md5(uniqid(mt_rand(), true))),
				'files'    => array('pref_longtext_input', ''),
				'versions' => array('yesnoradio', 0),
				'parse'    => array('yesnoradio', 0),
			) as $name => $val
		)
		{
			$n =  'rah_minify_'.$name;

			if (get_pref($n, false) === false)
			{
				set_pref($n, $val[1], 'rah_minify', PREF_ADVANCED, $val[0], $position);
			}

			$position++;
		}
	}

	/**
	 * Uninstaller.
	 */

	public function uninstall()
	{
		safe_delete('txp_prefs', "name like 'rah\_minify\_%'");
	}

	/**
	 * Constructor.
	 */

	public function __construct()
	{
		add_privs('prefs.rah_minify', '1');
		register_callback(array($this, 'install'), 'plugin_lifecycle.rah_minify', 'installed');
		register_callback(array($this, 'uninstall'), 'plugin_lifecycle.rah_minify', 'deleted');
		register_callback(array($this, 'admin_handler'), 'admin_side', 'body_end');
		register_callback(array($this, 'page_handler'), 'textpattern');
		register_callback(array($this, 'endpoint'), 'textpattern');
	}

	/**
	 * Public callback hook endpoint.
	 */

	public function endpoint()
	{
		extract(gpsa(array(
			'rah_minify',
		)));

		if (get_pref('rah_minify_key') && get_pref('rah_minify_key') === $rah_minify)
		{
			try
			{
				$this->handler();
			}
			catch (Exception $e)
			{
				send_json_response(array(
						'success' => false,
						'error'   => $e->getMessage(),
				));

				die;
			}

			send_json_response(array('success' => true));
			die;
		}
	}

	/**
	 * Handles on-demand initialization.
	 */

	public function page_handler()
	{
		if (get_pref('production_status') != 'live')
		{
			try
			{
				$this->handler();
			}
			catch (Exception $e)
			{
				trace_add('[rah_minify: '.$e->getMessage().']');
			}
		}
	}

	/**
	 * Run compressor on admin-side pages.
	 */

	public function admin_handler()
	{
		try
		{
			$this->handler();
		}
		catch (Exception $e)
		{
			if (has_privs('prefs.rah_minify'))
			{
				echo announce('<strong>rah_minify:</strong> ' . $e->getMessage(), E_WARNING);
			}
		}
	}

	/**
	 * Handles initialization.
	 */

	public function handler()
	{
		foreach (array('versions', 'files', 'parse') as $name)
		{
			$this->$name = get_pref('rah_minify_'.$name, $this->$name);
		}

		if ($this->files)
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

		if (!$this->files)
		{
			return;
		}

		try
		{
			$this->collect_files();
		}
		catch (Exception $e)
		{
			callback_event('rah_minify.minify', 'fail', 0, array(
				'files' => $this->files,
				'error' => $e->getMessage(),
			));

			throw new Exception($e->getMessage());
		}

		callback_event('rah_minify.minify', 'done', 0, array(
			'files' => $this->files,
		));
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
				throw new Exception(basename($path).' (source) can not be read');
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
			throw new Exception('Writing to '.$name.' failed');
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
		$this->output = JSMin::minify($this->input);
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
			throw new Exception('lessc class is unavailable');
		}

		$less = new lessc();

		try
		{
			$this->output = $less->parse($this->input);
		}
		catch (exception $e)
		{
			throw new Exception('LESSPHP said: "'.$e->getMessage().'"');
		}

		$this->input = $this->output;
		$this->compress_css();
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

new rah_minify();