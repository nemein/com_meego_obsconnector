<?php

require dirname(__FILE__).'/api.php';

if (!file_exists(dirname(__FILE__).'/config.ini')) {
    throw new RuntimeException('Please create config.ini file with "login" and "password" keys');
}
$config = parse_ini_file(dirname(__FILE__).'/config.ini');

$api = new com_meego_obsconnector_API($config['login'], $config['password']);
// $projects = $api->getProjects();
// repositories = $api->getRepositories($projects[0]));
// $packages = $api->getPackages($projects[0]);
//
// $project_name = $projects[0];
// $package_name = $packages[0];

$project_name = 'home:timoph';
$package_name = 'xournal';

$_meta = $api->getPackageMeta($project_name, $package_name);
$spec = $api->getPackageSpec($project_name, $package_name);

var_dump($_meta);
var_dump($spec);
