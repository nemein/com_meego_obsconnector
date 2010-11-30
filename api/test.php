<?php

require __DIR__.'/api.php';

if (!file_exists(__DIR__.'/config.ini')) {
    throw new RuntimeException('Please create config.ini file with "login" and "password" keys');
}
$config = parse_ini_file(__DIR__.'/config.ini');

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
$spec_fp = $api->getPackageSpec($project_name, $package_name);

var_dump($_meta);
var_dump(stream_get_contents($spec_fp));
fclose($spec_fp);
