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
     * An array of modified stacks.
     *
     * @var array
     */

    protected $modified = array();

    /**
     * Turns versioning on.
     *
     * @var bool
     */

    protected $versions = false;

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
        register_callback(array($this, 'compress_css'), 'rah_minify.compress', 'css');
        register_callback(array($this, 'compress_js'), 'rah_minify.compress', 'js');
        register_callback(array($this, 'compress_less'), 'rah_minify.compress', 'less');
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
        foreach (array('versions', 'files') as $name)
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
                $files[txpath.'/'.implode(' ', $file)] = txpath.'/'.$target;
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

            if ($this->modified)
            {
                update_lastmod();
            }
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
     * Gets modified files.
     *
     * Filtering is based on the file modification
     * stamp.
     */

    protected function getModified()
    {
        foreach ($this->files as $source => $target)
        {
            if (!file_exists($source) || !is_file($source) || !is_readable($source))
            {
                throw new Exception('Unable to read: '.$source);
            }

            $this->stack[$target][] = $source;

            if (in_array($target, $this->modified, true))
            {
                continue;
            }

            if (file_exists($target))
            {
                if (!is_file($target) || !is_writable($target))
                {
                    throw new Exception('Unable to write: ' . $target);
                }

                if (filemtime($target) < filemtime($source))
                {
                    $this->modified[] = $target;
                }
            }
            else
            {
                $this->modified[] = $to;
            }
        }
    }

    /**
     * Compresses updated files.
     */

    protected function collect_files()
    {
        $this->getModified();

        foreach ($this->stack as $target => $sources)
        {
            if (in_array($target, $this->modified))
            {
                $this->source = $sources;
                $this->target = $target;
                $this->process();
                $this->create_version();
            }
        }
    }

    /**
     * Processes and minifies files.
     */

    protected function process()
    {
        $data = array();

        foreach ((array) $this->source as $path)
        {
            $data[] = file_get_contents($path);
        }

        $this->input = implode(n, $data);
        $this->output = '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext && has_handler('rah_minify.compress', $ext))
        {
            $this->output = trim(callback_event('rah_minify.compress', $ext, 0, array(
                'data' => $this->input,
            )));
        }

        if (file_put_contents($this->target, $this->output) === false)
        {
            throw new Exception('Writing failed: '.$this->target);
        }

        touch($this->target);
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

        $name = implode('.', array(
            pathinfo($file, PATHINFO_FILENAME),
            filemtime($this->target),
            pathinfo($this->target, PATHINFO_EXTENSION),
        ));

        $to = dirname($this->target).'/'.$name;

        if (file_exists($to) === false && file_put_contents($to, $this->output) === false)
        {
            throw new Exception('Writing to '.$name.' failed');
        }
    }

    /**
     * Compresses JavaScript input.
     */

    protected function compress_js($event, $step, $data)
    {
        return JSMin::minify($data['data']);
    }

    /**
     * Compresses CSS input.
     */

    protected function compress_css($event, $step, $data)
    {
        $cssmin = new CSSmin(false);
        return $cssmin->run($data['data']);
    }

    /**
     * Processes and compresses LESS input.
     */

    protected function compress_less($event, $step, $data)
    {
        $less = new lessc();

        try
        {
            $out = $less->parse($data['data']);
        }
        catch (Exception $e)
        {
            throw new Exception('LESSPHP said: "'.$e->getMessage().'"');
        }

        $cssmin = new CSSmin(false);
        return $cssmin->run($out);
    }
}

/**
 * Gets the versioned file.
 *
 * @param  array $atts
 * @return string
 * @example
 * &lt;rah_minify name="../css/main.css" /&gt;
 */

function rah_minify($atts)
{
    extract(lAtts(array(
        'name' => '',
    ), $atts));

    $file = txpath.'/'.$name;

    if (!file_exists($file) || !is_file($file) || !is_readable($file))
    {
        trigger_error('Invalid name specified.');
        return '';
    }

    $file = implode('.', array(
        pathinfo($file, PATHINFO_FILENAME),
        filemtime($file),
        pathinfo($file, PATHINFO_EXTENSION),
    ));

    return txpspecialchars($file);
}

new rah_minify();