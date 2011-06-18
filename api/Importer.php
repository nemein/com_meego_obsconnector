<?php

/**
 * @todo: docs
 */
abstract class Importer
{
    private $config = null;

    /**
     * @todo: docs
     */
    public function __construct()
    {
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
    public function getPackageByFileName($filename = null, $repository = null) {
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
     * @param string OS homepage URL
     *
     * @return mixed com_meego_os object
     */
    public function getOS($name, $version, $url = '')
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
    public function deletePackage($package)
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
    public function cleanPackages($repo = null, $newlist = array())
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

?>
