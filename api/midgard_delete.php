<?php

$cmd = basename($argv[0]);
$inipath = php_ini_loaded_file();
$filepath = ini_get("midgard.configuration_file");

if (   ! $inipath
    || ! $filepath)
{
    echo "Please specify a valid php.ini with Midgard specific settings.\n";
    echo "Example: php -c <path-to-midgard-php-ini> $cmd ...\n";
    exit(1);
}


function help($cmd)
{
    echo "Help\n";
    echo "----\n";
    echo "$cmd -d -b package_name [-v version_number] [-p project_name]\n\n";
    echo "  Deletes the given package from the database.\n";
    echo "  If project_name and / or version_number are given then it only deletes the matching package.\n";
    echo "\n\n";

    exit;
}

function usage($cmd)
{
    echo "Usage\n";
    echo "-----\n";
    echo "  $cmd -h\n";
    echo "  $cmd -b package_name [-v version_number] [-p project_name]\n\n";
    echo "\n";

    exit;
}

$type = null;
$project = null;
$package = null;
$release_file_url = null;
$cleanonly = false;
$pos = 0;

if (count($argv) == 1)
{
    usage($cmd);
}

foreach($argv as $arg)
{
    switch ($arg)
    {
        case '-h':
            help($cmd);
            break;
        case '-b':
            if (isset($argv[$pos + 1]))
            {
                $package = $argv[$pos + 1];
            }
            break;
        case '-v':
            if (isset($argv[$pos + 1]))
            {
                $version = $argv[$pos + 1];
            }
            break;
        case '-p':
            if (isset($argv[$pos + 1]))
            {
                $project = $argv[$pos + 1];
            }
            break;
    }
    ++$pos;
}

if ($package)
{
    $config = new midgard_config();
    $config->read_file_at_path($filepath);
    $mgd = midgard_connection::get_instance();
    $mgd->open_config($config);
}
else
{
    usage($cmd);
}

