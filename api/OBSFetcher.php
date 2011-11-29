<?php
require __DIR__.'/api.php';
require __DIR__ . '/Importer.php';
require __DIR__.'/../parser/RpmXray.php';
require __DIR__.'/../parser/DebXray.php';
//require __DIR__.'/../parser/RpmSpecParser.php';

/**
 * @todo: docs
 */
class OBSFetcher extends Importer
{
    private $debug = false;

    private $project_name = null;
    private $package_counter = 0;
    private $build_counter = 0;

    // @todo: move to this standard component configuration file
    private $download_repo_protocol = 'http';
    private $download_repo_host = 'repo.pub.meego.com';

    /**
     * @todo: docs
     */
    public function __construct()
    {
        parent::__construct();

        if ( ! file_exists(dirname(__FILE__) . '/config.ini') )
        {
            // for now we bail out if there is no config.ini with login and password details
            throw new RuntimeException('Please create config.ini file with "apihost", "apiprotocol", "repohost", "repoprotocol", "login", "password", and optionally "wget" set to true if you want to use wget for HTTP operations');
        }
        else
        {
            $this->api = new com_meego_obsconnector_API($this->config['apiprotocol'], $this->config['apihost'], $this->config['login'], $this->config['password'], $this->config['wget'], $this->config['wget_options']);
            $this->download_repo_host = $this->config['repohost'];
            $this->download_repo_protocol = $this->config['repoprotocol'];
        }
    }

    /**
     * If no argument (ie. project name) is given when running this script
     * then we go through all available projects
     */
    public function scan_all_projects($cleanonly = false)
    {
        $i = 0;

        // get all published projects
        try
        {
            $projects = $this->api->getPublishedProjects();
        }
        catch (RuntimeException $e)
        {
            $this->log('         [EXCEPTION] ' . $e->getMessage());
        }

        // iterate through each project to get all its repositories and
        // eventually all available packages within the repositories
        foreach ($projects as $project_name)
        {
            $this->log('#' . ++$i . ' Project: ' . $project_name);

            if ($cleanonly)
            {
                $this->log('Requested clean up only');
            }

            $this->log('---------------------------------------------------------');

            $this->go($project_name, null, $cleanonly);
        }
    }

