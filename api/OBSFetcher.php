<?php
require __DIR__.'/api.php';
require __DIR__ . '/Importer.php';
require __DIR__.'/../parser/RpmXray.php';
//require __DIR__.'/../parser/RpmSpecParser.php';

/**
 * @todo: docs
 */
class OBSFetcher extends Importer
{
    private $project_name = null;
    private $package_counter = 0;
    private $build_counter = 0;

    // @todo: move to this standard component configuration file
    private $download_repo_protocol = 'http';
    private $download_repo_host = 'repo.pub.meego.com';

    /**
     * @todo: docs
     */
    public function __construct()
    {
        if ( ! file_exists(dirname(__FILE__) . '/config.ini') )
        {
            // for now we bail out if there is no config.ini with login and password details
            throw new RuntimeException('Please create config.ini file with "login", "password" and, optionally, "host" keys');
        }
        else
        {
            $this->config = parse_ini_file(dirname(__FILE__) . '/config.ini');

            if (isset($this->config['host']))
            {
                $this->api = new com_meego_obsconnector_API($this->config['login'], $this->config['password'], $this->config['host']);
            }
            else
            {
                $this->api = new com_meego_obsconnector_API($this->config['login'], $this->config['password']);
            }
        }
    }

    /**
     * If no argument (ie. project name) is given when running this script
     * then we go through all available projects
     */
    public function scan_all_projects($cleanonly = false)
    {
        $i = 0;

        // get all published projects
        try
        {
            $projects = $this->api->getPublishedProjects();
        }
        catch (RuntimeException $e)
        {
            echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
        }

        // iterate through each project to get all its repositories and
        // eventually all available packages within the repositories
        foreach ($projects as $project_name)
        {
            echo "\n#" . ++$i . ' Project: ' . $project_name . "\n";

            if ($cleanonly)
            {
                echo "Requested clean up only\n";
            }

            echo "---------------------------------------------------------\n";

            $this->go($project_name, null, $cleanonly);
        }
    }

