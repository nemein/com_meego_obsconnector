<?php
/*
 * RpmXray.php
 *
 * RPM file xray tool using the 'rpm' tool to query certain attributes of
 * an RPM package
 *
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 *
 */

require_once("Dependency.php");
require_once("../api/http.php");

class RpmXray {

    /**
     * Package sepcific attributes
     *
     * @todo: we might want to put group to Package object instead
     */
    var $group = '';

    /**
     * Release info
     */
    var $release = '';

    /**
     * Epoch info
     */
    var $epoch = '';

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
     * Constructor
     */
    function __construct($protocol = 'http', $host = null, $uri = null, $debug = false)
    {
        if ( ! $host )
        {
            throw new RuntimeException('No host given for RpmXray', 1);
        }

        if ( ! $uri )
        {
            throw new RuntimeException('No uri given for RpmXray', 2);
        }

        $this->location = $protocol . '://' . $host . '/' . $uri;

        $this->_flag_debug = $debug;

        $this->xray();
    }

    /**
     *
     * X-rays the file
     *
     */
    function xray()
    {
        // check if rpm tool is available
        $querytags = shell_exec('rpm --querytags 2>/dev/null');

        if ($querytags == NULL)
        {
            throw new RuntimeException("Please install the 'rpm' tool which supports the --querytags command line argument.");
        }

        $available = array_flip(split("\n", $querytags));

        // get the info needed that is __not available__ through OBS API
        // this works with single line infos, so requesting 'provides' will only
        // return the 1st provided file's name
        //
        // @see https://api.pub.meego.com/apidocs/#64
        $infoneeded = array (
            'license',
            'url',
            'group',
            'arch'
        );

        foreach($infoneeded as $key)
        {
            if (array_key_exists(strtoupper($key), $available))
            {
                $command = "rpm -qp --queryformat '%{" . $key . "}' " . $this->location;
                exec($command, $output, $retval);

                // check for return value
                if ($retval)
                {
                    // rpm returned an error, bail out
                    $error = 'The tool "rpm" returned an error: ' . trim($retval) . ".\n Command failed:\n  " . $command . "\nIt usually means that the location is not available.";
                    throw new RuntimeException($error, 999);
                }
                $this->$key = trim(implode("\n", $output));
            }
        }

        if ($this->_flag_debug) {
            foreach($infoneeded as $key)
            {
                echo "\n" . ucfirst($key) . "\n";
                echo "---------------------\n";
                print_r($this->$key);
                echo "\n";
            }
        }

        unset($querytags, $available, $infoneeded);
    }
}

?>