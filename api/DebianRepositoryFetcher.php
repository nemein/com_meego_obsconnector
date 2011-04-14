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

    private $protocol = 'http';

    /**
     * Creates a fetcher
     *
     * @param string HTTP basic auth login name
     * @param string HTTP basic auth password
     */
    public function __construct($protocol = 'http', $login = '', $password = '')
    {
        $this->protocol = $protocol;

        switch ($this->protocol)
        {
            case 'http':
            case 'https':
                $this->http = new com_meego_obsconnector_HTTP();

                if (   $login
                    && $password)
                {
                    $this->login = $login;
                    $this->password = $password;
                    $this->http->setAuthentication($login, $password);
                }
                break;
            case 'file':
                # todo
        }
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
        switch ($this->protocol)
        {
            case 'http':
            case 'https':
                $content = $this->http->get($release_file_url);
                break;
            case 'file':
                # todo
                break;
        }

        $release = $this->parseReleaseFile($content);

        if (! array_key_exists('Suite', $release))
        {
            throw new RuntimeException('The Release files does not specify a suite.' . $packages_file_url);
            return null;
        }

        // generate name and tile from release information
        $project_name = strtolower($release['Origin'] . '-' . $release['Suite'] . '-' . $release['Label']);
        $project_title = ucwords($release['Origin'] . ' ' . $release['Suite'] . ' ' . $release['Label']);

        // check if the project is already recorded in our database
        // this returns a com_meego_project object
        $project = $this->getProject($project_name);

        // set properties
        $project->name = $project_name;
        $project->title = $project_title;

        $project->description = "Debian repository from " . ucfirst($release['Origin']);

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
            echo "\nComponents in $project->name:\n";

            // get all components (ie repositories in the database) this Suite contains
            $repositories = explode(' ', $release['Components']);

            // iterate through each and every published repository
            // and dig out the packages
            foreach ($repositories as $repo_name)
            {
                echo "\n -> " . $repo_name . "\n";

                $architectures = explode(' ', $release['Architectures']);

                // get all available architectures within this repository
                foreach ($architectures as $arch_name)
                {
                    echo "\n  -> " . $arch_name . "\n";

                    // get a com_meego_repository object
                    $repo = $this->getRepository($repo_name, $arch_name, $project->id);

                    $repo_title = ucfirst($release['Suite']) . ' ' . ucfirst($release['Label']) . ' ' . ucfirst($repo_name) . ' (for ' . $arch_name . ')';

                    // fill in properties of the repo object
                    $repo->name = strtolower($release['Suite'] . '_' . $release['Label'] . '_' . $repo_name);
                    $repo->title = $repo_title;
                    $repo->arch = $arch_name;
                    $repo->project = $project->id;

                    $repo->os = $release['Origin'];
                    $repo->osversion = $release['Suite'];
                    $repo->osgroup = $release['Label'];
                    $repo->osux = $release['ux'];

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

                    // determine the url of the binary Packages file
                    $packages_file_url = preg_replace('|Release$|', '', $release_file_url) . '/' . $repo_name . '/binary-' . $arch_name . '/Packages';

                    // get an array of all available packages
                    // this array is a special one, so study the method for details
                    $packages = $this->parsePackagesFile($packages_file_url);

                    if ( ! count($packages))
                    {
                        continue;
                    }

                    foreach ($packages as $package_name => $versions)
                    {
                        // iterate through each versions
                        foreach ($versions as $package_version => $details)
                        {
                            echo "\n     -> package #" . ++$this->package_counter . ': ' . $package_name . ' ' . $package_version . "\n";

                            if ($cleanonly)
                            {
                                // only cleanup is requested so we can go to the next package
                                continue;
                            }

                            // creates or updates a package in the database
                            $package = $this->createPackage($project->name, $repo->id, $repo_name, $arch_name, $details);

                            if ( ! $package )
                            {
                                // we got no package object, usually because we had to delete
                                // an existing one from database, so go to next binary package
                                continue;
                            }

                            $fulllist[] = $package->filename;

                            // @todo
                            $screenshot_names = array();

                            foreach ($screenshot_names as $name)
                            {
                                // @todo: a remote file that should be opened with http->get, or something to get a filepointer
                                $fp = null;

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
                        }
                    }

                    // now cleanup all the packages from our database
                    // that are not part of this OBS repository

                    $this->cleanPackages($repo, $fulllist);
                }
            }
        } // if $project->id
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
        $lines = explode("\n", trim($content));

        foreach($lines as $line)
        {
            $info = explode(':', $line);
            if (count($info) == 2)
            {
                $release[trim($info[0])] = trim(strtolower($info[1]));
            }
        }

        if (! array_key_exists('ux', $release))
        {
            $release['ux'] = 'universal';
        }

        return $release;
    }

    /**
     * Parse Packages file of a repository
     * and return an array with all packages associated with their data
     *
     * @param string url of the Packages file
     * @return array associative array of packages
     */
    public function parsePackagesFile($packages_file_url)
    {
        $packages = array();

        // get release information from the relase file
        // get release information from the relase file
        switch ($this->protocol)
        {
            case 'http':
            case 'https':
                $handle = $this->http->get_as_stream($packages_file_url);
                break;
            case 'file':
                # todo
                break;
        }

        if ($handle === false)
        {
            throw new RuntimeException('Unable to open Packages file: ' . $packages_file_url);
            return null;
        }
        else
        {
            $current_package = '';
            $current_version = '';
            $info = '';
            $data = '';
            $buffer = array();

            while(   is_resource($handle)
                  && ! feof($handle))
            {
                $line = rtrim(fgets($handle));

                if (strlen($line))
                {
                    $cnt = preg_match('|^([0-9A-Za-z-+]*):(.*)$|', $line, $matches);

                    if (count($matches) == 3)
                    {
                        $info = $matches[1];
                        $data = trim($matches[2]);

                        switch ($info)
                        {
                            case 'Package':
                                $current_version = '';
                                $current_package = $data;
                                break;
                            case 'Filename':
                                // extend the data to become a full URL
                                $data = substr_replace($packages_file_url, $data, strpos($packages_file_url, 'dists'));
                                // add an extra field to package array
                                $prefix = $this->protocol . '://';
                                $packages[$current_package][$current_version]['Directdownload'] = $prefix . $data;
                                // now shortend the data to become only the filename with .deb extension
                                $data = substr($data, strrpos($data, '/') + 1);
                                break;
                            case 'Version':
                                $current_version = $data;
                                break;
                            case 'Build-Depends':
                            case 'Build-Depends-Indep':
                            case 'Build-Conflicts':
                            case 'Build-Conflicts-Indep':
                                $info = str_replace('-', '', $info);
                            case 'Depends':
                            case 'Conflicts':
                            case 'Provides':
                            case 'Enchances':
                            case 'Recommends':
                            case 'Breaks':
                            case 'Replaces':
                            case 'PreDepends':
                                $info = lcfirst($info);
                                break;
                        }
                    }
                    else
                    {
                        if (strlen($info))
                        {
                            // if we have an info
                            if (   substr($line, 0, 1) == ' '
                                || substr($line, 0, 1) == '\t')
                            {
                                // if the new line starts with space to tab
                                // then it is a folded line, so we can trim it
                                $data .= trim($line);
                            }
                            else
                            {
                                // it is a multiline info, so we can't trim the new line
                                $data .= $line;
                            }
                        }
                    }

                    if (   strlen($current_package)
                        && strlen($info)
                        && strlen($data))
                    {
                        if (strlen($current_version))
                        {
                            if (count($buffer))
                            {
                                // if we have a buffer than let's 1st pushed that info to the package array
                                $packages[$current_package][$current_version] = $buffer[$current_package];
                                // now set the version too
                                $packages[$current_package][$current_version]['Version'] = $data;
                                // reset the buffer
                                $buffer = array();
                            }
                            else
                            {
                                $packages[$current_package][$current_version][$info] = $data;
                            }
                        }
                        else
                        {
                            // no version number read yet, so we buffer the data
                            $buffer[$current_package][$info] = $data;
                        }
                    }
                }
            }
        }

        fclose($handle);
        return $packages;
    }

    /**
     * Creates or updates a package in the database
     *
     * @return object package object
     */
    public function createPackage($project_name = null, $repo_id = null, $repo_name = null, $arch_name = null, $details = null)
    {
        $package = null;

        if (   $project_name
            && $repo_id
            && $repo_name
            && $arch_name
            && $details)
        {
            // get a com_meego_package instance
            $package = $this->getPackageByFileName($details['Filename'], $repo_id);

            if (is_object($package))
            {
                if ( ! $package->guid )
                {
                    $package->repository = $repo_id;
                    $package->filename = $details['Filename'];
                }

                $package->name = $details['Package'];

                if (array_key_exists('Maemo-Display-Name', $details))
                {
                    $package->title = $details['Maemo-Display-Name'];
                }
                else
                {
                    $package->title = $details['Package'];
                }

                if (array_key_exists('Summary', $details))
                {
                    $package->summary = $details['Summary'];
                }
                if (array_key_exists('Bugtracker', $details))
                {
                    $package->bugtracker = $details['Bugtracker'];
                }
                if (array_key_exists('Homepage', $details))
                {
                    $package->homepageurl = $details['Homepage'];
                }

                $details['License'] = '';

                $package->version = $details['Version'];
                $package->description = $details['Description'];

                // direct download url
                $package->downloadurl = $details['Directdownload'];

                $package->category = $this->getCategory($details['Section']);

                // @todo for this we need a remote check or some other magic
                // since this info is inside the package itself
                $package->license = '';

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

                // create relation arrays within details instead of strings
                if (array_key_exists('depends', $details))
                {
                    $details['depends'] = $this->parseRelationLine($details['depends']);
                }

                // add relations by calling the parent class
                $this->addRelations($details, $package);
            }
        }

        return($package);
    }


    /**
     * Parses relations lines and returns an array
     *
     * @param $line string
     * @return array
     */
    private function parseRelationLine($line)
    {
        $relations = array();
        $items = explode(',', $line);

        foreach($items as $item)
        {
            if (strpos($item, '|'))
            {
                // now check if we have | in the item
                $pieces = explode('|', $item);
            }
            else
            {
                $pieces = array($item);
            }

            // parse the individial pieces
            foreach($pieces as $piece)
            {
                if (strpos($piece, '(') !== false)
                {
                    $cnt = preg_match('|^(.*)\s[(]?(.*)\s(.*)[)]$|', trim($piece), $matches);

                    if (count($matches) == 4)
                    {
                        $packagename = $matches[1];
                        $constraint = $matches[2];
                        $version = $matches[3];
                    }
                }
                else
                {
                    // we have constraint and version info
                    $packagename = $piece;
                    $constraint = '';
                    $version = '';
                }

                $relation['name'] = trim($packagename);
                $relation['title'] = trim($packagename);
                $relation['constraint'] = trim($constraint);
                $relation['version'] = trim($version);

                $relations[] = $relation;
            }
        }

        return $relations;
    }
}
