<?php
/*
 * DebXray.php
 *
 * Deb file xray tool using the 'dpkg' tool to query attributes of
 * an deb package
 *
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 *
 */

require_once("Dependency.php");
require_once("../api/http.php");

class DebXray
{
    /**
     * Collect subpackage info to a separate array
     */
    var $subpackages = array();

    /**
     * Location of the RPM file
     */
    var $location = null;

    /**
     * HTTP interface
     */
    var $http = null;

    /**
     * License info
     *
     * Extracting license info of .deb files requires a human, or
     * some higher power.
     */
    var $license = 'N/A';

    /**
     * Constructor
     */
    public function __construct($protocol = 'http', $host = null, $uri = null, $wget = false, $wget_options = '', $debug = false)
    {
        if ( ! $host )
        {
            throw new RuntimeException('No host given for DebXray', 1);
        }

        if ( ! $uri )
        {
            throw new RuntimeException('No URI given for DebXray', 2);
        }

        $this->http = new com_meego_obsconnector_HTTP($protocol, $host, $wget, $wget_options);

        $this->location = $protocol . '://' . $host . '/' . $uri;

        $this->_flag_debug = $debug;

        $this->xray();
        $this->extract_icon();
    }

    /**
     *
     * X-rays the file
     *
     */
    public function xray()
    {
        // check if rpm tool is available
        $dpkg = shell_exec('dpkg --version');

        if ($dpkg == NULL)
        {
            throw new RuntimeException("Please install the 'dpkg' tool.");
        }

        // deb name
        $deb_name = substr($this->location, strrpos($this->location, '/') + 1);

        // download the deb locally as dpkg does not have a curl-like interface
        $location = $this->http->download($this->location, '/tmp/' . $deb_name);

        if (! is_file($location))
        {
            throw new RuntimeException("Could not download the deb package locally: $this->location");
        }

        // get the info needed that is __not available__ through OBS API
        // this works with single line infos, so requesting 'provides' will only
        // return the 1st provided file's name
        //
        // @see https://api.pub.meego.com/apidocs/#64
        $infoneeded = array
        (
            'homepage' => 'url',
            'section' => 'group',
            'architecture' => 'arch'
        );

        foreach ($infoneeded as $key => $map)
        {
            $command = 'dpkg -f ' . $location . ' ' . $key;
            exec($command, $output, $retval);

            // check for return value
            if ($retval)
            {
                // rpm returned an error, bail out
                $error = 'The tool "dpkg" returned an error: ' . trim($retval) . ".\n Command failed:\n  " . $command . "\nIt usually means that the location is not available.";
                throw new RuntimeException($error, 999);
            }
            $this->$map = trim(implode("\n", $output));
            $output = '';
        }

        if ($this->_flag_debug)
        {
            foreach($infoneeded as $key)
            {
                echo "\n" . ucfirst($key) . "\n";
                echo "---------------------\n";
                print_r($this->$key);
                echo "\n";
            }
        }

        unset($querytags, $infoneeded);
    }

    /**
     *
     * Looks up an icon from the package and extracts it to:
     * /var/tmp/{packagename}.icon
     *
     * It can then be further processed (e.g. import it to a midgard_attachment)
     *
     */
    public function extract_icon()
    {
        $this->icon = "local path to the icon";
    }
}

?>