    /**
     * Implemented by child
     *
     * Goes through a project
     *
     * @param string OBS project name, e.g. home:feri
     * @param string optional; specify a concrete package to be imported
     * @param boolean optional; if true then only cleanup will be performed on the local database
     *                otherwise full import happens
     *
     */
    public function go($project_name = null, $specific_package_name = null, $cleanonly = false)
    {
        if (is_null($project_name))
        {
            // no project given for go
            throw new RuntimeException('Please specify a valid project');
        }

        // check if the project is already recorded in our database
        $project = $this->getProject($project_name);

        try
        {
            // get meta info about a project. this info consists of the following:
            // project name, title, description,
            // people involved,
            // repositories (published and non-published)
            $project_meta = $this->api->getProjectMeta($project_name);
        }
        catch (RuntimeException $e)
        {
            if ($e->getCode() == 990)
            {
                // failed to fetch project meta, bail out
                echo "Failed to fetch meta information of project: $project_name\n";
                echo "The project may not exist anymore\n";

                if ($project->guid)
                {
                    // the project is in our database; so let's delete it
                    // @todo: $this->deleteProject($project);
                }

                exit(1);
            }
        }

        // set properties
        $project->name = trim($project_meta['name']);
        $project->title = trim($project_meta['title']);
        $project->description = trim($project_meta['description']);

        if (! $cleanonly)
        {
            if ($project->guid)
            {
                echo 'Update project record: ' . $project->name;
                $project->update();
            }
            else
            {
                echo 'Create project record: ' . $project->name;
                $project->create();
            }
            echo ' (' . $project->title . ', ' . $project->description . ")\n";
        }

        if ($project->id)
        {
            // get all repositories this project has published
            echo "\nRepositories in $project->name:\n";

            try
            {
                $repositories = $this->api->getPublishedRepositories($project->name);
            }
            catch (RuntimeException $e)
            {
                echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
            }

            // iterate through each and every published repository
            // and dig out the packages
            foreach ($repositories as $repo_name)
            {
                echo "\n -> " . $repo_name . "\n";

                $repo->os = $project_meta['repositories'][$repo_name]['os'];

                if (   is_array($this->config['os_map'])
                    && array_search($repo->os, $this->config['os_map']) === false)
                {
                    echo "    skipped due to wrong OS: " . $repo->os . "\n";
                    continue;
                }

                try
                {
                    $architectures = $this->api->getBuildArchitectures($project->name, $repo_name);
                }
                catch (RuntimeException $e)
                {
                    echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
                }

                if ( ! count($architectures) )
                {
                    continue;
                }

                // get all available architectures within this repository
                foreach ($architectures as $arch_name)
                {
                    echo "\n  -> " . $arch_name . "\n";

                    // get a com_meego_repository object
                    $repo = $this->getRepository($repo_name, $arch_name, $project->id);

                    // fill in properties of the repo object
                    $repo->name = $repo_name;
                    $repo->project = $project->id;

                    $repo->arch = $arch_name;
                    $repo->title = $repo_name . ' (for ' . $arch_name . ')';

                    $repo->os = $project_meta['repositories'][$repo_name]['os'];

                    // @todo: we could supply an URL for the OS if available (see last parameter)
                    $repo->osversion = $this->getOS($repo->os, $project_meta['repositories'][$repo_name]['osversion'], $repo->arch, '');

                    $repo->osgroup = $project_meta['repositories'][$repo_name]['osgroup'];
                    $repo->osux = $this->getUX($project_meta['repositories'][$repo_name]['osux'], '');

                    if (! $cleanonly)
                    {
                        if ($repo->guid)
                        {
                            echo '     update: ';
                            $repo->update();
                        }
                        else
                        {
                            echo '     create: ';
                            $repo->create();
                        }
                        echo $repo->name . ' (id: ' . $repo->id . '; OS: ' . $repo->os . ', OS id: ' . $repo->osversion . ', ' . $repo->osgroup . ', UX id: ' . $repo->osux . ', OS arch: ' . $repo->arch . ")\n";
                    }

                    $fulllist = array();

                    try
                    {
                        $builtpackages = $this->api->getBuiltPackages($project->name, $repo_name, $arch_name);
                    }
                    catch (RuntimeException $e)
                    {
                        echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
                    }

                    if ( ! count($builtpackages))
                    {
                        continue;
                    }

                    foreach ($builtpackages as $package_name)
                    {
                        // check if a specific package should be imported
                        if (   ! is_null($specific_package_name)
                            && $package_name != $specific_package_name)
                        {
                            // this is a no match, so go to next package
                            continue;
                        }

                        echo "\n     -> package #" . ++$this->package_counter . ': ' . $package_name . "\n";

                        try
                        {
                            $newlist = $this->api->getBuiltBinaryList($project->name, $repo_name, $arch_name, $package_name);
                        }
                        catch (RuntimeException $e)
                        {
                            echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
                        }

                        // this list contains all binaries built for all packages in that repo
                        // we will use it to do a clean up operation once this loop finishes
                        $fulllist = array_merge($fulllist, $newlist);

                        if ($cleanonly)
                        {
                            // only cleanup is requested so we can go to the next package
                            continue;
                        }

                        foreach($newlist as $file_name)
                        {
                            echo "\n        -> binary #" . ++$this->build_counter . ': ' . $file_name . "\n";

                            // the getBuiltBinaryList will also return binary names that are
                            // built for different architecture
                            // we should skip these binaries except the noarch ones
                            $chunks = explode('.', $file_name);

                            $bin_arch = '';

                            if (count($chunks) >= 2)
                            {
                                $bin_arch = $chunks[count($chunks) - 2];

                                if ($bin_arch == 'armv7l')
                                {
                                    // fix the inconsistency between the published repo and the API
                                    $bin_arch = 'armv7el';
                                }

                                if (   $bin_arch != 'src'
                                    && $bin_arch != $arch_name)
                                {
                                    if ($bin_arch != 'noarch')
                                    {
                                        echo '        skip arch: ' . $bin_arch . ' (' . $file_name . ")\n";
                                        continue;
                                    }
                                }
                            }

                            // creates or updates a package in the database
                            $package = $this->createPackage($project->name, $repo->id, $repo_name, $arch_name, $package_name, $file_name, $bin_arch);

                            if ( ! $package )
                            {
                                // we got no package object, usually because we had to delete
                                // an existing one from database, so go to next binary package
                                continue;
                            }

                            try
                            {
                                $screenshot_names = array_filter
                                (
                                    $this->api->getPackageSourceFiles($project->name, $package_name),

                                    function($name)
                                    {
                                        $_marker = 'screenshot.png';
                                        return strpos($name, $_marker) === (strlen($name) - strlen($_marker));
                                    }
                                );
                            }
                            catch (RuntimeException $e)
                            {
                                echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
                            }

                            foreach ($screenshot_names as $name)
                            {
                                try
                                {
                                    $fp = $this->api->getPackageSourceFile($project->name, $package_name, $name);
                                }
                                catch (RuntimeException $e)
                                {
                                    echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
                                }

                                if ($fp)
                                {
                                    $attachment = $package->create_attachment($name, $name, "image/png");

                                    if ($attachment)
                                    {
                                        $blob = new midgard_blob($attachment);

                                        $handler = $blob->get_handler('wb');

                                        fwrite($handler, stream_get_contents($fp));
                                        fclose($fp);

                                        fclose($handler);
                                        $attachment->update();
                                    }
                                }
                            }

                            //
                            // @todo: get the icon from the package if exists there
                            //
                            try
                            {
                                // check the filelist of the package that can be obtained via an OBS API call
                                // download the package locally if it has a promising icon in it
                                // $rpm = $this->getRpm($project->name, $repo_name, $arch_name, $package_name);
                            }
                            catch (RuntimeException $e)
                            {
                                echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
                            }
                        }
                    }

                    // now cleanup all the packages from our database
                    // that are not part of this OBS repository
                    $this->cleanPackages($repo, $fulllist);
                }
            }
        }
    }

