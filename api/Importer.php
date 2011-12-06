<?php

/**
 * @todo: docs
 */
abstract class Importer
{
    public $config = null;

    /**
     * @todo: docs
     */
    public function __construct()
    {
        $this->config = parse_ini_file(dirname(__FILE__) . '/config.ini');
    }

    /**
     * Implemented by child
     *
     * Goes through a project
     *
     * @param string OBS project name, e.g. home:feri
     * @param string optional; specify a concrete package to be imported
     * @param boolean optional; if true then only cleanup will be performed on the local database
     *
     */
    abstract public function go($project_name = null, $specific_package_name = null, $cleanonly = false);

    /**
     * Logging to STDOUT
     *
     * @param string the string to log
     */
    public function log($message)
    {
        if (   array_key_exists('importer_log', $this->config)
            && $this->config['importer_log'] == 1)
        {
            $message = date('Y-m-d H:i:s') . ' ' . $message . "\n";
            echo $message;
        }
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

        // check if this category is already mapped to a base category
        $q = new midgard_query_select(new midgard_query_storage('com_meego_package_category_relation'));

        $qc = new midgard_query_constraint(
            new midgard_query_property('packagecategory'),
            '=',
            new midgard_query_value($prev->id)
        );

        $q->set_constraint($qc);
        $q->execute();
        $results = $q->list_objects();

        if (! count($results))
        {
            // check if there is an "Other" basecategory
            $q = new midgard_query_select(new midgard_query_storage('com_meego_package_basecategory'));

            $qc = new midgard_query_constraint(
                new midgard_query_property('name'),
                '=',
                new midgard_query_value('Other')
            );

            $q->set_constraint($qc);
            $q->execute();
            $results = $q->list_objects();

            if (count($results))
            {
                // map this category to "Other" by default
                $relation = new com_meego_package_category_relation;
                $relation->basecategory = $results[0]->id;
                $relation->packagecategory = $prev->id;
                $relation->create();
                $this->log('           package category ' . $group_string . ' is mapped to ' . $results[0]->name);
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
    public function addRelations($extinfo = null, $package = null)
    {
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
            $this->log('           package is not required by others');
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
                    $this->log('           package is in relation but "to" field is already set. relation id: ' . $relation->id);
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
                    $this->log('           package is in relation with ' . $relation->from . ', update "to" field. relation id:' . $relation->id);
                    $_relation = new com_meego_package_relation($relation->guid);
                    $_relation->to = $package->id;
                    $_relation->update();
                }
            }
            unset ($relations, $_relation);
        }

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
    public function cleanRelations($type, $relatives, $parent)
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
                $this->log('           check if ' . $parent->name . ' still ' . $type . ': ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version);
                foreach ($relatives as $relative)
                {
                    //$this->log('            Compare: ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version . ' <<<---->>> ' . $relative->name . ' ' . $relative->constraint . ' ' . $relative->version);
                    if (   ! ($relation->toname == $relative->name
                        && $relation->constraint == $relative->constraint
                        && $relation->version == $relative->version ))
                    {
                        //$this->log('            mark deleted ' . $relation->id);
                        $_deleted[$relation->guid] = $relation->id;
                    }
                    else
                    {
                        //$this->log('            mark kept: ' . $relation->id);
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
                    $this->log('           delete ' . $type . ' of package ' . $parent->name . ': relation guid: ' . $guid . ' (id: ' . $value . ')');
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
    public function createRelation($type, $relative, $parent)
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
        $q->toggle_readonly(false);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            $relation = array_shift($results);
        }
        else
        {
            $relation = new com_meego_package_relation();
            $relation->from = $parent->id;
            $relation->relation = $type;
            $relation->toname = $relative->name;

            // check if the relative has already been imported
            // if yes, then set relation->to to the relative's ID
            $_package = $this->getPackageByTitle($relative->title, $parent->repository);

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
            $this->log('           ' . $relation->relation . ': ' . $relation->toname . ' (package id: ' . $relation->to . ') ' . $relation->constraint . ' ' . $relation->version);
        }
        else
        {
            $_res = $relation->update();
            $this->log('           ' . $relation->relation . ' updated: ' . $relation->toname . ' ' . $relation->constraint . ' ' . $relation->version);
        }

        if ($_res != 'MGD_ERR_OK')
        {
            $_mc = midgard_connection::get_instance();
            $this->log('Error received from midgard_connection: ' . $_mc->get_error_string());
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
    public function getProject($name = null)
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
        $q->toggle_readonly(false);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            $project = array_shift($results);
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
    public function getRepository($name, $arch, $project_id)
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
        $q->toggle_readonly(false);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            $repository = array_shift($results);
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
    public function getPackageByFileName($filename = null, $repository = null)
    {
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
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('metadata.hidden'),
                '=',
                new midgard_query_value(0)
            ));
        }

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->toggle_readonly(false);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            // one repo should only have 1 instance from the same filename (as it also has the version number)
            $package = array_shift($results);

            // we set all other packages hidden
            foreach($results as $result)
            {
                $result->metadata->hidden = true;
                $ret = $result->update();
                if ($ret)
                {
                    $this->log('           extra package instance ' . $result->guid . ' set to hidden');
                }
            }
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
    public function getPackageByTitle($title = null, $repository = null)
    {
        $storage = new midgard_query_storage('com_meego_package');

        $qc = new midgard_query_constraint_group('AND');

        if (   strlen($title)
            && $repository > 0)
        {
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('title'),
                '=',
                new midgard_query_value($title)
            ));
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('repository'),
                '=',
                new midgard_query_value($repository)
            ));
        }

        $q = new midgard_query_select($storage);
        if (isset($qc))
        {
            $q->set_constraint($qc);
        }
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
     * Checks if a license already exists in the database
     * If the license exists then it returns its object
     * Otherwise it returns a blank com_meego_license object
     *
     * @param string license name, e.g BSD
     * @param string license title or pretty name, e.g BSD License
     * @param string url to the license
     *
     * @return mixed com_meego_license object
     */
    public function getLicense($name, $title = '', $url = '')
    {
        $storage = new midgard_query_storage('com_meego_license');

        $qc = new midgard_query_constraint(
            new midgard_query_property('name'),
            '=',
            new midgard_query_value($name)
        );

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            $license = new com_meego_license($results[0]->guid);
        }
        else
        {
            $license = new com_meego_license();
            $license->name = $name;
            $license->title = $title;
            $license->url = $url;

            $license->create();
        }

        // @todo: could do a safety check if the new objext was really created
        //        throw an exception if not
        return $license->id;
    }

    /**
     * Checks if an OS version already exists in the database
     * If the OS version exists then it returns its object
     * Otherwise it returns a blank com_meego_os object
     *
     * @param string OS name, e.g meego (all lowercase)
     * @param string OS version, e.g 1.2
     * @param string OS architecture, e.g armv7el
     * @param string OS homepage URL
     *
     * @return mixed com_meego_os object
     */
    public function getOS($name, $version, $arch, $url = '')
    {
        $storage = new midgard_query_storage('com_meego_os');

        $qc = new midgard_query_constraint_group('AND');

        if (   strlen($name)
            && strlen($version))
        {
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('name'),
                '=',
                new midgard_query_value($name)
            ));
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('version'),
                '=',
                new midgard_query_value($version)
            ));
            $qc->add_constraint(new midgard_query_constraint(
                new midgard_query_property('arch'),
                '=',
                new midgard_query_value($arch)
            ));
        }

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            $os = new com_meego_os($results[0]->guid);
        }
        else
        {
            $os = new com_meego_os();
            $os->name = strtolower($name);
            $os->version = $version;
            $os->arch = $arch;
            $os->url = $url;

            $os->create();
        }

        // @todo: could do a safety check if the new objext was really created
        //        throw an exception if not
        return $os->id;
    }

    /**
     * Checks if a UX already exists in the database
     * If the UX exists then it returns its object
     * Otherwise it returns a blank com_meego_ux object
     *
     * @param string UX name, e.g netbook, ivi (all lowercase)
     * @param string UX homepage URL
     *
     * @return mixed com_meego_ux object
     */
    public function getUX($name, $url = '')
    {
        $storage = new midgard_query_storage('com_meego_ux');

        $qc = new midgard_query_constraint(
            new midgard_query_property('name'),
            '=',
            new midgard_query_value($name)
        );

        $q = new midgard_query_select($storage);
        $q->set_constraint($qc);
        $q->execute();

        $results = $q->list_objects();

        if (count($results))
        {
            $ux = new com_meego_ux($results[0]->guid);
        }
        else
        {
            $ux = new com_meego_ux();
            $ux->name = $name;
            $ux->url = $url;

            $ux->create();
        }

        // @todo: could do a safety check if the new objext was really created
        //        throw an exception if not
        return $ux->id;
    }

    /**
     * Deletes all relations a package is involved in
     *
     * @param integer id of the package
     * @return boolean true if all relations are deleted, false otherwise
     */
    public function deleteRelations($id)
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
            $_mc = midgard_connection::get_instance();

            foreach ($results as $object)
            {
                $relation = new com_meego_package_relation($object->guid);

                if (   is_object($relation)
                    && $relation->purge())
                {
                    $log = '              deleted relation: ';
                }
                else
                {
                    $log = '              failed to delete relation: ';
                    $retval = false;
                }
                $this->log($log . $relation->id . ' (from: ' . $relation->from . ', to: ' . $relation->to . ')');

                if (! $retval)
                {
                    $this->log($_mc->get_error_string());
                    break;
                }
            }
        }

        return $retval;
    }

    /**
     * Deletes a packages from database
     * Used only if during an update we notice that a package is no longer available in a repositor
     *
     * @param object com_meego_package object
     * @param string the project tha package belongs to
     *               needed to remove the QA file that are created by the Apps web app
     *
     * @return boolean true if operation succeeded, false otherwise
     */
    public function deletePackage($package, $project_name = '')
    {
        $retval = false;
        //$object = new com_meego_package($guid);

        if (   is_object($package)
            && ! $package->metadata->hidden)
        {
            // we hide objects instead of deleting them - 2011-11-23; ferenc

            $package->metadata->hidden = true;
            $retval = $package->update();

            if ($retval)
            {
                $log = '              hidden: ';
            }
            else
            {
                $log = '              failed to hide package: ';
                $_mc = midgard_connection::get_instance();
                $this->log($_mc->get_error_string());
            }

            $this->log($log . $package->filename . ' (' . $package->guid . ')');

            if ($retval)
            {
                $qa_file = $this->config['qa_path'] . '/' . $project_name . '/' . $package->parent . '.txt';

                $this->log('              looking for QA file: ' . $qa_file);

                if (is_file($qa_file))
                {
                    $ret = unlink($qa_file);
                    if ($ret)
                    {
                        $this->log('              removed QA file:     ' . $qa_file);
                    }
                }
            }
        }

        return $retval;
    }

    /**
     * Cleans packages from the database if they are
     * no longer part of the OBS repository
     *
     * @param object repository object from our database
     * @param array of binaries that are currently part of the OBS repository
     * @param string if given then only this package will be cleaned up
     *
     */
    public function cleanPackages($repo = null, $newlist = array(), $specific_package_name = null)
    {
        $found = false;

        if ($specific_package_name)
        {
            $this->log('        -> cleanup package: ' . $specific_package_name . ' in repo: ' . $repo->name);
        }
        else
        {
            $this->log('     -> cleanup repo: ' . $repo->name . ' (id: ' . $repo->id . '; OS: ' . $repo->os . ', OS version ID: ' . $repo->osversion . ', OS group: ' . $repo->osgroup . ', UX: ' . $repo->osux . ')');
        }

        $storage = new midgard_query_storage('com_meego_package');
        $q = new midgard_query_select($storage);

        $qc = new midgard_query_constraint_group('AND');

        $constraints[] = new midgard_query_constraint(
            new midgard_query_property('repository'),
            '=',
            new midgard_query_value($repo->id)
        );
        $constraints[] = new midgard_query_constraint(
            new midgard_query_property('metadata.hidden'),
            '=',
            new midgard_query_value(0)
        );

        if (! is_null($specific_package_name))
        {
            $qc1 = new midgard_query_constraint_group('OR');
            $qc1->add_constraint(new midgard_query_constraint(
                new midgard_query_property('name'),
                '=',
                new midgard_query_value($specific_package_name)
            ));
            $qc1->add_constraint(new midgard_query_constraint(
                new midgard_query_property('parent'),
                '=',
                new midgard_query_value($specific_package_name)
            ));
            $constraints[] = $qc1;
        }

        foreach($constraints as $constraint)
        {
            $qc->add_constraint($constraint);
        }

        $q->set_constraint($qc);
        $q->toggle_readonly(false);
        $q->execute();

        $oldpackages = $q->list_objects();

        foreach ($oldpackages as $oldpackage)
        {
            if (array_search($oldpackage->filename, $newlist) === false)
            {
                $found = true;
                // the package is not in the list, so remove it from db
                $project = new com_meego_project($repo->project);
                $retval = $this->deletePackage($oldpackage, $project->name);
                unset($project);
            }
        }

        if (! $found)
        {
            $this->log('           no cleanup was necessary');
        }
    }

    /**
     * Creates a role object for the package
     */
    public function createRole($package_guid, $userid, $role)
    {
        $storage = new midgard_query_storage('midgard_user');
        $q = new midgard_query_select($storage);

        $q->set_constraint(new midgard_query_constraint (
            new midgard_query_property('login'),
            '=',
            new midgard_query_value($userid)
        ));

        $q->toggle_readonly(false);
        $q->execute();

        $users = $q->list_objects();

        if (count($users))
        {
            $user = $users[0];
        }
        else
        {
            $user = $this->createUser($userid);
        }

        // check if this role is already set
        midgard_error::info(__CLASS__ . ' Check if role exists: (' . $package_guid . ', ' . $userid . ', ' . $role . ')');

        $storage = new midgard_query_storage('com_meego_package_role');
        $q = new midgard_query_select($storage);

        $qc = new midgard_query_constraint_group('AND');

        $qc->add_constraint(new midgard_query_constraint (
            new midgard_query_property('package'),
            '=',
            new midgard_query_value($package_guid)
        ));
        $qc->add_constraint(new midgard_query_constraint (
            new midgard_query_property('user'),
            '=',
            new midgard_query_value($user->guid)
        ));
        $qc->add_constraint(new midgard_query_constraint (
            new midgard_query_property('role'),
            '=',
            new midgard_query_value($role)
        ));

        $q->set_constraint($qc);
        $q->execute();

        $roles = $q->list_objects();

        if (! count($roles))
        {
            $role_obj = new com_meego_package_role();
            $role_obj->package = $package_guid;
            $role_obj->user = $user->guid;
            $role_obj->role = $role;

            if (! $role_obj->create())
            {
                $error = midgard_connection::get_instance()->get_error_string();
                midgard_error::error(__CLASS__ . " Creating role object failed: " . $error);
            }
            midgard_error::info(__CLASS__ . " Creating role object succeeded: " . $role_obj->guid);
        }
        else
        {
            midgard_error::info(__CLASS__ . " Role object already exists: " . $roles[0]->guid);
        }
    }

    /**
     * Creates and returns a midgard_person object
     *
     */
    private function createUser($login)
    {
        # create the person object
        $person = new midgard_person();

        $person->firstname = 'imported';
        $person->lastname = 'user';

        if ( ! $person->create() )
        {
            $error = midgard_connection::get_instance()->get_error_string();
            midgard_error::error(__CLASS__ . " Failed to create midgard person: " . $error);
            return false;
        }
        else
        {
            midgard_error::info(__CLASS__ . " Created midgard person: " . $person->guid);

            $user = new midgard_user();
            $user->login = $login;
            $user->password = '';
            $user->usertype = 1;

            $user->authtype = ($this->config['default_auth_type']) ? $this->config['default_auth_type'] : 'SHA1';
            $user->active = true;
            $user->set_person($person);

            if ( ! $user->create() )
            {
                $error = midgard_connection::get_instance()->get_error_string();
                midgard_error::error(__CLASS__ . "Failed to create midgard user: " . $error);
                return false;
            }

            midgard_error::info(__CLASS__ . " Created midgard user: " . $user->login);
        }

        // @todo: not sure if this is the best solution;
        // but it is simple to create midgardmvc_account objects

        // this does not work, as we are not an MVC app
        /*
            $dummy_session = new midgardmvc_core_login_session();
            $dummy_session->userid = '';
            $dummy_session->username = $user->login;
            $dummy_session->authtype = $user->authtype;
            midgardmvc_account_injector::create_account_from_session($dummy_session);
            unset($dummy_session);
        */
        return $user;
    }

    /**
     * Generate a short summary from a longer text
     * Implemented by Henri Bergius
     * @param string original string
     * @param int max lengths in bytes
     * @return string the new string
     */
    public function generateAbstract($string, $maxlength = 100)
    {
        $string = strip_tags($string);

        if (mb_strlen($string) <= $maxlength)
        {
            return $string;
        }

        $buffer = $maxlength * 0.1;
        $string = substr($string, 0, $maxlength + $buffer);

        $last_period = mb_strrpos($string, '.');
        if (   $last_period !== false
            && $last_period > ($maxlength * 0.8))
        {
            // Found a period in the last 20% of string, go with it.
            return mb_substr($string, 0, $last_period + 1);
        }

        $last_space = mb_strrpos($string, ' ');
        return mb_substr($string, 0, $last_space);
    }
}
?>