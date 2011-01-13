<?php

// FIXME: use some autoloader
require __DIR__.'/http.php';

class com_meego_obsconnector_API
{
    private $http = null;

    public function __construct($login, $password, $host = 'api.pub.meego.com')
    {
        $this->http = new com_meego_obsconnector_HTTP($host, 'https');
        $this->http->setAuthentication($login, $password);
    }

    public function getProjects()
    {
        $txt = $this->getPublished();
        return $this->parseDirectoryXML($txt);
    }

    public function getProjectMeta($name)
    {
        $txt = $this->http->get('/source/'.$name.'/_meta');
        return $this->parseProjectXML($txt);
    }

    public function getSourcePackages($project)
    {
        $txt = $this->http->get('/source/'.$project);
        return $this->parseDirectoryXML($txt);
    }

    public function getPackages($project, $repository, $architecture)
    {
        $txt = $this->http->get('/build/'.$project.'/'.$repository.'/'.$architecture);
        return $this->parseDirectoryXML($txt);
    }

    public function getPackageMeta($project, $package)
    {
        $txt = $this->http->get('/source/'.$project.'/'.$package.'/_meta');
        return $this->parsePackageXML($txt);
    }

    public function getPackageSourceFiles($project, $package)
    {
        $txt = $this->http->get('/source/'.$project.'/'.$package);
        return $this->parseDirectoryXML($txt);
    }

    public function putPackageSourceFile($project, $package, $filename, $stream_or_string)
    {
        if (is_resource($stream_or_string)) {
            $stream_or_string = stream_get_contents($stream_get_contents);
            fclose($stream_or_string);
        }

        $txt = $this->http->put('/source/'.$project.'/'.$package, $stream_or_string);
        return $this->parseStatus($txt);
    }

    public function getPackageSourceFile($project, $package, $name)
    {
        return $this->http->get_as_stream('/source/'.$project.'/'.$package.'/'.$name);
    }

    public function getPackageSpec($project, $package)
    {
        $spec_name = $package.'.spec';

        // $files = $api->getPackageSourceFiles($projects[0], $packages[0]);
        // if (!in_array($spec_name, $files))
        //     return null;

        return $this->getPackageSourceFile($project, $package, $spec_name);
    }

    public function getRepositories($project)
    {
        $txt = $this->getPublished($project);
        return $this->parseDirectoryXML($txt);
    }

    public function getArchitectures($project, $repository)
    {
        $txt = $this->http->get('/build/'.$project.'/'.$repository);
        return $this->parseDirectoryXML($txt);
    }

    public function getBinaries($project, $repository, $architecture)
    {
        return $this->getPublished($project, $repository, $architecture);
    }


    protected function parseDirectoryXML($xml)
    {
        $_xml = simplexml_load_string($xml);

        $retval = array();
        foreach ($_xml->entry as $entry) {
            $retval[] = strval($entry['name']);
        }

        return $retval;
    }

    protected function parsePackageXML($xml)
    {
        $_xml = simplexml_load_string($xml);

        $retval = array('owners' => array());

        foreach ($_xml->person as $person) {
            if ($person['role'] == 'maintainer') {
                $retval['owners'][] = strval($person['userid']);
            }
        }

        return $retval;
    }

    protected function parseProjectXML($xml)
    {
        $_xml = simplexml_load_string($xml);

        $retval = array(
            'name' => '',
            'title' => '',
            'description' => '',
            'maintainer' => '',
            'bugowner' => '',
            'repositories' => array()
        );

        //var_dump($_xml);

        $retval['name'] = strval($_xml['name']);
        $retval['title'] = strval($_xml->title);
        $retval['description'] = strval($_xml->description);
        foreach ($_xml->person as $person) {
            if ($person['role'] == 'maintainer') {
                $retval['maintainer'] = strval($person['userid']);
                $retval['bugowner'] = strval($person['userid']);
            }
        }
        foreach ($_xml->repository as $repository) {
            $retval['repositories'][strval($repository['name'])]['path'] = strval($repository->path['project']);
            foreach ($repository->arch as $arch)
            {
                // @todo: this could be set to show if the arch is enabled to disabled, or public, non-public
                $retval['repositories'][strval($repository['name'])]['architectures'][strval($arch)] = 1;
            }
        }
        return $retval;
    }

    protected function parseStatus($xml)
    {
        $_xml = simplexml_load_string($xml);

        return array(
            'summary' => strval($_xml->summary),
            'details' => strval($_xml->details),
            'data'    => strval($_xml->data),
        );
    }

    protected function getPublished($project = null, $repository = null, $architecture = null)
    {
        $url = '/published';

        if (null !== $project) {
            $url .= '/'.$project;

            if (null !== $repository) {
                $url .= '/'.$repository;

                if (null !== $architecture) {
                    $url .= '/'.$architecture;
                }
            }
        }

        return $this->http->get($url);
    }
}