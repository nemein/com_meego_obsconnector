<?php

// FIXME: use some autoloader
require __DIR__ . '/http.php';
require __DIR__ . '/../parser/Package.php';

class com_meego_obsconnector_API
{
    private $config = null;
    private $host = null;
    private $download_repo_prefix = '';

    public $http = null;

    public function __construct($protocol = 'https', $host = 'api.pub.meego.com', $login = null, $password = null, $wget = false, $wget_options = '')
    {
        $this->host = $host;

        $prefix = '';

        if (!  ($login
            && $password))
        {
            // no login and password given, so we use the public prefix for URLs
            $prefix = '/public';
        }

        $this->http = new com_meego_obsconnector_HTTP($protocol, $host . $prefix, $wget, $wget_options);

        if (   $login
            && $password)
        {
            $this->login = $login;
            $this->password = $password;
            $this->http->setAuthentication($login, $password);
        }

        $this->config = parse_ini_file(dirname(__FILE__) . '/config.ini');
    }

    public function getPublishedProjects()
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

    public function getPackageMeta($project, $package)
    {
        $txt = $this->http->get('/source/' . $project . '/' . $package . '/_meta');
        return $this->parsePackageXML($txt);
    }

    public function getPackageSourceFiles($project, $package)
    {
        $txt = $this->http->get('/source/' . $project . '/' . $package);
        return $this->parseDirectoryXML($txt);
    }

    public function putPackageSourceFile($project, $package, $filename, $stream_or_string)
    {
        if (is_resource($stream_or_string))
        {
            $stream_or_string = stream_get_contents($stream_get_contents);
            fclose($stream_or_string);
        }

        $txt = $this->http->put('/source/' . $project . '/' . $package, $stream_or_string);
        return $this->parseStatus($txt);
    }

    public function getPackageSourceFile($project, $package, $name)
    {
        return $this->http->get_as_stream('/source/' . $project . '/' . $package . '/' . $name);
    }

    public function getPackageSpec($project, $package)
    {
        $spec_name = $package . '.spec';
        return $this->getPackageSourceFile($project, $package, $spec_name);
    }

    public function getPublishedRepositories($project)
    {
        $txt = $this->getPublished($project);
        return $this->parseDirectoryXML($txt);
    }

    /**
     * Retrives a list of _build_ architectures avaialable in a certain repository
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     *
     * @return array of architectures
     */
    public function getBuildArchitectures($project, $repository)
    {
        $txt = $this->http->get('/build/' . $project . '/' . $repository);
        return $this->parseDirectoryXML($txt);
    }

    /**
     * Retrives a list of _published_ architectures avaialable in a certain repository
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     *
     * @return array of architectures
     */
    public function getPublishedArchitectures($project, $repository)
    {
        // @fix: this actually lists directories, not architectures
        $txt = $this->http->get('/published/' . $project . '/' . $repository);
        return $this->parseDirectoryXML($txt);
    }

    /**
     * Retrieves a list of __built__ package names (note: names only, without version numbers)
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     * @param string architecture e.g. i586 or armv7l
     *
     * @return array of packages
     */
    public function getBuiltPackages($project, $repository, $architecture)
    {
        $txt = $this->http->get('/build/' . $project . '/' . $repository . '/' . $architecture);
        return $this->parseDirectoryXML($txt);
    }

    /**
     * Retrieves a list of __built__ package names (note: names only, without version numbers)
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     * @param string architecture e.g. i586 or armv7l
     *
     * @return array of packages
     */
    public function getPublishedPackages($project, $repository, $architecture)
    {
        $txt = $this->http->get('/published/' . $project . '/' . $repository . '/' . $architecture);
        return $this->parseDirectoryXML($txt);
    }

    /**
     * Retrieves a list of __built__ binaries for a concrete package
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     * @param string architecture e.g. i586 or armv7l
     * @param string package name of the package, e.g. gtk-xfce-engine (without version number)
     *
     * @return array of binaries produced for that package
     */
    public function getBuiltBinaryList($project = null, $repository = null, $architecture = null, $package = null)
    {
        $txt = $this->http->get('/build/' . $project . '/' . $repository . '/' . $architecture . '/' . $package);
        return $this->parseBinaryXML($txt);
    }

