<?php

class com_meego_obsconnector_HTTP
{
    private $prefix = null;
    private $protocol = 'http';

    private $auth = '';

    protected $more_options = array();

    public function __construct($prefix = '', $protocol = 'http')
    {
        $this->prefix = $prefix;
        $this->protocol = $protocol;

        $this->setProxy();
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
        $context = stream_context_create(array(
            'http' => array_merge($this->more_options, array('method' => 'GET', 'timeout' => 30)),
        ));

        if ($apiurl)
        {
            $url_to_fetch = $this->buildUrl($url_to_fetch);
        }

        $content = @file_get_contents($url_to_fetch, false, $context);

        if ($content === false)
        {
            // failed to fetch content
            throw new RuntimeException("Failed to fetch " . $url_to_fetch, 990);
        }
        return $content;
    }

    public function get_as_stream($url)
    {
        $context = stream_context_create(array(
            'http' => array_merge($this->more_options, array('method' => 'GET', 'timeout' => 30)),
        ));
        return @fopen($this->buildUrl($url), 'r', false, $context);
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
        return $this->protocol.'://'.$this->auth.$this->prefix.$url;
    }


    protected function setProxy()
    {
        $proxy_var_name = null;

        if ($this->protocol == 'http') {
            $proxy_var_name = 'http_proxy';
        } elseif ($this->protocol == 'https') {
            if (isset($_SERVER['https_proxy'])) {
                $proxy_var_name = 'https_proxy';
            } elseif (isset($_SERVER['HTTPS_PROXY'])) {
                $proxy_var_name = 'HTTPS_PROXY';
            }
        }

        if (null === $proxy_var_name) {
            return;
        }

        if (!isset($_SERVER[$proxy_var_name])) {
            return;
        }

        $parsed_proxy_str = parse_url($_SERVER[$proxy_var_name]);

        if (is_array($parsed_proxy_str) and
            $parsed_proxy_str['scheme'] == 'http' and
            isset($parsed_proxy_str['host']) and
            isset($parsed_proxy_str['port'])
        ) {
            $this->more_options['proxy'] = 'tcp://'.$parsed_proxy_str['host'].':'.$parsed_proxy_str['port'];
            $this->more_options['request_fulluri'] = true;
        } else {
            trigger_error('"'.$proxy_var_name.'" environment variable is set to the wrong value. expecting http://host:port', E_USER_WARNING);
        }
    }

    /**
     * Downloads any file directly from an URL
     *
     * @param string URL to  be downloaded
     * @param string location the file should be saved to
     *
     * @return string location the file it was saved to or null in case of error
     */
    public function download($url, $location)
    {
        $handle = fopen($location, 'c');

        if ($handle === false)
        {
            throw new RuntimeException('Unable to open file for writing: ' . $location);
            return null;
        }
        else
        {
            $txt = $this->get($url, false);
            if ($txt)
            {
                $retval = @fwrite($handle, $txt);
                if ($retval === false)
                {
                    throw new RuntimeException('Unable to save to: ' . $location);
                }
            }
            @fclose($handle);
            return $location;
        }
    }
}
