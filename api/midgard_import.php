<?php
require __DIR__.'/api.php';
require __DIR__.'/../parser/RpmSpecParser.php';

/**
 * @todo: docs
 */
class Fetcher
{
    private $project_name = null;
    private $package_counter = 0;

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
            echo ' -> '.$repo_name."\n";

            //var_dump($project_meta['repositories'][$repo_name]);

            foreach ($this->api->getArchitectures($project_name, $repo_name) as $arch_name) {
                echo '  -> '.$arch_name."\n";

                $repo = $this->getRepository($repo_name . '_' . $arch_name);

                $repo->name = $repo_name . '_' . $arch_name;
                $repo->title = $repo_name . ' (for ' . $arch_name . ')';
                $repo->arch = $arch_name;
                $repo->url = '* TODO *';

                $repo->os = $project_meta['repositories'][$repo_name]['os'];
                $repo->osversion = $project_meta['repositories'][$repo_name]['osversion'];
                $repo->osgroup = $project_meta['repositories'][$repo_name]['osgroup'];
                $repo->osux = $project_meta['repositories'][$repo_name]['osux'];

                if ( ! $repo->guid ) {
                    echo '     create: ' . $repo->name;
                    $repo->create();
                } else {
                    echo '     update: ' . $repo->name;
                    $repo->update();
                }
                echo ' (' . $repo->os . ' ' . $repo->osversion . ', ' . $repo->osgroup . ', ' . $repo->osux . ")\n";

                foreach ($this->api->getPackages($project_name, $repo_name, $arch_name) as $package_name) {
                    echo '   -> ' . ++$this->package_counter . ': ' . $package_name."\n";

                    try {
                        $spec = $this->getSpec($project_name, $package_name);
                    } catch (RuntimeException $e) {
                        echo "\n         [EXCEPTION: " . $e->getMessage()."]\n\n";
                        continue;
                    }

                    $package = $this->getPackage($package_name, $spec->version, $repo->id);

                    $package->repository = $repo->id;
                    $package->name = $package_name;
                    $package->version = $spec->version;
                    $package->summary = $spec->summary;
                    $package->description = $spec->description;
                    $package->license = $spec->license;
                    $package->url = $spec->url;
                    $package->category = $this->getCategory($spec->group);

                    if ( ! $package->guid ) {
                        echo '        create: ' . $package->name . "\n";
                        $package->create();
                    } else {
                        echo '        update: ' . $package->name . "\n";
                        $package->update();
                    }

                    $this->addRelations($spec, $package);

                    $screenshot_names = array_filter(
                        $this->api->getPackageSourceFiles($project_name, $package_name),
                        function($name) {
                            $_marker = 'screenshot.png';
                            return strpos($name, $_marker) === (strlen($name) - strlen($_marker));
                        }
                    );

                    foreach ($screenshot_names as $name) {
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

        if (null === $cache) {
            $cache = array();
        }

        if (!array_key_exists($project_name . '_' . $package_name, $cache)) {
            $spec_stream = $this->api->getPackageSpec($project_name, $package_name);

            if (false === $spec_stream) {
                throw new RuntimeException("couldn't get spec-file");
            }

            $cache[$project_name . '_' . $package_name] = new RpmSpecParser($spec_stream, '');
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
     * - runtime dependency packages
     * - build dependency packages
     * - provided packages
     * - obsoleted packages
     * - conflicting packages
     *
     * @param spec RpmSpecParse object
     * @param spec Package object
     *
     */
    private function addRelations($spec, $package)
    {
        if (is_array($spec->depends))
        {
            /* delete relations that are no longer needed */
            $this->cleanRelations('requires', $spec->depends, $package);
            foreach ($spec->depends as $dependency)
            {
                $this->createRelation('requires', $dependency, $package);
            }
        }

        if (is_array($spec->buildDepends))
        {
            /* delete relations that are no longer needed */
            $this->cleanRelations('buildrequires', $spec->buildDepends, $package);
            foreach ($spec->buildDepends as $dependency)
            {
                $this->createRelation('buildrequires', $dependency, $package);
            }
        }

        if (is_array($spec->provides))
        {
            /* delete relations that are no longer needed */
            $this->cleanRelations('provides', $spec->provides, $package);
            foreach ($spec->provides as $provided)
            {
                $this->createRelation('provides', $provided, $package);
            }
        }

        if (is_array($spec->obsoletes))
        {
            /* delete relations that are no longer needed */
            $this->cleanRelations('obsoletes', $spec->obsoletes, $package);
            foreach ($spec->obsoletes as $obsoleted)
            {
                $this->createRelation('obsoletes', $obsoleted, $package);
            }
        }

        if (is_array($spec->subpackages))
        {
            echo "        TODO: subpackages are not yet loaded to database!\n";
            foreach ($spec->subpackages as $subpackage)
            {
                echo "Subpackage: " . $subpackage->name . "\n";
                foreach ($subpackage as $key => $value)
                {
                    if (   $key == 'depends'
                        || $key == 'buildDepends'
                        || $key == 'provides'
                        || $key == 'conflicts'
                        || $key == 'obsoletes')
                    {
                        foreach ($value as $stuff)
                        {
                            echo ucfirst($key) . ': ' . $stuff->name  . ' ' . $stuff->constraint . ' ' . $stuff->version . "\n";
                        }

                    }
                    else
                    {
                        echo ucfirst($key) . ': ' . trim($value) . "\n";
                    }

                }
                echo "\n";
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
                    //echo '        Compare: ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . ' <<<---->>> ' . $relative->name . ' ' . $relative->constraint . ' ' . $relative->version . "\n";
                    if (   ! ($relation->toname == $relative->name
                        && $relation->constraint == $relative->constraint
                        && $relation->version == $relative->version )) {
                        //echo '        mark deleted ' . $relation->id . "\n";
                        $_deleted[$relation->guid] = $relation->id;
                    } else {
                        //echo '        mark kept: ' . $relation->id . "\n";
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
            $relation = new com_meego_package_relation();
            $relation->from = $parent->id;
            $relation->relation = $type;
            $relation->toname = $relative->name;

            /* @todo: this will be set later */
            // $relation->to = null;

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
     * @param string package name
     * @param string package version
     * @return mixed package object
     */
    private function getPackage($name = null, $version = null, $repository = null) {
        $storage = new midgard_query_storage('com_meego_package');

        $qc = new midgard_query_constraint_group('AND');
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('name', $storage),
            '=',
            new midgard_query_value($name)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('version', $storage),
            '=',
            new midgard_query_value($version)
        ));
        $qc->add_constraint(new midgard_query_constraint(
            new midgard_query_property('repository', $storage),
            '=',
            new midgard_query_value($repository)
        ));

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

$f = new Fetcher();
if ($argv[1])
{
    $f->go($argv[1]);
}
else
{
    $f->scan_all_projects();
}