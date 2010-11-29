<?php

require dirname(__FILE__).'/api.php';

if (!file_exists(dirname(__FILE__).'/config.ini')) {
    throw new RuntimeException('Please create config.ini file with "login" and "password" keys');
}
$config = parse_ini_file(dirname(__FILE__).'/config.ini');

$api = new com_meego_obsconnector_API($config['login'], $config['password']);
$projects = $api->getProjects();
// var_dump($api->getRepositories($projects[0]));

$packages = $api->getPackages($projects[0]);

$_meta = $api->getPackageMeta($projects[0], $packages[0]);
var_dump($_meta['owners']);

var_dump($api->getPackageSpec($projects[0], $packages[0]));
