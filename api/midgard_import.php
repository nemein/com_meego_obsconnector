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

        echo "Repositories in $project_name:\n";
        $repositories = $this->api->getRepositories($project_name);

        foreach ($repositories as $repo_name)
        {
            echo "\n -> " . $repo_name . "\n";

            //var_dump($project_meta['repositories'][$repo_name]);

            foreach ($this->api->getArchitectures($project_name, $repo_name) as $arch_name)
            {
                echo "\n  -> " . $arch_name . "\n";

                $repo = $this->getRepository($repo_name, $arch_name);

                $repo->name = $repo_name;// . '_' . $arch_name;
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
                        echo "\n     -> binary #" . ++$this->build_counter . ': ' . $file_name . "\n";

                        // creates or updates a package in the database
                        $package = $this->createPackage($project_name, $repo->id, $repo_name, $arch_name, $package_name, $file_name);

                        $screenshot_names = array_filter
                        (
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
                        try
                        {
                            // check the filelist of the package that can be obtained via an OBS API call
                            // download the package locally if it has a promising icon in it
                            // $rpm = $this->getRpm($project_name, $repo_name, $arch_name, $package_name);
                        }
                        catch (RuntimeException $e)
                        {
                            echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
                        }
                    }
                }
            }
        }
    }

    /**
     * Creates or updates a package in the database
     *
     * @return object package object
     */
    public function createPackage($project_name = null, $repo_id = null, $repo_name = null, $arch_name = null, $package_name = null, $file_name = null, $extinfo = null)
    {
        if (   $project_name
            && $repo_name
            && $arch_name
            && $package_name
            && $file_name)
        {

            // get fill package info via OBS API
            $extinfo = $this->api->getPackageWithFullInformation($project_name, $repo_name, $arch_name, $package_name, $file_name);
        }
        else
        {
            throw new RuntimeException('Not enough parameters to gather full package information');
            return;
        }

        // get a com_meego_package instance
        $package = $this->getPackage($file_name, $repo_id);

        if (   $package
            && $repo_id
            && $extinfo)
        {
            $package->name = $file_name;
            $package->title = $extinfo->name;
            $package->version = $extinfo->version;
            $package->summary = $extinfo->summary;
            $package->description = $extinfo->description;
            $package->repository = $repo_id;

            $repo_arch_name = $arch_name;

            if ($arch_name == 'armv7el')
            {
                // the armv7el repository name is different on the repository server than in the API
                $repo_arch_name = 'armv7l';
            }

            // determine file extension
            $extension = preg_replace('/.*\.(.*)/', '\1', $file_name);

            // if the package is a source package then the downloadurl is slightly different
            // also change the title a bit
            if (strrpos($file_name, '.src.' . $extension))
            {
                $repo_arch_name = 'src';
                $package->title = $package->title . '-src';
            }

            // direct download url
            $package->downloadurl = $this->download_repo_prefix . '/' . str_replace('home:', 'home:/', $project_name) . '/' . $repo_name . '/' . $repo_arch_name . '/' . $file_name;

            // get the install file URL
            $package->installfileurl = $this->api->getInstallFileURL($project_name, $repo_name, $arch_name, $package_name, $file_name);

            // @todo
            $package->bugtracker = '* TODO *';

            // for some info we need a special xray
            try
            {
                $rpmxray = new RpmXray($package->downloadurl);
            }
            catch (RuntimeException $e)
            {
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

            // check if package is referred in a relation
            // and update the relation record's 'to' field unless
            // it has been set already

            $qc = new midgard_query_constraint_group('AND');
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('toname'),
                '=',
                new midgard_query_value($package->title)
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
                echo "        package is not required by others\n";
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
                        echo '        package is in relation but "to" field is already set. relation id: ' . $relation->id . "\n";
                        continue;
                    }

                    // make sure if we update
                    // if both the related and the current package share the same architecture

                    // get the related package object
                    $related_package = new com_meego_package();
                    $related_package->get_by_id($relation->from);

                    // repo of the related package
                    $repository_b = new com_meego_repository();
                    $repository_b->get_by_id($related_package->repository);

                    if ($repository_a->arch == $repository_b->arch)
                    {
                        // we can safely update the to field of this relation
                        echo '        package is in relation with ' . $relation->from . ', update "to" field. relation id:' . $relation->id . "\n";
                        $_relation = new com_meego_package_relation($relation->id);
                        $_relation->to = $package->id;
                        $_relation->update();
                    }
                }
                unset ($relations, $_relation);
            }

            // populate all kinds of package relations to our database
            $this->addRelations($extinfo, $package);
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
            foreach ($results as $relation)
            {
                echo '        check if ' . $parent->title . ' still ' . $type . ': ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . "\n";
                foreach ($relatives as $relative)
                {
                    //echo '            Compare: ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . ' <<<---->>> ' . $relative->name . ' ' . $relative->constraint . ' ' . $relative->version . "\n";
                    if (   ! ($relation->toname == $relative->title
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
                    echo '        delete ' . $type . ' of package ' . $parent->title . ': relation guid: ' . $guid . ' (id: ' . $value . ')' . "\n";
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
            echo '        ' . $relation->relation . ': ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . "\n";
        }
        else
        {
            $_res = $relation->update();
            echo '        ' . $relation->relation . ' updated: ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . "\n";
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
     * @param string repository name, e.g meego_1.1_core_handset
     * @param strinh architecture, e.g. i586
     *
     * @return mixed repo object
     */
    private function getRepository($name, $arch)
    {
        $storage = new midgard_query_storage('com_meego_repository');

        $qc = new midgard_query_constraint_group('AND');
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('name', $storage),
            '=',
            new midgard_query_value($name)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('arch', $storage),
            '=',
            new midgard_query_value($arch)
        ));

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
     * @param string package name, e.g. cdparanoia-libs-10.2-1.1.i586.rpm
     * @param int id of the repository the package belongs to
     *
     * @return mixed package object
     */
    private function getPackage($name = null, $repository = null) {
        $storage = new midgard_query_storage('com_meego_package');

        $qc = new midgard_query_constraint_group('AND');
        if (   strlen($name)
            && $repository > 0)
        {
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('name', $storage),
                '=',
                new midgard_query_value($name)
            ));
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