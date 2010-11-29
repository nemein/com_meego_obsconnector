<?php

namespace com\meego\obsconnector;

// FIXME: use some autoloader
require __DIR__.'/http.php';

class API
{
    private $http = null;

    public function __construct()
    {
        $this->http = new HTTP('api.pub.meego.com', 'https');
        $this->http->setAuthentication('indeyets', 'TFkRjenhKh');
    }

    public function getProjects()
    {
        $txt = $this->getPublished();
        return $this->parseDirectoryXML($txt);
    }

    public function getProjectMeta($name)
    {
        return $this->http->get('/source/'.$name.'/_meta');
    }

    public function getPackages($project)
    {
        $txt = $this->http->get('/source/'.$project);
        return $this->parseDirectoryXML($txt);
    }

    public function getPackageMeta($project, $package)
    {
        $txt = $this->http->get('/source/'.$project.'/'.$package.'/_meta');
        return $txt;
    }

    public function getPackageSourceFiles($project, $package)
    {
        $txt = $this->http->get('/source/'.$project.'/'.$package);
        return $this->parseDirectoryXML($txt);
    }

    public function getPackageSourceFile($project, $package, $name)
    {
        $txt = $this->http->get('/source/'.$project.'/'.$package.'/'.$name);
        return $txt;
    }


    public function getRepositories($project)
    {
        return $this->getPublished($project);
    }

    public function getArchitectures($project, $repository)
    {
        return $this->getPublished($project, $repository);
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