    /**
     * Retrieves a list of __published__ binaries within a certain project:repo:architecture
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     * @param string architecture e.g. i586 or armv7l
     *
     * @return array of binaries produced for that package
     */
    public function getPublishedBinaries($project, $repository, $architecture)
    {
        $txt = $this->getPublished($project, $repository, $architecture);
        return $this->parseDirectoryXML($txt);
    }

    /**
     * Retrieves a list of __published__ files
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     * @param string architecture e.g. i586 or armv7l
     *
     * @return string of published files
     */
    protected function getPublished($project = null, $repository = null, $architecture = null)
    {
        $url = '/published';

        if (null !== $project) {
            $url .= '/' . $project;

            if (null !== $repository) {
                $url .= '/' . $repository;

                if (null !== $architecture) {
                    $url .= '/' . $architecture;
                }
            }
        }
        return $this->http->get($url);
    }

    /**
     * Retrieves extended information about a binary
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     * @param string architecture e.g. i586 or armv7l
     * @param string package name of the package, e.g. gtk-xfce-engine (without version number)
     * @param string filename full name of the file (with version and release numbers)
     *
     * @return object Package object with info filled in
     */
    public function getPackageWithFullInformation($project = null, $repository = null, $architecture = null, $package = null, $filename = null)
    {
        $txt = $this->http->get('/build/' . $project . '/' . $repository . '/' . $architecture . '/' . $package . '/' . $filename . '?view=fileinfo_ext');
        $package = $this->parseExtendedInfoXML($txt);

        return $package;
    }

    /**
     * Parses an XML input
     * @param $xml xml string
     *
     * @return array
     */
    protected function parseDirectoryXML($xml)
    {
        $_xml = simplexml_load_string($xml);

        $retval = array();
        if (count($_xml->entry))
        {
            foreach ($_xml->entry as $entry) {
                $retval[] = strval($entry['name']);
            }
        }
        return $retval;
    }

    /**
     * Parses an XML input
     * @param $xml xml string
     *
     * @return array
     */
    protected function parseBinaryXML($xml)
    {
        $_xml = simplexml_load_string($xml);
        $retval = array();

        if (count($_xml->binary))
        {
            foreach ($_xml->binary as $binary) {
                $filename = strval($binary['filename']);
                $retval[] = $filename;
            }
        }
        return $retval;
    }

    /**
     * Parses an XML input
     * @param $xml xml string
     *
     * @return object Package object with info filled in
     */
    protected function parseExtendedInfoXML($xml)
    {
        $_xml = simplexml_load_string($xml);

        $retval = new Package();

        // determine the file extension
        // from that we know that all required package will have to have the same extension
        $extension = preg_replace('/.*\.(.*)/', '\1', strval($_xml['filename']));

        $retval->name = strval($_xml->name);
        $retval->title = ucfirst($retval->name);
        $retval->filename = $_xml['filename'];
        $retval->version = strval($_xml->version);

        // not part of mgdschema
        $retval->release = strval($_xml->release);

        // not part of mgdschema
        $retval->arch = strval($_xml->arch);

        // not part of mgdschema
        $retval->size = strval($_xml->size);

        $retval->summary = strval($_xml->summary);
        $retval->description = strval($_xml->description);

        if (isset($_xml->requires_ext))
        {
            foreach ($_xml->requires_ext as $required)
            {
                if (   $required->providedby['name'] == $retval->name
                    && $required->providedby['version'] == $retval->version
                    && $required->providedby['release'] == $retval->release
                    && $required->providedby['arch'] == $retval->arch)
                {
                    // the package requires itself; no way, but it still happens (see cdparanoia-libs for example)
                    continue;
                }
                if ($required->providedby['name'])
                {
                    $_name = strval($required->providedby['name']);
                    $_version = strval($required->providedby['version']);
                    $_release = strval($required->providedby['release']);
                    $_project = str_replace('home:', 'home:/', strval($required->providedby['project']));
                    $_repository = strval($required->providedby['repository']);
                    $_arch = strval($required->providedby['arch']);

                    // the constraint is always = if we use the "fileinfo_ext" API
                    $_constraint = '=';

                    $dependency = new Dependency($_name, $_constraint, $_version);
                    $dependency->title = ucfirst($dependency->name);

                    // educated guess about the exact filename of the dependency
                    $_filename = $_name . '-' . $_version . '-' . $_release . '.' . $_arch . '.' . $extension;

                    $dependency->filename = $_filename;
                    $dependency->release = $_release;
                    $dependency->project = strval($required->providedby['project']);
                    $dependency->downloadurl = $this->download_repo_prefix . '/' . $_project . '/' . $_repository . '/' . $_arch . '/' . $_filename;
                    $dependency->repository = $_repository;
                    $dependency->arch = $_arch;

                    $retval->depends[$_name] = $dependency;
                }
            }
        }

        // @todo fill in 'provides' for sake of completeness

        // @fix: it seems that the OBS API does not provide info about conflicts and obsoletes

        return $retval;
    }

