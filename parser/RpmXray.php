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

require_once("Parser.php");
require_once("Dependency.php");

class RpmXray extends Parser {

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
     * Constructor
     */
    function __construct($location = '', $debug = false)
    {
        if (! strlen($location))
        {
            throw new RuntimeException('No location given for thr RpmXray');
        }

        // location can be any URI, ie. http://..... will work too
        $this->location = $location;

        parent::__construct($this->location, '');

        $this->_flag_debug = $debug;

        $this->xray();

        // delete the local file at $location
        //unlink($location);

        fclose($this->handle);

        parent::__destruct();
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
                $this->$key = trim(shell_exec("rpm -qp --queryformat '%{" . $key . "}\n' " . $this->location));
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