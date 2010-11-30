<?php
require __DIR__.'/api.php';
require __DIR__.'/../parser/RpmSpecParser.php';

class Fetcher
{
    public function __construct()
    {
        if (!file_exists(dirname(__FILE__).'/config.ini')) {
            throw new RuntimeException('Please create config.ini file with "login" and "password" keys');
        }

        $config = parse_ini_file(dirname(__FILE__).'/config.ini');

        $this->api = new com_meego_obsconnector_API($config['login'], $config['password']);
    }

    public function go()
    {
        $project_name = 'home:timoph';

        echo "Repositories:\n";
        $repositories = $this->api->getRepositories($project_name);
        foreach ($repositories as $repo_name) {
            echo ' -> '.$repo_name."\n";
            foreach ($this->api->getArchitectures($project_name, $repo_name) as $arch_name) {
                echo '  -> '.$arch_name."\n";

                $repo = new com_meego_repository();
                $repo->name = $repo_name.'_'.$arch_name;
                $repo->title = $repo_name.' (for '.$arch_name.')';
                $repo->arch = $arch_name;
                $repo->url = '* TODO *';
                $repo->create();

                foreach ($this->api->getPackages($project_name, $repo_name, $arch_name) as $package_name) {
                    echo '   -> '.$package_name."\n";

                    $spec = $this->getSpec($project_name, $package_name);

                    $package = new com_meego_package();
                    $package->repository = $repo->id;
                    $package->name = $package_name;
                    $package->version = $spec->version;
                    $package->summary = $spec->summary;
                    $package->description = $spec->description;
                    $package->category = $spec->group;
                    $package->license = $spec->license;
                    $package->url = $spec->url;
                    $package->create();
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

        if (!array_key_exists($project_name.'_'.$package_name, $cache)) {
            $cache[$project_name.'_'.$package_name] = new RpmSpecParser($this->api->getPackageSpec($project_name, $package_name), '');
        }

        return $cache[$project_name.'_'.$package_name];
    }
}

$f = new Fetcher();
$f->go();
