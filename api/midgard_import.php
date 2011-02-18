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
    private $download_repo_prefix = 'http://repo.pub.meego.com';

    /**
     * @todo: docs
     */
    public function __construct()
    {
        if ( ! file_exists(dirname(__FILE__).'/config.ini') )
        {
            // @TODO:
            // This could be uncommented if we could get list of publisihed projects
            // via the /public route

            //echo "Importing only public repositories\n";
            //$this->api = new com_meego_obsconnector_API();

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
    public function scan_all_projects()
    {
        $projects = $this->api->getProjects();
        $i = 0;
        foreach ($projects as $project_name)
        {
            echo '#' . ++$i . ' Project: ' . $project_name . "\n";
            echo "--------------------------------------------\n";
            $this->go($project_name);
        }
    }

    /**
     * Goes through a project
     */
    public function go($project_name)
    {
        $project_meta = $this->api->getProjectMeta($project_name);
        echo "Repositories:\n";
        $repositories = $this->api->getRepositories($project_name);
        foreach ($repositories as $repo_name) {
            echo "\n -> " . $repo_name . "\n";

            //var_dump($project_meta['repositories'][$repo_name]);

            foreach ($this->api->getArchitectures($project_name, $repo_name) as $arch_name)
            {
                echo '  -> '.$arch_name."\n";

                $repo = $this->getRepository($repo_name . '_' . $arch_name);

                $repo->name = $repo_name . '_' . $arch_name;
                $repo->title = $repo_name . ' (for ' . $arch_name . ')';
                $repo->arch = $arch_name;
                $repo->url = '/published/' . $project_name . '/' . $repo_name . '/' . $arch_name;

                $repo->os = $project_meta['repositories'][$repo_name]['os'];
                $repo->osversion = $project_meta['repositories'][$repo_name]['osversion'];
                $repo->osgroup = $project_meta['repositories'][$repo_name]['osgroup'];
                $repo->osux = $project_meta['repositories'][$repo_name]['osux'];

                if ( ! $repo->guid )
                {
                    echo '     create: ' . $repo->name;
                    $repo->create();
                }
                else
                {
                    echo '     update: ' . $repo->name;
                    $repo->update();
                }
                echo ' (' . $repo->os . ' ' . $repo->osversion . ', ' . $repo->osgroup . ', ' . $repo->osux . ")\n";

                foreach ($this->api->getPackages($project_name, $repo_name, $arch_name) as $package_name)
                {
                    echo "\n   -> package #" . ++$this->package_counter . ': ' . $package_name . "\n";

                    foreach($this->api->getBinaryList($project_name, $repo_name, $arch_name, $package_name) as $file_name)
                    {
                        echo '     -> binary #' . ++$this->build_counter . ': ' . $file_name . "\n";

                        $extinfo = $this->api->getPackageWithInformation($project_name, $repo_name, $arch_name, $package_name, $file_name);

                        // get a com_meego_package instance
                        $package = $this->getPackage($extinfo->name, $extinfo->version, $repo->id);

                        $package->name = $file_name;
                        $package->title = $extinfo->name;
                        $package->version = $extinfo->version;
                        $package->summary = $extinfo->summary;
                        $package->description = $extinfo->description;
                        $package->repository = $repo->id;

                        // add the drect download link
                        $package->downloadurl = $this->download_repo_prefix . '/' . str_replace('home:', 'home:/', $project_name) . '/' . $repo_name . '/' . $arch_name . '/' . $file_name;

                        // get the install file URL
                        $package->installfileurl = $this->api->getInstallFileURL($project_name, $repo_name, $arch_name, $package_name, $file_name);

                        // @todo
                        $package->bugtracker = '* TODO *';

                        // for some info we need a special xray
                        try
                        {
                            $rpmxray = new RpmXray($package->downloadurl);
                        }
                        catch (RuntimeException $e) {
                            echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
                        }

                        if (is_object($rpmxray))
                        {
                            $package->license = $rpmxray->license;
                            $package->homepageurl = $rpmxray->url;
                            $package->category = $this->getCategory($rpmxray->group);
                        }

                        if ( ! $package->guid )
                        {
                            echo '        create: ' . $package->name . "\n";
                            $package->create();
                        }
                        else
                        {
                            echo '        update: ' . $package->name . "\n";
                            $package->update();
                        }

                        // populate all kinds of package relations to our database
                        $this->addRelations($extinfo, $package);

                        try
                        {
                            // check the filelist of the package that can be obtained vian an OBS API call
                            // download the package locally if it has a promising icon in it
                            // $rpm = $this->getRpm($project_name, $repo_name, $arch_name, $package_name);
                        }
                        catch (RuntimeException $e) {
                            echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
                            die;
                        }

                        $screenshot_names = array_filter(
                            $this->api->getPackageSourceFiles($project_name, $package_name),
                            function($name)
                            {
                                $_marker = 'screenshot.png';
                                return strpos($name, $_marker) === (strlen($name) - strlen($_marker));
                            }
                        );

                        foreach ($screenshot_names as $name)
                        {
                            $fp = $this->api->getPackageSourceFile($project_name, $package_name, $name);

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
                    }
                }
            }
        }
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
            $spec_stream = $this->api->getPackageSpec($project_name, $package_name);

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
            $rpm = $this->api->downloadBinary($project_name, $repo_name, $arch_name, $package_name);

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
    private function addRelations($extinfo = null, $package = null)
    {
        if (is_array($extinfo->depends))
        {
            /* delete relations that are no longer needed */
            $this->cleanRelations('requires', $extinfo->depends, $package);
            foreach ($extinfo->depends as $dependency)
            {
                $this->createRelation('requires', $dependency, $package);
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
            new midgard_query_property('from', $storage),
            '=',
            new midgard_query_value($parent->id)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('relation', $storage),
            '=',
            new midgard_query_value($type)
        ));

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            foreach ($results as $relation) {
                echo '        check if ' . $parent->name . ' still ' . $type . ': ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . "\n";
                foreach ($relatives as $relative) {
                    //echo '            Compare: ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . ' <<<---->>> ' . $relative->name . ' ' . $relative->constraint . ' ' . $relative->version . "\n";
                    if (   ! ($relation->toname == $relative->name
                        && $relation->constraint == $relative->constraint
                        && $relation->version == $relative->version )) {
                        //echo '            mark deleted ' . $relation->id . "\n";
                        $_deleted[$relation->guid] = $relation->id;
                    } else {
                        //echo '            mark kept: ' . $relation->id . "\n";
                        unset($_deleted[$relation->guid]);
                        break;
                    }
                }
            }

            foreach ($_deleted as $guid => $value)
            {
                $relation = new com_meego_package_relation($guid);
                if (is_object($relation)) {
                    $relation->delete();
                    echo '        delete ' . $type . ' of package ' . $parent->name . ': relation guid: ' . $guid . ' (id: ' . $value . ')' . "\n";
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
    private function createRelation($type, $relative, $parent)
    {
        $storage = new midgard_query_storage('com_meego_package_relation');

        $qc = new midgard_query_constraint_group('AND');
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('from', $storage),
            '=',
            new midgard_query_value($parent->id)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('relation', $storage),
            '=',
            new midgard_query_value($type)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('toname', $storage),
            '=',
            new midgard_query_value($relative->name)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('version', $storage),
            '=',
            new midgard_query_value($relative->version)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('constraint', $storage),
            '=',
            new midgard_query_value($relative->constraint)
        ));

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            $relation = $results[0];
        }
        else
        {
            // if type is one of these below then add relative to our database
            // also add its repository to our database
            switch ($type)
            {
                case 'requires':
                case 'buildrequires':
                case 'suggests':
                case 'provides':
                    $_repository = $this->getRepository($relative->repository);
                    if ( ! $_repository->guid )
                    {
                        $_repository->name = $relative->repository;
                        echo '        create repository for relative package: ' . $_repository->name . "\n";
                        // other fields of _repository will be filled when we import (if ever) this relative repository
                        $_repository->create();
                    }

                    $_package = $this->getPackage($relative->name, $relative->version, $_repository->id);
                    if ( ! $_package->guid )
                    {
                        $_package->title = $relative->name;
                        $_package->version = $relative->version;
                        $_package->repository = $_repository->id;
                        echo '        create relative package: ' . $_package->name . "\n";
                        // other fields of _package will be filled when we import (if ever) this relative package
                        $_package->create();
                    }
            }

            $relation = new com_meego_package_relation();
            $relation->from = $parent->id;
            $relation->relation = $type;
            $relation->toname = $relative->name;

            if (   is_object($_package)
                && isset($_package->id))
            {
                $relation->to = $_package->id;
            }

            $relation->version = $relative->version;
            $relation->constraint = $relative->constraint;
        }

        /* @todo: this might actually be $this->getCategory($dependency->group); */
        $relation->group = $parent->group;

        if (! isset($relation->guid))
        {
            $_res = $relation->create();
            echo '        relation created: ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . "\n";
        }
        else
        {
            $_res = $relation->update();
            echo '        relation updated: ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . "\n";
        }

        if ($_res != 'MGD_ERR_OK')
        {
            $_mc = midgard_connection::get_instance();
            echo 'Error received from midgard_connection: ' . $_mc->get_error_string() . "\n";
        }
    }

    /**
     * Checks if a repository already exists in the database
     *
     * @param string repository name
     * @return mixed repo object
     */
    private function getRepository($name) {
        $storage = new midgard_query_storage('com_meego_repository');
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
            $repository = $results[0];
        }
        else
        {
            $repository = new com_meego_repository();
        }
        return $repository;
    }

    /**
     * Checks if the package already exists in the database
     *
     * @param string package title, e.g. x11vnc
     * @param string package version
     * @param int id of the repository the package belongs to
     *
     * @return mixed package object
     */
    private function getPackage($title = null, $version = null, $repository = null) {
        $storage = new midgard_query_storage('com_meego_package');

        $qc = new midgard_query_constraint_group('AND');
        if (strlen($title))
        {
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('title', $storage),
                '=',
                new midgard_query_value($title)
            ));
        }
        if (strlen($version))
        {
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('version', $storage),
                '=',
                new midgard_query_value($version)
            ));
        }
        if (strlen($repository))
        {
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('repository', $storage),
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
            $package = $results[0];
        }
        else
        {
            $package = new com_meego_package();
        }
        return $package;
    }
}

$filepath = ini_get("midgard.configuration_file");
$config = new midgard_config ();
$config->read_file_at_path($filepath);
$mgd = midgard_connection::get_instance();
$mgd->open_config($config);

$f = new Fetcher();
if ($argv[1])
{
    $f->go($argv[1]);
}
else
{
    $f->scan_all_projects();
}