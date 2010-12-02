<?php
require __DIR__.'/api.php';
require __DIR__.'/../parser/RpmSpecParser.php';

class Fetcher
{
    private $project_name;

    public function __construct($project_name)
    {
        if (!file_exists(dirname(__FILE__).'/config.ini')) {
            throw new RuntimeException('Please create config.ini file with "login" and "password" keys');
        }

        $config = parse_ini_file(dirname(__FILE__).'/config.ini');

        $this->api = new com_meego_obsconnector_API($config['login'], $config['password']);

        $this->project_name = $project_name;
    }

    public function go()
    {
        echo "Repositories:\n";
        $repositories = $this->api->getRepositories($this->project_name);
        foreach ($repositories as $repo_name) {
            echo ' -> '.$repo_name."\n";
            foreach ($this->api->getArchitectures($this->project_name, $repo_name) as $arch_name) {
                echo '  -> '.$arch_name."\n";

                $repo = new com_meego_repository();
                $repo->name = $repo_name.'_'.$arch_name;
                $repo->title = $repo_name.' (for '.$arch_name.')';
                $repo->arch = $arch_name;
                $repo->url = '* TODO *';
                $repo->create();

                foreach ($this->api->getPackages($this->project_name, $repo_name, $arch_name) as $package_name) {
                    echo '   -> '.$package_name."\n";

                    $spec = $this->getSpec($this->project_name, $package_name);

                    $package = new com_meego_package();
                    $package->repository = $repo->id;
                    $package->name = $package_name;
                    $package->version = $spec->version;
                    $package->summary = $spec->summary;
                    $package->description = $spec->description;
                    $package->license = $spec->license;
                    $package->url = $spec->url;
                    $package->category = $this->getCategory($spec->group);
                    $package->create();

                    $this->addRelations($spec, $package);

                    $screenshot_names = array_filter(
                        $this->api->getPackageSourceFiles($this->project_name, $package_name),
                        function($name) {
                            $_marker = 'screenshot.png';
                            return strpos($name, $_marker) === (strlen($name) - strlen($_marker));
                        }
                    );

                    foreach ($screenshot_names as $name) {
                        $fp = $this->api->getPackageSourceFile($this->project_name, $package_name, $name);

                        $attachment = $package->create_attachment($name, $name, "image/png");

                        $blob = new midgard_blob($attachment);
                        $handler = $blob->get_handler();

                        fwrite($handler, stream_get_contents($fp));
                        fclose($fp);

                        fclose($handler);
                        $attachment->update();
                    }
                }
            }
        }
    }

    public function getSpec($project_name, $package_name)
    {
        static $cache = null;

        if (null === $cache) {
            $cache = array();
        }

        if (!array_key_exists($this->project_name.'_'.$package_name, $cache)) {
            $cache[$this->project_name.'_'.$package_name] = new RpmSpecParser($this->api->getPackageSpec($this->project_name, $package_name), '');
        }

        return $cache[$this->project_name.'_'.$package_name];
    }

    public function getCategory($group_string)
    {
        $prev = null;

        foreach (explode('/', $group_string) as $piece) {
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

            if (count($results) === 0) {
                $category = new com_meego_package_category();
                $category->name = $piece;
                $category->up = ($prev === null ? 0 : $prev->id);
                $category->create();

                $prev = $category;
            } else {
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
            foreach ($spec->depends as $dependency)
            {
                echo "Depends      : " . $dependency->name  . ' ' . $dependency->constraint . ' ' . $dependency->version . "\n";
            }
        }

        echo "\n";

        if (is_array($spec->buildDepends))
        {
            foreach ($spec->buildDepends as $dependency)
            {
                echo "BuildDepends : package->id: " . $package->id . ', ' . $dependency->name  . ' ' . $dependency->constraint . ' ' . $dependency->version . "\n";
                $this->createRelation('buildrequires', $dependency, $package);
            }
        }

        echo "\n";

        if (is_array($spec->provides))
        {
            foreach ($spec->provides as $provided)
            {
                echo "Provides : " . $provided->name  . ' ' . $provided->constraint . ' ' . $provided->version . "\n";
                $this->createRelation('provides', $provided, $package);
            }
        }

        echo "\n";

        if (is_array($spec->obsoletes))
        {
            foreach ($spec->obsoletes as $obsoleted)
            {
                echo "Obsoletes : " . $obsoleted->name  . ' ' . $obsoleted->constraint . ' ' . $obsoleted->version . "\n";
                $this->createRelation('obsoletes', $obsoleted, $package);
            }
        }

        echo "\n";

        if (is_array($spec->subpackages))
        {
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
     * Create a relation
     *
     * @param type of the relation: requires, buildrequires, obsoletes, conflicts, provides
     * @param relative object that is in some relation with package
     * @param parent package object
     */
    private function createRelation($type, $relative, $parent)
    {
        $relation = new com_meego_package_relation();
        $relation->relation = $type;
        $relation->from = $package->id;
        /* @todo: this might actually be $this->getCategory($dependency->group); */
        $relation->group = $package->group;
        /* @todo: this will be set later */
        // $relation-> to = null;
        $relation->toName = $dependency->name;
        $relation->version = $dependency->version;
        $relation->constraint = $dependency->constraint;
        $relation->create();
    }
}

if (count($argv) < 2)
    die("Please, specify repository name as parameter (for example: home:xfade or home:timoph)\n");

$f = new Fetcher($argv[1]);
$f->go();
