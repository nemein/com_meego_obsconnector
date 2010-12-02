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
}

if (count($argv) < 2)
    die("Please, specify repository name as parameter (for example: home:xfade or home:timoph)\n");

$f = new Fetcher($argv[1]);
$f->go();