    /**
     * Implemented by child
     *
     * Goes through a project
     *
     * @param string OBS project name, e.g. home:feri
     * @param string optional; specify a concrete package to be imported
     * @param boolean optional; if true then only cleanup will be performed on the local database
     *                otherwise full import happens
     *
     */
    public function go($project_name = null, $specific_package_name = null, $cleanonly = false)
    {
        if (is_null($project_name))
        {
            // no project given for go
            throw new RuntimeException('Please specify a valid project');
        }

        // check if the project is already recorded in our database
        $project = $this->getProject($project_name);

        try
        {
            // get meta info about a project. this info consists of the following:
            // project name, title, description,
            // people involved,
            // repositories (published and non-published)
            $project_meta = $this->api->getProjectMeta($project_name);
        }
        catch (RuntimeException $e)
        {
            if ($e->getCode() == 990)
            {
                // failed to fetch project meta, bail out
                $this->log("Failed to fetch meta information of project: $project_name");
                $this->log("The project may not exist anymore");

                if ($project->guid)
                {
                    // the project is in our database; so let's delete it
                    // @todo: $this->deleteProject($project);
                }

                exit(1);
            }
        }

        // set properties
        $project->name = trim($project_meta['name']);
        $project->title = trim($project_meta['title']);
        $project->description = trim($project_meta['description']);

        if (! $cleanonly)
        {
            if ($project->guid)
            {
                $log = 'Update project record: ' . $project->name;
                $project->update();
            }
            else
            {
                $log = 'Create project record: ' . $project->name;
                $project->create();
            }
            $this->log($log . ' (' . $project->title . ', ' . $project->description . ')');
        }

        if ($project->id)
        {
            // get all repositories this project has published
            $this->log('Proceed with repositories of project: ' . $project->name);

            try
            {
                $repositories = $this->api->getPublishedRepositories($project->name);
            }
            catch (RuntimeException $e)
            {
                $this->log('[EXCEPTION] ' . $e->getMessage());
            }

            if (! count($repositories))
            {
                // clean on local records
                $this->log('[TODO] No repositories in OBS. Cleaning up local ones.');
                exit;
            }

            // iterate through each and every published repository
            // and dig out the packages
            foreach ($repositories as $repo_name)
            {
                $this->log('  ' . $repo_name);

                // work on allowed OSes only
                $repo->os = $project_meta['repositories'][$repo_name]['os'];

                if (   is_array($this->config['os_map'])
                    && array_search($repo->os, $this->config['os_map']) === false)
                {
                    $this->log('  skipped due to wrong OS: ' . $repo->os);
                    continue;
                }

                try
                {
                    $architectures = $this->api->getBuildArchitectures($project->name, $repo_name);
                }
                catch (RuntimeException $e)
                {
                    $this->log('  [EXCEPTION] ' . $e->getMessage());
                }

                if ( ! count($architectures) )
                {
                    continue;
                }

                // get all available architectures within this repository
                foreach ($architectures as $arch_name)
                {
                    $this->log('  -> ' . $arch_name);

                    // get a com_meego_repository object
                    $repo = $this->getRepository($repo_name, $arch_name, $project->id);

                    // fill in properties of the repo object
                    $repo->name = $repo_name;
                    $repo->project = $project->id;

                    $repo->arch = $arch_name;
                    $repo->title = $repo_name . ' (for ' . $arch_name . ')';

                    $repo->os = $project_meta['repositories'][$repo_name]['os'];

                    // @todo: we could supply an URL for the OS if available (see last parameter)
                    $repo->osversion = $this->getOS($repo->os, $project_meta['repositories'][$repo_name]['osversion'], $repo->arch, '');

                    $repo->osgroup = $project_meta['repositories'][$repo_name]['osgroup'];
                    $repo->osux = $this->getUX($project_meta['repositories'][$repo_name]['osux'], '');

                    try
                    {
                        $builtpackages = $this->api->getBuiltPackages($project->name, $repo_name, $arch_name);
                    }
                    catch (RuntimeException $e)
                    {
                        $this->log('     [EXCEPTION] ' . $e->getMessage());
                    }

                    if ( ! count($builtpackages))
                    {
                        if ($repo->guid)
                        {
                            // clean up the local repo, since it is no longer there in OBS
                            $this->log('     No built packages in OBS, clean this local repository.');
                        }
                    }

                    if (! $cleanonly)
                    {
                        if (count($builtpackages))
                        {
                            if ($repo->guid)
                            {
                                $log = '     update: ';
                                $repo->update();
                            }
                            else
                            {
                                $log = '     create: ';
                                $repo->create();
                            }
                            $this->log($log . $repo->name . ' (id: ' . $repo->id . '; OS: ' . $repo->os . ', OS version id: ' . $repo->osversion . ', OS group: ' . $repo->osgroup . ', UX id: ' . $repo->osux . ', OS arch: ' . $repo->arch . ')');
                        }
                    }

                    $cleanup = true;
                    $fulllist = array();

                    foreach ($builtpackages as $package_name)
                    {
                        // check if a specific package should be imported
                        if (   ! is_null($specific_package_name)
                            && $package_name != $specific_package_name)
                        {
                            // if not cleanup was requested then we can skip non mtaching packages
                            continue;
                        }

                        $this->log('     -> package #' . ++$this->package_counter . ' ' . $package_name . ': get binary list');

                        try
                        {
                            $newlist = $this->api->getBuiltBinaryList($project->name, $repo_name, $arch_name, $package_name);
                        }
                        catch (RuntimeException $e)
                        {
                            $this->log('        [EXCEPTION] ' . $e->getMessage());
                        }

                        // this list contains all binaries built for all packages in that repo
                        // we will use it to do a clean up operation once this loop finishes
                        $fulllist = array_merge($fulllist, $newlist);

                        if ($cleanonly)
                        {
                            // no need to go futher if cleanup was requested
                            continue;
                        }

                        foreach($newlist as $file_name)
                        {
                            $this->log('        -> binary #' . ++$this->build_counter . ': ' . $file_name);

                            // the getBuiltBinaryList will also return binary names that are
                            // built for different architecture
                            // we should skip these binaries except the noarch ones
                            $chunks = explode('.', $file_name);

                            $bin_arch = '';

                            if (count($chunks) >= 2)
                            {
                                $bin_arch = $chunks[count($chunks) - 2];

                                // workaround for Harmattan repositories
                                if ($chunks[count($chunks) - 1] == 'deb')
                                {
                                    $bin_arch = 'armv7el';
                                }

                                if ($bin_arch == 'armv7l')
                                {
                                    // fix the inconsistency between the published repo and the API
                                    $bin_arch = 'armv7el';
                                }

                                if (   $bin_arch != 'src'
                                    && $bin_arch != $arch_name)
                                {
                                    if ($bin_arch != 'noarch')
                                    {
                                        $this->log('        skip arch: ' . $bin_arch . ' (' . $file_name . ')');
                                        continue;
                                    }
                                }
                            }

                            // creates or updates a package in the database
                            $package = $this->createPackage($project->name, $repo->id, $repo_name, $arch_name, $package_name, $file_name, $bin_arch);

                            if ( ! $package )
                            {
                                // we got no package object, usually because we had to delete
                                // an existing one from database, so go to next binary package
                                continue;
                            }

                            try
                            {
                                $this->log('           fetch image names for ' . $package_name);

                                $filelist = $this->api->getPackageSourceFiles($project->name, $package_name);

                                $images = array_filter
                                (
                                    $filelist,
                                    function($item)
                                    {
                                        $retval = false;
                                        $name = $item['name'];
                                        $_icon_marker = 'icon.png';
                                        $_screenshot_marker = 'screenshot.png';

                                        if (   strpos($name, $_icon_marker) === (strlen($name) - strlen($_icon_marker))
                                            || strpos($name, $_screenshot_marker) === (strlen($name) - strlen($_screenshot_marker)))
                                        {
                                            $retval = true;
                                        }

                                        return $retval;
                                    }
                                );
                                $this->log('           received image names for ' . $package_name . ' (count: ' . count($images) . ')');
                            }
                            catch (RuntimeException $e)
                            {
                                $this->log('         [EXCEPTION] ' . $e->getMessage());
                            }

                            // collect attachments
                            $pngattachments = $package->find_attachments(array('mimetype' => 'image/png'));
                            $keptattachments = array();

                            // delete ones that are no longer needed
                            foreach ($pngattachments as $attachment)
                            {
                                $delete = true;
                                foreach ($images as $image)
                                {
                                    if ($image['name'] == $attachment->name)
                                    {
                                        $delete = false;
                                    }
                                }

                                if ($delete)
                                {
                                    $this->log('           attachment no longer needed: ' . $attachment->name . ' (guid: ' . $attachment->guid . ')');
                                    // TODO: this segfaults midgard
                                    // $package->purge_attachments(array('guid' => $attachment->guid), true);
                                    $package->delete_attachments(array('guid' => $attachment->guid));
                                }
                                else
                                {
                                    #$this->log('           attachment kept: ' . $attachment->name . ' (guid: ' . $attachment->guid . ')');
                                    $keptattachments[] = array(
                                        'name' => $attachment->name,
                                        'mtime' => $attachment->metadata->revised->getTimestamp()
                                    );
                                }
                            }

                            // compare images with the current ones and create new or changed ones
                            foreach ($images as $image)
                            {
                                $skip = false;
                                $name = $image['name'];
                                $size = $image['name'];
                                $mtime = $image['mtime'];

                                foreach ($keptattachments as $attachment)
                                {
                                    #echo 'compare: ' . $attachment['name'] . ' (' . $attachment['mtime'] . ') vs. ' . $name . ' (' . $mtime . ')' . "\n";
                                    if ($attachment['name'] !== $name)
                                    {
                                        continue;
                                    }
                                    if ($attachment['mtime'] >= $mtime)
                                    {
                                        $skip = true;
                                    }
                                }

                                if ($skip)
                                {
                                    $this->log('           image did not change: ' . $name . ', skip it');
                                    continue;
                                }

                                try
                                {
                                    $this->log('           fetch image: ' . $name);
                                    //$fp is either a stream, or a string if wget is used to fetch content
                                    $fp = $this->api->getPackageSourceFile($project->name, $package_name, $name);
                                }
                                catch (RuntimeException $e)
                                {
                                    $this->log('         [EXCEPTION] ' . $e->getMessage());
                                }

                                if ($fp)
                                {
                                    $attachment = $package->create_attachment($name, $name, "image/png");

                                    if (! $attachment)
                                    {
                                        // attachment with this name may already exist
                                        // try to fetch it
                                        $this->log('           attachment: ' . $name . ' might exists; try update');
                                        $attachments = $package->find_attachments(array('name' => $name, 'mimetype' => "image/png"));
                                        if (   is_array($attachments)
                                            && count($attachments))
                                        {
                                            $attachment = $attachments[0];
                                        }
                                    }

                                    if ($attachment)
                                    {
                                        $blob = new midgard_blob($attachment);

                                        $handler = $blob->get_handler('wb');

                                        if ($handler)
                                        {
                                            if (! $this->config['wget'])
                                            {
                                                fwrite($handler, stream_get_contents($fp));
                                                fclose($fp);
                                            }
                                            else
                                            {
                                                fwrite($handler, $fp);
                                            }

                                            fclose($handler);
                                            $attachment->update();
                                            $this->log('           attachment done: ' . $attachment->name . ' (location: blobs/' . $attachment->location . ')');
                                        }
                                        else
                                        {
                                            $this->log('Could not create blob');
                                        }
                                    }
                                }
                            }
                        }
                    }

#                    if ($cleanonly)
#                    {
                        // now cleanup all the packages from our database
                        // that are not part of this OBS repository
                        $this->cleanPackages($repo, $fulllist, $specific_package_name);
#                    }
                }
            }
        }
    }

