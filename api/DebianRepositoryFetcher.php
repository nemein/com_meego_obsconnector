<?php

require __DIR__ . '/http.php';
require __DIR__ . '/Importer.php';
require __DIR__ . '/../parser/DebianPackagesParser.php';

/**
 * @todo: docs
 */
class DebianRepositoryFetcher extends Importer
{
    private $http = null;

    private $project_name = null;
    private $package_counter = 0;
    private $build_counter = 0;

    /**
     * Creates a fetcher
     *
     * @param string HTTP basic auth login name
     * @param string HTTP basic auth password
     */
    public function __construct($login = '', $password = '')
    {
        $this->http = new com_meego_obsconnector_HTTP($host . $prefix, 'https');

        if (   $login
            && $password)
        {
            $this->login = $login;
            $this->password = $password;
            $this->http->setAuthentication($login, $password);
        }
    }

    /**
     * Parses a Debian Release file and gets all the content to a associative array
     *
     * @param string the raw content of the Release file
     * @return array keys => value associative array with all release info
     */
    public function parseReleaseFile($content)
    {
        $release = array();
        return $release;
    }

    /**
     * Goes through a release specified by a URL that points to a Release file
     *
     * @param string URL pointing to a Debian Release file
     * @param boolean $cleanonly if true then only clenup will be performed on the local database
     *                otherwise full import happens
     *
     */
    public function go($release_file_url, $cleanonly = false)
    {
        // get release information from the relase file
        $content = $this->http->get($release_file_url);
        $release = $this->parseReleaseFile($content);

        // check if the project is already recorded in our database
        // this returns a com_meego_project object
        $project = $this->getProject($release['project_name']);

        var_dump($project);
        die('end here for now');

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
                    $repo->title = $repo_name . ' (for ' . $arch_name . ')';
                    $repo->arch = $arch_name;
                    $repo->project = $project->id;

                    $repo->os = $project_meta['repositories'][$repo_name]['os'];
                    $repo->osversion = $project_meta['repositories'][$repo_name]['osversion'];
                    $repo->osgroup = $project_meta['repositories'][$repo_name]['osgroup'];
                    $repo->osux = $project_meta['repositories'][$repo_name]['osux'];

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
                        echo $repo->name . ' (id: ' . $repo->id . '; ' . $repo->os . ' ' . $repo->osversion . ', ' . $repo->osgroup . ', ' . $repo->osux . ")\n";
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
}
