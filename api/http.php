<?php

class com_meego_obsconnector_HTTP
{
    private $prefix = null;
    private $protocol = 'http';

    private $auth = '';

    private $more_options = array();

    public function __construct($prefix, $protocol = 'http')
    {
        $this->prefix = $prefix;
        $this->protocol = $protocol;
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
}