    /**
     * Creates or updates a package in the database
     *
     * @return object package object
     */
    public function createPackage($project_name = null, $repo_id = null, $repo_name = null, $arch_name = null, $package_name = null, $file_name = null, $repo_arch_name = null)
    {
        if (   $project_name
            && $repo_name
            && $arch_name
            && $package_name
            && $file_name)
        {
            // get fill package info via OBS API
            try
            {
                $extinfo = $this->api->getPackageWithFullInformation($project_name, $repo_name, $arch_name, $package_name, $file_name);
            }
            catch (RuntimeException $e)
            {
                $this->log('         [EXCEPTION] ' . $e->getMessage());
            }
        }
        else
        {
            throw new RuntimeException('Not enough parameters to gather full package information');
            return;
        }

        // get a com_meego_package instance
        $package = $this->getPackageByFileName($file_name, $repo_id);

        if (   $package
            && $repo_id
            && $extinfo)
        {
            if ( ! $package->guid )
            {
                $package->repository = $repo_id;
                $package->filename = $extinfo->filename;
            }

            // deb or rpm
            $package->type = substr($package->filename, strrpos($package->filename, '.') + 1);

            $package->size = $extinfo->size;
            $package->name = $extinfo->name;
            $package->title = $extinfo->title;
            $package->parent = $package_name;
            $package->version = $extinfo->version;
            $package->summary = $extinfo->summary;
            $package->description = $extinfo->description;

            // if the package is a source package then the downloadurl is slightly different
            // also change the title a bit
            if ($repo_arch_name == 'src')
            {
                $package->title = $package->title . '-src';
            }

            if ($repo_arch_name == 'armv7el')
            {
                // fix the inconsistency between the published repo and the API
                $repo_arch_name = 'armv7l';

                if ($package->type == 'deb')
                {
                    $repo_arch_name = $extinfo->arch;
                }
            }

            // direct download url
            $_uri = str_replace(':', ':/', $project_name) . '/' . str_replace(':', ':/', $repo_name) . '/' . $repo_arch_name . '/' . $file_name;
            $package->downloadurl = $this->download_repo_protocol . '://' . $this->download_repo_host . '/' . $_uri;

            // @todo
            $package->bugtracker = '* TODO *';

            // for some info we need a special xray
            try
            {
                switch ($package->type)
                {
                    case 'rpm':
                        $xray = new RpmXray($this->download_repo_protocol, $this->download_repo_host, $_uri);
                        break;
                    case 'deb':
                        $xray = new DebXray($this->download_repo_protocol, $this->download_repo_host, $_uri, $this->config['wget'], $this->config['wget_options'], $this->debug);
                        break;
                    default:
                        throw new RuntimeException("Unknown file extension: " . $package->type . "(should be rpm or deb).");
                }
            }
            catch (RuntimeException $e)
            {
                $this->log('         [EXCEPTION] ' . $e->getMessage());

                // if there was a problem during xray (with code 999)
                // then it almost certainly means that the package no longer exists in the repository
                // so if the package exists in our database then remove it
                if (   $package->guid
                    && $e->getCode() == 999)
                {
                    // if package deletion is OK then return immediately
                    $result = $this->deletePackage($package, $project_name);
                }
            }

            if (is_object($xray))
            {
                $package->license = $this->getLicense($xray->license, '');
                $package->homepageurl = $xray->url;
                $package->category = $this->getCategory($xray->group);
            }

            // call the parent
            if ($package->guid)
            {
                $this->log('           update: ' . $package->filename . ' (name: ' . $package->name . ')');
                $package->metadata->hidden = false;
                $package->update();
            }
            else
            {
                $this->log('           create: ' . $package->filename . ' (name: ' . $package->name . ')');
                $package->create();
            }

            try
            {
                // if attachment creation failed then use the original OBS link
                $package->installfileurl = $this->api->getInstallFileURL($project_name, $repo_name, $arch_name, $package_name, $file_name);

                // get the file and store it locally
                // $fp might be a stream, or string if wget is in use
                $fp = $this->api->http->get_as_stream($this->api->getRelativeInstallPath($project_name, $repo_name, $arch_name, $package_name, $file_name));

                if ($fp)
                {
                    $attachment = $package->create_attachment($package_name . "_install.ymp", $package_name . "_install.ymp", "text/x-suse-ymp");

                    if ($attachment)
                    {
                        $blob = new midgard_blob($attachment);

                        $handler = $blob->get_handler('wb');

                        if ($handler)
                        {
                            if (! $this->config['wget'])
                            {
                                $ymp = stream_get_contents($fp);
                            }
                            else
                            {
                                $ymp = $fp;
                            }

                            $origymp = $ymp;

                            // replace name with the package name
                            $ymp = self::replace_name_with_packagename($ymp, $package->name);

                            if (! strlen($ymp))
                            {
                                $this->log('           attempt to update package name in: ' . $attachment->name . ' would result in 0 byte long file; rollback, location: blobs/' . $attachment->location);
                                $ymp = $origymp;
                            }

                            // write the attachment to the file system
                            fwrite($handler, $ymp);

                            if (! $this->config['wget'])
                            {
                                fclose($fp);
                            }

                            // close the attachment's handler
                            fclose($handler);

                            $attachment->update();
                            $this->log('           attachment created: ' . $attachment->name . ' (location: blobs/' . $attachment->location . ')');
                        }
                        else
                        {
                            $this->log('Could not create attachment');
                        }
                    }
                    else
                    {
                        // could not create attachment, maybe we have it already
                        $attachments = $package->list_attachments();

                        foreach ($attachments as $attachment)
                        {
                            if ($attachment->name == $package_name . "_install.ymp")
                            {
                                $blob = new midgard_blob($attachment);
                                $handler = $blob->get_handler('rb+');
                                if ($handler)
                                {
                                    $content = $blob->read_content();
                                    $ymp = $content;

                                    if (strlen($content))
                                    {
                                        $ymp = self::replace_name_with_packagename($content, $package->name);
                                    }

                                    if (! strlen($ymp))
                                    {
                                        $this->log('           attempt to update package name in: ' . $attachment->name . ' would result in 0 byte long file; rollback, location: blobs/' . $attachment->location);
                                        $ymp = $content;
                                    }

                                    fwrite($handler, $ymp);
                                    fclose($handler);
                                }
                                else
                                {
                                    $this->log('           failed to update attachment: ' . $attachment->name . ', location: blobs/' . $attachment->location);
                                }
                                break;
                            }
                        }
                    }

                    // set the install url field to the local attachment
                    if (is_object($attachment))
                    {
                        // write a local relative URL for the install file
                        $package->installfileurl = '/mgd:attachment/' . $attachment->guid . '/' . $attachment->name;
                    }

                    // update because of the installfileurl stuff
                    $package->update();
                }
            }
                catch (RuntimeException $e)
            {
                $this->log('         [EXCEPTION] ' . $e->getMessage());
            }

            // get the roles and create the necessary role objects
            $roles = $this->api->getPackageMeta($project_name, $package_name);

            foreach ($roles as $role => $userids)
            {
                foreach($userids as $userid)
                {
                    $this->log('           create role: ' . $userid . ' = ' . $role . ' (' . $package->guid . ')');
                    $this->createRole($package->guid, $userid, $role);
                }
            }

            // add relations by calling the parent class
            $this->addRelations($extinfo, $package);
        }

        return $package;
    }

    /**
     * Replaces the Name inside the YMP file with a real package name
     * @param string content of the ymp file
     * @param string the package name
     */
    public function replace_name_with_packagename($ymp, $packagename)
    {
        $this->log('           attempt to update package name in ymp file');

        $retval = preg_replace('|\<software\>\s*\<item\>\s*\<name\>[^<].*\<\/name\>|', "<software>\n      <item>\n        <name>" . $packagename . "</name>", $ymp);
        if (! $retval)
        {
            $retval = $ymp;
            $this->log('           updating ymp file with ' . $packagename . ' failed');

        }
        else
        {
            $this->log('           updating ymp file with ' . $packagename . ' succeeded');
        }
        return $retval;
    }
}
