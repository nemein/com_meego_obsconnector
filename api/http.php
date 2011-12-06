<?php

class com_meego_obsconnector_HTTP
{
    private $config = null;
    private $prefix = null;
    private $protocol = 'http';

    private $auth = '';
    private $wget = false;

    protected $more_options = array();

    public function __construct($protocol = 'http', $prefix = '', $wget = false, $wget_options = '')
    {
        $this->prefix = $prefix;
        $this->protocol = $protocol;

        $this->setProxy();

        $this->wget = $wget;
        $this->wget_options = $wget_options;

        $this->config = parse_ini_file(dirname(__FILE__) . '/config.ini');
    }

    public function setAuthentication($user, $password)
    {
        $this->auth = $user.':'.$password.'@';
    }

    public function getAuthentication()
    {
        return $this->auth;
    }

    /**
     * Downloads a url via HTTP GET
     *
     * @param string partial or full URL to fetch
     * @param boolean if true, then the given (partial) URL will be translated
     *                to a valid OBS API URL
     *                if false, then the URL will be used as given
     *
     * @return string the contents of the file
     */
    public function get($url_to_fetch, $apiurl = true)
    {
        $retval = 0;

        if ($apiurl)
        {
            $url_to_fetch = $this->buildUrl($url_to_fetch);
        }

        if (! $this->wget)
        {
            $context = stream_context_create(array(
                'http' => array_merge($this->more_options, array(
                    'method' => 'GET',
                    'timeout' => 30
                )),
            ));
            $content = file_get_contents($url_to_fetch, false, $context);
        }
        else
        {
            ob_start();
            passthru('wget ' . $this->wget_options . ' ' . $url_to_fetch, $retval);
            $content = ob_get_contents();
            ob_end_clean();
        }


        if (   $content === false
            || $retval != 0)
        {
            // failed to fetch content
            throw new RuntimeException("Failed to fetch " . $url_to_fetch, 990);
        }

        $this->log('request : ' . $url_to_fetch);
        $this->log('response: ' . strlen($content) . ' bytes');
        return $content;
    }

    public function get_as_stream($url)
    {
        $retval = 0;

        if (! $this->wget)
        {
            $context = stream_context_create(array(
                'http' => array_merge($this->more_options, array('method' => 'GET', 'timeout' => 30)),
            ));
            $content = fopen($this->buildUrl($url), 'r', false, $context);
        }
        else
        {
            // when using wget we can not return a stream
            // wget is actually a workaround to some off php -> ssl conection problems
            $content = $this->get($this->buildUrl($url), false);
        }

        return $content;
    }

    public function post($url, array $parameters = array())
    {
        $context = stream_context_create(array(
            'http' => array_merge(
                $this->more_options,
                array(
                    'method' => 'POST',
                    'content' => '',
                    'timeout' => 30,
                )
            ),
        ));

        return file_get_contents($this->buildUrl($url), false, $context);
    }

    public function put($url, $body)
    {
        $context = stream_context_create(array(
            'http' => array_merge(
                $this->more_options,
                array(
                    'method' => 'PUT',
                    'content' => $body,
                    'timeout' => 30,
                )
            ),
        ));

        return file_get_contents($this->buildUrl($url), false, $context);
    }

    protected function buildUrl($url)
    {
        return $this->protocol . '://' . $this->auth . $this->prefix . $url;
    }

    protected function setProxy()
    {
        $proxy_var_name = '';

        if ($this->protocol == 'http') {
            $proxy_var_name = 'http_proxy';
        } elseif ($this->protocol == 'https') {
            if (isset($_SERVER['https_proxy'])) {
                $proxy_var_name = 'https_proxy';
            } elseif (isset($_SERVER['HTTPS_PROXY'])) {
                $proxy_var_name = 'HTTPS_PROXY';
            }
        }

        if ($proxy_var_name === '')
        {
            return;
        }

        if (! isset($_SERVER[$proxy_var_name]))
        {
            return;
        }

        $parsed_proxy_str = parse_url($_SERVER[$proxy_var_name]);

        if (  is_array($parsed_proxy_str)
            && $parsed_proxy_str['scheme'] == 'http'
            && isset($parsed_proxy_str['host'])
            && isset($parsed_proxy_str['port'])
        )
        {
            $this->more_options['proxy'] = 'tcp://'.$parsed_proxy_str['host'].':'.$parsed_proxy_str['port'];
            $this->more_options['request_fulluri'] = true;
        }
        else
        {
            trigger_error('"'.$proxy_var_name.'" environment variable is set to the wrong value. expecting http://host:port', E_USER_WARNING);
        }
    }

    /**
     * Downloads any file directly from an URL
     *
     * @param string URL to be downloaded
     * @param string location the file should be saved to
     *
     * @return string location the file it was saved to or null in case of error
     */
    public function download($url, $location)
    {
        $txt = $this->get($url, false);
        if ($txt)
        {
            $handle = fopen($location, 'wb+');

            if ($handle === false)
            {
                @fclose($handle);
                throw new RuntimeException('Unable to open file for writing: ' . $location);
            }
            else
            {
                $retval = fwrite($handle, $txt);
                if ($retval === false)
                {
                    @fclose($handle);
                    throw new RuntimeException('Unable to save to: ' . $location);
                }
            }
        }
        return $location;
    }

    /**
     * Logging to STDOUT
     *
     * @param string the string to log
     */
    public function log($message)
    {
        if (   array_key_exists('api_log', $this->config)
            && $this->config['api_log'] == 1)
        {
            $message = date('Y-m-d H:i:s') . ' API ' . $message . "\n";
            echo $message;
        }
    }
}
