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
    echo "$cmd -t obs [-p project_name [-b package_name]] [-c | --cleanonly]\n\n";
    echo "  Scans the entire OBS repository tree and imports all repositories from all the projects.";
    echo "  If project_name is given then it only imports repostories from the given project\n";
    echo "  If package_name is given then it only imports that particular package from all repositories of the given project\n";
    echo "  If cleanonly is specified then it will only clean the current database, meaning that\n";
    echo "  o no new projects, repositories and packages will be scanned and imported\n";
    echo "  o current projects, repositories and packages in the database will be checked and removed if not existing in OBS anymore";
    echo "\n\n";
    echo "$cmd -t debian -r release_file_url [-c | --cleanonly]\n\n";
    echo "  Imports packages from a raw Debian repository by specifying a concrete release file.\n";
    echo "  Note: Debian release files are architecture specific.\n";
    echo "  If you want to import multiple architectures then run the importer for each of them release separately.\n";

    exit;
}

function usage($cmd)
{
    echo "Usage\n";
    echo "-----\n";
    echo "  $cmd -h\n";
    echo "  $cmd -t obs [-p project_name [-b package_name]] [-c | --cleanonly]\n";
    echo "  $cmd -t debian -r release_file_url [-c | --cleanonly]\n";
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
        case '-t':
            if (isset($argv[$pos + 1]))
            {
                $type = $argv[$pos + 1];
            }
            break;
        case '-p':
            if (isset($argv[$pos + 1]))
            {
                $project = $argv[$pos + 1];
            }
            break;
        case '-b':
            if (isset($argv[$pos + 1]))
            {
                $package = $argv[$pos + 1];
            }
            break;
        case '-r':
            if (isset($argv[$pos + 1]))
            {
                $release_file_url = $argv[$pos + 1];
            }
            break;
        case '-c':
        case '--cleanonly':
            $cleanonly = true;
            break;
    }
    ++$pos;
}

if ($type)
{
    if (     $type == 'debian'
        && ! isset($release_file_url))
    {
        help($cmd);
    }
    $config = new midgard_config();
    $config->read_file_at_path($filepath);
    $mgd = midgard_connection::get_instance();
    $mgd->open_config($config);
}
else
{
    help($cmd);
}

switch ($type)
{
    case 'obs':
        require __DIR__.'/OBSFetcher.php';
        $f  = new OBSFetcher();

        if (is_null($project))
        {
            // scan all projects
            $f->scan_all_projects($cleanonly);
        }
        else
        {
            // use the given project only
            $f->go($project, $package, $cleanonly);
        }

        break;
    case 'debian':
        require __DIR__.'/DebianRepositoryFetcher.php';
        $df  = new DebianRepositoryFetcher();

        $df->go($release_file_url, $cleanonly);

        break;
    default:
        usage($cmd);
}
