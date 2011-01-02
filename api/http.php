<?php

class com_meego_obsconnector_HTTP
{
    private $prefix = null;
    private $protocol = 'http';

    private $auth = '';

    protected $more_options = array();

    public function __construct($prefix, $protocol = 'http')
    {
        $this->prefix = $prefix;
        $this->protocol = $protocol;

        $this->setProxy();
    }

    public function setAuthentication($user, $password)
    {
        $this->auth = $user.':'.$password.'@';
    }

    public function get($url)
    {
        $context = stream_context_create(array(
            'http' => array_merge($this->more_options, array('method' => 'GET', 'timeout' => 30)),
        ));

        return file_get_contents($this->buildUrl($url), false, $context);
    }

    public function get_as_stream($url)
    {
        $context = stream_context_create(array(
            'http' => array_merge($this->more_options, array('method' => 'GET', 'timeout' => 30)),
        ));

        return fopen($this->buildUrl($url), 'r', false, $context);
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
}