    /**
     * Creates or updates a package in the database
     *
     * @return object package object
     */
    public function createPackage($project_name = null, $repo_id = null, $repo_name = null, $arch_name = null, $package_name = null, $file_name = null, $repo_arch_name = null)
    {
        if (   $project_name
            && $repo_name
            && $arch_name
            && $package_name
            && $file_name)
        {
            // get fill package info via OBS API
            try
            {
                $extinfo = $this->api->getPackageWithFullInformation($project_name, $repo_name, $arch_name, $package_name, $file_name);
            }
            catch (RuntimeException $e)
            {
                echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
            }
        }
        else
        {
            throw new RuntimeException('Not enough parameters to gather full package information');
            return;
        }

        // get a com_meego_package instance
        $package = $this->getPackageByFileName($file_name, $repo_id);

        if (   $package
            && $repo_id
            && $extinfo)
        {
            if ( ! $package->guid )
            {
                $package->repository = $repo_id;
                $package->filename = $extinfo->filename;
            }

            $package->name = $extinfo->name;
            $package->title = $extinfo->title;
            $package->version = $extinfo->version;
            $package->summary = $extinfo->summary;
            $package->description = $extinfo->description;

            // if the package is a source package then the downloadurl is slightly different
            // also change the title a bit
            if ($repo_arch_name == 'src')
            {
                $package->title = $package->title . '-src';
            }

            if ($repo_arch_name == 'armv7el')
            {
                // fix the inconsistency between the published repo and the API
                $repo_arch_name = 'armv7l';
            }

            // direct download url
            $_uri = str_replace(':', ':/', $project_name) . '/' . str_replace(':', ':/', $repo_name) . '/' . $repo_arch_name . '/' . $file_name;
            $package->downloadurl = $this->download_repo_protocol . '://' . $this->download_repo_host . '/' . $_uri;

            try
            {
                // get the install file URL
                $package->installfileurl = $this->api->getInstallFileURL($project_name, $repo_name, $arch_name, $package_name, $file_name);
            }
            catch (RuntimeException $e)
            {
                echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
            }

            // @todo
            $package->bugtracker = '* TODO *';

            // for some info we need a special xray
            try
            {
                $rpmxray = new RpmXray($this->download_repo_protocol, $this->download_repo_host, $_uri);
            }
            catch (RuntimeException $e)
            {
                echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";

                // if there was a problem during xray (with code 999)
                // then it almost certainly means that the package no longer exists in the repository
                // so if the package exists in our database then remove it
                if (   $package->guid
                    && $e->getCode() == 999)
                {
                    // if package deletion is OK then return immediately
                    $result = $this->deletePackage($package);

                    if ($result)
                    {
                        return null;
                    }
                }
            }

            if (is_object($rpmxray))
            {
                $package->license = $this->getLicense($rpmxray->license, '');
                $package->homepageurl = $rpmxray->url;
                $package->category = $this->getCategory($rpmxray->group);
            }

            // call the parent
            if ($package->guid)
            {
                echo '           update: ' . $package->filename . ' (name: ' . $package->name . ")\n";
                $package->update();
            }
            else
            {
                echo '           create: ' . $package->filename . ' (name: ' . $package->name . ")\n";
                $package->create();
            }

            // add relations by calling the parent class
            $this->addRelations($extinfo, $package);
        }

        return $package;
    }

    /**
     * @todo: docs
     */
    public function getRpm($project_name, $repo_name, $arch_name, $package_name)
    {
        static $cache = null;

        if (null === $cache)
        {
            $cache = array();
        }

        if ( ! array_key_exists($project_name . '_' . $package_name, $cache) )
        {
            try
            {
                $rpm = $this->api->downloadBinary($project_name, $repo_name, $arch_name, $package_name);
            }
            catch (RuntimeException $e)
            {
                echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
            }

            if ($rpm === false)
            {
                throw new RuntimeException("couldn't get rpm file");
            }

            $cache[$project_name . '_' . $package_name] = new RpmXray($rpm, true);
        }

        return $cache[$project_name . '_' . $package_name];
    }

    /**
     * @todo: docs
     */
    public function getSpec($project_name, $package_name)
    {
        static $cache = null;

        if (null === $cache)
        {
            $cache = array();
        }

        if ( ! array_key_exists($project_name . '_' . $package_name, $cache) )
        {
            try
            {
                $spec_stream = $this->api->getPackageSpec($project_name, $package_name);
            }
            catch (RuntimeException $e)
            {
                echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
            }

            if (false === $spec_stream)
            {
                throw new RuntimeException("couldn't get spec-file");
            }

            $cache[$project_name . '_' . $package_name] = new RpmSpecParser($spec_stream, '');
        }

        return $cache[$project_name . '_' . $package_name];
    }
}
