<?php
require __DIR__.'/api.php';
//require __DIR__.'/../parser/RpmSpecParser.php';
require __DIR__.'/../parser/RpmXray.php';

/**
 * @todo: docs
 */
class Fetcher
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
            $config = parse_ini_file(dirname(__FILE__) . '/config.ini');

            if (isset($config['host']))
            {
                $this->api = new com_meego_obsconnector_API($config['login'], $config['password'], $config['host']);
            }
            else
            {
                $this->api = new com_meego_obsconnector_API($config['login'], $config['password']);
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

            $this->go($project_name, $cleanonly);
        }
    }

    /**
     * Goes through a project
     *
     * @param string OBS project name, e.g. home:feri
     * @param boolean $cleanonly if true then only clenup will be performed on the local database
     *                otherwise full import happens
     *
     */
    public function go($project_name, $cleanonly = false)
    {
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
                $package->license = $rpmxray->license;
                $package->homepageurl = $rpmxray->url;
                $package->category = $this->getCategory($rpmxray->group);
            }

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

            // check if package is referred in a relation
            // and update the relation record's 'to' field unless
            // it has been set already

            $qc = new midgard_query_constraint_group('AND');
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('toname'),
                '=',
                new midgard_query_value($package->filename)
            ));
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('version'),
                '=',
                new midgard_query_value($package->version)
            ));

            $q = new midgard_query_select(new midgard_query_storage('com_meego_package_relation'));
            $q->set_constraint($qc);
            $q->execute();
            $relations = $q->list_objects();

            if (! count($relations))
            {
                echo "           package is not required by others\n";
            }
            else
            {
                // repo of the current package
                $repository_a = new com_meego_repository();
                $repository_a->get_by_id($package->repository);

                foreach ($relations as $relation)
                {
                    if ($relation->to != 0)
                    {
                        echo '           package is in relation but "to" field is already set. relation id: ' . $relation->id . "\n";
                        continue;
                    }

                    // get the related package object
                    $related_package = new com_meego_package();
                    $related_package->get_by_id($relation->from);

                    // repo of the related package
                    $repository_b = new com_meego_repository();
                    $repository_b->get_by_id($related_package->repository);

                    if ($repository_a->arch == $repository_b->arch)
                    {
                        // we can safely update the to field of this relation
                        echo '           package is in relation with ' . $relation->from . ', update "to" field. relation id:' . $relation->id . "\n";
                        $_relation = new com_meego_package_relation($relation->guid);
                        $_relation->to = $package->id;
                        $_relation->update();
                    }
                }
                unset ($relations, $_relation);
            }

            // populate all kinds of package relations to our database
            $this->addRelations($extinfo, $package, $repo_id);
        }

        return $package;
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
    public function getCategory($group_string)
    {
        $prev = null;

        foreach (explode('/', $group_string) as $piece)
        {
            $qc = new midgard_query_constraint_group('AND');
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('name'),
                '=',
                new midgard_query_value($piece)
            ));
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('up'),
                '=',
                new midgard_query_value($prev === null ? 0 : $prev->id)
            ));

            $q = new midgard_query_select(new midgard_query_storage('com_meego_package_category'));
            $q->set_constraint($qc);
            $q->execute();
            $results = $q->list_objects();

            if (count($results) === 0)
            {
                $category = new com_meego_package_category();
                $category->name = $piece;
                $category->up = ($prev === null ? 0 : $prev->id);
                $category->create();

                $prev = $category;
            }
            else
            {
                $prev = $results[0];
            }
        }

        return $prev->id;
    }

    /**
     * Populates relations, such as
     *
     * - runtime dependency packages
     * - build dependency packages
     * - provided packages
     * - obsoleted packages
     * - suggested packages
     * - conflicting packages
     *
     * @param object extinfo which is a Package object
     * @param object package which is a com_meego_package object
     *
     */
    private function addRelations($extinfo = null, $package = null, $repo_id = null)
    {
        if (is_array($extinfo->depends))
        {
            /* delete relations that are no longer needed */
            $this->cleanRelations('requires', $extinfo->depends, $package);
            foreach ($extinfo->depends as $dependency)
            {
                $this->createRelation('requires', $dependency, $package, $repo_id);
            }
        }

        if (is_array($extinfo->buildDepends))
        {
            /* delete relations that are no longer needed */
            $this->cleanRelations('buildrequires', $extinfo->buildDepends, $package);
            foreach ($extinfo->buildDepends as $dependency)
            {
                $this->createRelation('buildrequires', $dependency, $package);
            }
        }

        if (is_array($extinfo->provides))
        {
            /* delete relations that are no longer needed */
            $this->cleanRelations('provides', $extinfo->provides, $package);
            foreach ($extinfo->provides as $provided)
            {
                $this->createRelation('provides', $provided, $package);
            }
        }

        if (is_array($extinfo->obsoletes))
        {
            /* delete relations that are no longer needed */
            $this->cleanRelations('obsoletes', $extinfo->obsoletes, $package);
            foreach ($extinfo->obsoletes as $obsoleted)
            {
                $this->createRelation('obsoletes', $obsoleted, $package);
            }
        }

        if (is_array($extinfo->suggests))
        {
            /* delete relations that are no longer needed */
            $this->cleanRelations('suggests', $extinfo->suggests, $package);
            foreach ($extinfo->suggests as $suggested)
            {
                $this->createRelation('suggests', $suggested, $package);
            }
        }

        if (is_array($extinfo->conflicts))
        {
            /* delete relations that are no longer needed */
            $this->cleanRelations('conflicts', $extinfo->conflicts, $package);
            foreach ($extinfo->conflicts as $conflicted)
            {
                $this->createRelation('conflicts', $conflicted, $package);
            }
        }
    }

    /**
     * Cleans up relations from the database that are no longer specified for the package
     *
     * @param string relation of the relation: requires, buildrequires, obsoletes, conflicts, provides
     * @param array of relative objects
     * @param object parent package object
     */
    private function cleanRelations($type, $relatives, $parent)
    {
        $_deleted = array();
        $storage = new midgard_query_storage('com_meego_package_relation');

        $qc = new midgard_query_constraint_group('AND');
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('from'),
            '=',
            new midgard_query_value($parent->id)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('relation'),
            '=',
            new midgard_query_value($type)
        ));

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            foreach ($results as $relation)
            {
                echo '           check if ' . $parent->name . ' still ' . $type . ': ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . "\n";
                foreach ($relatives as $relative)
                {
                    //echo '            Compare: ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . ' <<<---->>> ' . $relative->name . ' ' . $relative->constraint . ' ' . $relative->version . "\n";
                    if (   ! ($relation->toname == $relative->name
                        && $relation->constraint == $relative->constraint
                        && $relation->version == $relative->version ))
                    {
                        //echo '            mark deleted ' . $relation->id . "\n";
                        $_deleted[$relation->guid] = $relation->id;
                    }
                    else
                    {
                        //echo '            mark kept: ' . $relation->id . "\n";
                        unset($_deleted[$relation->guid]);
                        break;
                    }
                }
            }

            foreach ($_deleted as $guid => $value)
            {
                $relation = new com_meego_package_relation($guid);
                if (is_object($relation))
                {
                    $relation->delete();
                    echo '           delete ' . $type . ' of package ' . $parent->name . ': relation guid: ' . $guid . ' (id: ' . $value . ')' . "\n";
                }
            }
        }
    }

    /**
     * Create a relation only if it does not exists yet
     * If exists then just update
     *
     * @param string relation of the relation: requires, buildrequires, obsoletes, conflicts, provides
     * @param object relative object that is in some relation with package
     * @param object parent package object
     */
    private function createRelation($type, $relative, $parent, $repo_id = null)
    {
        $storage = new midgard_query_storage('com_meego_package_relation');

        $qc = new midgard_query_constraint_group('AND');
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('from'),
            '=',
            new midgard_query_value($parent->id)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('relation'),
            '=',
            new midgard_query_value($type)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('toname'),
            '=',
            new midgard_query_value($relative->name)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('version'),
            '=',
            new midgard_query_value($relative->version)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('constraint'),
            '=',
            new midgard_query_value($relative->constraint)
        ));

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            $relation = new com_meego_package_relation($results[0]->guid);
        }
        else
        {
            $relation = new com_meego_package_relation();
            $relation->from = $parent->id;
            $relation->relation = $type;
            $relation->toname = $relative->name;

            // check if the relative has already been imported
            // if yes, then set relation->to to the relative's ID
            $_package = $this->getPackageByName($relative->name, $repo_id);

            if ($_package->guid)
            {
                $relation->to = $_package->id;
            }

            $relation->version = $relative->version;
            $relation->constraint = $relative->constraint;
        }

        /* @todo: this might actually be $this->getCategory($dependency->group); */
        $relation->group = $parent->group;

        if (! $relation->guid)
        {
            $_res = $relation->create();
            echo '           ' . $relation->relation . ': ' . $relation->toname . ' (package id: ' . $relation->to . ') ' . $relation->constraint . ' ' . $relation->version . "\n";
        }
        else
        {
            $_res = $relation->update();
            echo '           ' . $relation->relation . ' updated: ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . "\n";
        }

        if ($_res != 'MGD_ERR_OK')
        {
            $_mc = midgard_connection::get_instance();
            echo 'Error received from midgard_connection: ' . $_mc->get_error_string() . "\n";
        }
    }

    /**
     * Checks if a project already exists in the database
     * If the project exists then it returns its object
     * Otherwise it returns a blank com_meego_project object
     *
     * @param string project name
     *
     * @return mixed com_meego_project object
     */
    private function getProject($name = null)
    {
        $storage = new midgard_query_storage('com_meego_project');

        // name should be unique
        $qc = new midgard_query_constraint(
            new midgard_query_property('name', $storage),
            '=',
            new midgard_query_value($name)
        );

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            $project = new com_meego_project($results[0]->guid);
        }
        else
        {
            $project = new com_meego_project();
        }

        return $project;
    }

    /**
     * Checks if a repository already exists in the database
     * If the repository exists then it returns its object
     * Otherwise it returns a blank com_meego_repository object
     *
     * @param string repository name, e.g meego_1.1_core_handset
     * @param string architecture, e.g. i586
     * @param integer project id, e.g. 1
     *
     * @return mixed com_meego_repository object
     */
    private function getRepository($name, $arch, $project_id)
    {
        $storage = new midgard_query_storage('com_meego_repository');

        $qc = new midgard_query_constraint_group('AND');
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('name'),
            '=',
            new midgard_query_value($name)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('arch'),
            '=',
            new midgard_query_value($arch)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('project'),
            '=',
            new midgard_query_value($project_id)
        ));

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            $repository = new com_meego_repository($results[0]->guid);
        }
        else
        {
            $repository = new com_meego_repository();
        }

        return $repository;
    }

    /**
     * Gets a package by its file name
     * Returns an empty com_meego_package instance if the package does not exist
     *
     * @param string package filename, e.g. cdparanoia-libs-10.2-1.1.i586.rpm
     * @param int id of the repository the package belongs to
     *
     * @return mixed package object
     */
    private function getPackageByFileName($filename = null, $repository = null) {
        $storage = new midgard_query_storage('com_meego_package');

        $qc = new midgard_query_constraint_group('AND');
        if (   strlen($filename)
            && $repository > 0)
        {
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('filename'),
                '=',
                new midgard_query_value($filename)
            ));
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('repository'),
                '=',
                new midgard_query_value($repository)
            ));
        }

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            $package = new com_meego_package($results[0]->guid);
        }
        else
        {
            $package = new com_meego_package();
        }
        return $package;
    }

    /**
     * Gets a package by its name
     * Returns an empty com_meego_package instance if the package does not exist
     *
     * @param string package name, e.g. cdparanoia-libs
     * @param int id of the repository the package belongs to
     *
     * @return mixed package object
     */
    private function getPackageByName($name = null, $repository = null) {
        $storage = new midgard_query_storage('com_meego_package');

        $qc = new midgard_query_constraint_group('AND');
        if (   strlen($title)
            && $repository > 0)
        {
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('title'),
                '=',
                new midgard_query_value($name)
            ));
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('repository'),
                '=',
                new midgard_query_value($repository)
            ));
        }

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            $package = new com_meego_package($results[0]->guid);
        }
        else
        {
            $package = new com_meego_package();
        }
        return $package;
    }


    /**
     * Deletes all relations a package is involved in
     *
     * @param integer id of the package
     * @return boolean true if all relations are deleted, false otherwise
     */
    private function deleteRelations($id)
    {
        $retval = true;

        $storage = new midgard_query_storage('com_meego_package_relation');

        $qc = new midgard_query_constraint_group('OR');
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('from'),
            '=',
            new midgard_query_value($id)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('to'),
            '=',
            new midgard_query_value($id)
        ));

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            foreach ($results as $object)
            {
                $relation = new com_meego_package_relation($object->guid);

                if (   is_object($relation)
                    && $relation->delete())
                {
                    echo '              deleted relation: ';
                }
                else
                {
                    echo '              failed to delete relation: ';
                    $retval = false;
                    break;
                }
                echo  $relation->id . ' (from: ' . $relation->from . ', to: ' . $relation->to .")\n";
            }
        }

        return $retval;
    }

    /**
     * Deletes a packages from database
     * Used only if during an update we notice that a package is no longer available in a repositor
     *
     * @param object com_meego_package object
     *
     * @return boolean true if operation succeeded, false otherwise
     */
    private function deletePackage($package)
    {
        $retval = false;
        //$object = new com_meego_package($guid);

        if (is_object($package))
        {
            // we have to remove all the relations before Midgard is willing
            // to delete the package
            $retval = $this->deleteRelations($package->id);

            if ($retval)
            {
                $retval = $package->delete();

                if ($retval)
                {
                    echo '              deleted: ';
                }
            }
        }

        if (! $retval)
        {
            echo '              failed to delete package: ';
        }
        echo  $package->filename . ' (' . $package->guid .")\n";

        return $retval;
    }

    /**
     * Cleans packages from the database if they are
     * no longer part of the OBS repository
     *
     * @param object repository object from our database
     * @param array of binaries that are currently part of the OBS repository
     *
     */
    private function cleanPackages($repo = null, $newlist = array())
    {
        $found = false;

        echo "\n     cleanup: " . $repo->name . ' (id: ' . $repo->id . '; ' . $repo->os . ' ' . $repo->osversion . ', ' . $repo->osgroup . ', ' . $repo->osux . ")\n";

        $storage = new midgard_query_storage('com_meego_package');

        $qc = new midgard_query_constraint(
            new midgard_query_property('repository'),
            '=',
            new midgard_query_value($repo->id)
        );

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $oldpackages = $q->list_objects();

        foreach ($oldpackages as $oldpackage)
        {
            if (array_search($oldpackage->filename, $newlist) === false)
            {
                $found = true;
                // the package is not in the list, so remove it from db
                $retval = $this->deletePackage($oldpackage);
            }
        }

        if (! $found)
        {
            echo "              no cleanup needed\n";
        }
    }
}
