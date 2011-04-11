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
    echo "$cmd -type obs [cleanonly]\n\n";
    echo "  Scans the entire OBS repository tree and imports all repositories from all the projects.";
    echo "  If cleanonly is specified then it will only clean the current database, meaning that\n";
    echo "  o no new projects, repositories and packages will be scanned and imported\n";
    echo "  o current projects, repositories and packages in the database will be checked and removed if not existing in OBS anymore";
    echo "\n\n";
    echo "$cmd -type obs project_name [cleanonly]\n\n";
    echo "  The same as above, except it only imports (or checks) repositories of a given OSB project.";
    echo "\n\n";
    echo "$cmd -type debian release_file_url [cleanonly]\n\n";
    echo "  Imports packages from a raw Debian repository by specifying a concrete release file.\n";
    echo "  Note: Debian release files are architecture specific.\n";
    echo "  If you want to import multiple architectures then run the importer for each of them release separately.\n";
}

function usage($cmd)
{
    echo "Usage\n";
    echo "-----\n";
    echo "  $cmd -h\n";
    echo "  $cmd -t obs [cleanonly]\n";
    echo "  $cmd -t obs project_name [cleanonly]\n";
    echo "  $cmd -t debian release_file_url [cleanonly]\n";
    echo "\n";
}

switch ($argv[1])
{
    case '-h':
        help($cmd);
        break;
    case '-t':
        $config = new midgard_config ();

        $config->read_file_at_path($filepath);
        $mgd = midgard_connection::get_instance();
        $mgd->open_config($config);
        switch ($argv[2])
        {
            case 'obs':

                require __DIR__.'/Fetcher.php';
                $f  = new Fetcher();

                if (isset($argv[3]))
                {
                    if ($argv[3] == "cleanonly")
                    {
                        $f->scan_all_projects(true);
                    }
                    else
                    {
                        if (    isset($argv[4])
                            &&  $argv[4] == "cleanonly")
                        {
                            $f->go($argv[3], true);
                        }
                        else
                        {
                            $f->go($argv[3], false);
                        }
                    }
                }
                else
                {
                    $f->scan_all_projects(false);
                }
                break;
            case 'debian':
                break;
            default:
                usage($cmd);
        }
        break;
    default:
        usage($cmd);
}