    /**
     * Parses an XML input of packages
     * @param $xml xml string
     *
     * @return array
     */
    protected function parsePackageXML($xml)
    {
        $_xml = simplexml_load_string($xml);

        $retval = array('owners' => array());

        foreach ($_xml->person as $person)
        {
            if ($person['role'] == 'maintainer')
            {
                $retval['owners'][] = strval($person['userid']);
            }
        }

        return $retval;
    }

    /**
     * Parses an XML input of projects
     * @param $xml xml string
     *
     * @return array
     */
    protected function parseProjectXML($xml)
    {
        $_xml = simplexml_load_string($xml);

        $retval = array (
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

        // check if the project is an official one
        $official_project = false;

        if (isset($this->config['official_project']))
        {
            foreach ($this->config['official_project'] as $pattern)
            {
                // echo "project name: " . $retval['name'] . ' vs ' . $pattern . "\n";
                if (preg_match('/' . $pattern . '/', $retval['name']))
                {
                    $official_project = true;
                }
            }
        }

        foreach ($_xml->person as $person)
        {
            if ($person['role'] == 'maintainer')
            {
                $retval['maintainer'] = strval($person['userid']);
                $retval['bugowner'] = strval($person['userid']);
            }
        }
        foreach ($_xml->repository as $repository)
        {
            $retval['repositories'][strval($repository['name'])]['path'] = strval($repository->path['project']);

            // set blank defaults
            $retval['repositories'][strval($repository['name'])]['os'] = '';
            $retval['repositories'][strval($repository['name'])]['osversion'] = '';
            $retval['repositories'][strval($repository['name'])]['osgroup'] = '';
            $retval['repositories'][strval($repository['name'])]['osux'] = '';

            if ($official_project)
            {
                // parse the project property of path to determine OS, version, group and UX data
                $info = explode('_', $repository['name']);
            }
            else
            {
                if (strval($repository->path['repository']) == 'standard')
                {
                    // parse the project property of path to determine OS, version, group and UX data
                    $info = explode(':', $repository->path['project']);
                }
                else
                {
                    // Might not work with all kinds of exotic repository names...
                    // parse the repository property of path to determine OS, version, group and UX data
                    // @todo: check back the whole tree of the anchestor repositories to determine uses..
                    $info = explode('_', $repository->path['repository']);
                }
            }

            if (is_array($info))
            {
                if (isset($info[0]))
                {
                    $retval['repositories'][strval($repository['name'])]['os'] = mb_strtolower($info[0], 'UTF-8');
                }
                if (isset($info[1]))
                {
                    $retval['repositories'][strval($repository['name'])]['osversion'] = mb_strtolower($info[1], 'UTF-8');
                }
                if (isset($info[2]))
                {
                    $retval['repositories'][strval($repository['name'])]['osgroup'] = mb_strtolower($info[2], 'UTF-8');
                }
                else
                {
                    if (strval($repository->path['repository']) == 'standard')
                    {
                        $retval['repositories'][strval($repository['name'])]['osgroup'] = 'standard';
                    }
                }
                if (isset($info[3]))
                {
                    // @todo: UX might be too MeeGo specific, may not work with other OBSes
                    $retval['repositories'][strval($repository['name'])]['osux'] = mb_strtolower($info[3], 'UTF-8');
                }
            }

            foreach ($repository->arch as $arch)
            {
                // @todo: this could be set to show if the arch is enabled to disabled, or public, non-public
                $retval['repositories'][strval($repository['name'])]['architectures'][strval($arch)] = 1;
            }
        }

        return $retval;
    }

    /**
     * Parses an XML input statuses
     * @param $xml xml string
     *
     * @return array
     */
    protected function parseStatus($xml)
    {
        $_xml = simplexml_load_string($xml);

        return array
        (
            'summary' => strval($_xml->summary),
            'details' => strval($_xml->details),
            'data'    => strval($_xml->data),
        );
    }

    /**
     * Returns a relative link to the install file
     * that lists repositories of packages that are runtime dependencies
     * of the package in question
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     * @param string architecture e.g. i586 or armv7l
     * @param string full package name, e.g. gtk-xfce-engine-2.6.0-1.1.rpm (see getPublished)
     *
     * @return string URL of the install file
     */
    public function getRelativeInstallPath($project = null, $repository = null, $architecture = null, $fullpackagename = null)
    {
        return '/published' . '/' . $project . '/' . $repository . '/' . $architecture . '/' . $fullpackagename . '?view=ymp';
    }

    /**
     * Returns a link to the install file
     * that lists repositories of packages that are runtime dependencies
     * of the package in question
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     * @param string architecture e.g. i586 or armv7l
     * @param string full package name, e.g. gtk-xfce-engine-2.6.0-1.1.rpm (see getPublished)
     *
     * @return string URL of the install file
     */
    public function getInstallFileURL($project = null, $repository = null, $architecture = null, $fullpackagename = null)
    {
        return 'https://' . $this->host . $this->getRelativeInstallPath($project, $repository, $architecture, $fullpackagename);
    }

    /**
     * Returns a link to the install file with authentication details
     * that lists repositories of packages that are runtime dependencies
     * of the package in question
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     * @param string architecture e.g. i586 or armv7l
     * @param string full package name, e.g. gtk-xfce-engine-2.6.0-1.1.rpm (see getPublished)
     *
     * @return string URL of the install file
     */
    public function getAuthInstallFileURL($project = null, $repository = null, $architecture = null, $fullpackagename = null)
    {
        return 'https://' . $this->http->getAuthentication() . $this->host . '/published' . '/' . $project . '/' . $repository . '/' . $architecture . '/' . $fullpackagename . '?view=ymp';
    }

    /**
     * Returns a link for package download
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     * @param string architecture e.g. i586 or armv7l
     * @param string package name, e.g. gtk-xfce-engine
     * @param string full name of the file, e.g. gtk-xfce-engine-2.6.0-1.1.rpm (see getPublished)
     *
     * @return string URL of the package
     */
    public function getDownloadURL($project = null, $repository = null, $architecture = null, $package = null, $fullpackagename = null)
    {
        return 'https://' . $this->host . '/build' . '/' . $project . '/' . $repository . '/' . $architecture . '/' . $package . '/' . $fullpackagename;
    }

    /**
     * Returns a link for package download with authentication credentials
     *
     * @param string project person's home project, e.g. home:ferenc
     * @param string repository name of the repo, e.g. meego_1.1_extras_handset
     * @param string architecture e.g. i586 or armv7l
     * @param string package name, e.g. gtk-xfce-engine
     * @param string full name of the file, e.g. gtk-xfce-engine-2.6.0-1.1.rpm (see getPublished)
     *
     * @return string URL of the package
     */
    public function getAuthDownloadURL($project = null, $repository = null, $architecture = null, $package = null, $fullpackagename = null)
    {
        return 'https://' . $this->http->getAuthentication() . $this->host . '/build' . '/' . $project . '/' . $repository . '/' . $architecture . '/' . $package . '/' . $fullpackagename;
    }

}
