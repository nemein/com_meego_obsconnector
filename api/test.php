<?php

require __DIR__.'/api.php';

use com\meego\obsconnector as obs;

$api = new obs\API;
$projects = $api->getProjects();
// var_dump($api->getRepositories($projects[0]));

$packages = $api->getPackages($projects[0]);

$_meta = $api->getPackageMeta($projects[0], $packages[0]);
var_dump($_meta['owners']);

var_dump($api->getPackageSpec($projects[0], $packages[0]));
