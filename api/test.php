<?php

require __DIR__.'/api.php';

use com\meego\obsconnector as obs;

$api = new obs\API;
$projects = $api->getProjects();
// $repos = $api->getRepositories($projects[0]);
// var_dump($repos);
$packages = $api->getPackages($projects[0]);
$files = $api->getPackageSourceFiles($projects[0], $packages[0]);
foreach ($files as $file) {
    if (strpos($file, '.spec') !== false) {
        var_dump($api->getPackageSourceFile($projects[0], $packages[0], $file));
    }
}
