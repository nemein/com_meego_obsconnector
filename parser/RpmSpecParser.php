<?php
/*
 * RpmSpecParser.php
 *
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 *
 */
require_once("Parser.php");

/**
 * Parses RPM spec files
 */
class RpmSpecParser extends Parser {

    /**
     * Package sepcific attributes
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
     * Constructor
     */
    function __construct($path, $distribution) {
        parent::__construct($path, $distribution);
        $this->parse();
        fclose($this->handle);
        parent::__destruct();
    }

    /**
     * Parse the file in 1 go
     *
     * @todo: add missing patterns and their "replacements"
     * @todo: parse complex data (such as depends ...)
     *
     */
    function parse() {
        $pattern = array(
            '/^Name\s*:\s*(.*)$/i',
            '/^Version\s*:\s*(.*)$/i',
            '/^Release\s*:\s*(\S*)(%\{\?dist\}).*$/',
            '/^Release\s*:\s*(\S*)$/',
            '/^Summary\s*:\s*(.*)$/i',
            '/^License\s*:\s*(.*)$/i',
            '/^URL\s*:\s*(.*)$/i',
            '/^Epoch\s*:\s*(.*)/i',
            '/^Group\s*:\s*(.*)$/i',
            '/^%define\s*(\S*)\s*(\S*)\s*$/i',
            '/^%description\s*$/i',
            '/^%description\s*(.*)$/i',
            '/^%description\s*:(.*)$/',
            '/%package\s*(.*)$/i',
            '/^Requires\s*:(.*)$/i',
            '/^BuildRequires\s*:(.*)$/i',
            '/^Obsoletes\s*:(.*)$/i',
            '/^Conflicts\s*:(.*)$/i',
            '/^.*$/',
        );

        $replace = array(
            'name: $1',
            'version: $1',
            'release: $1' . $this->distribution,
            'release: $1',
            'summary: $1',
            'license: $1',
            'url: $1',
            'epoch: $1',
            'group: $1',
            '$1: $2',
            'description: $1',
            'description: $1',
            'description: $1',
            'package: $1',
            'depends: $1',
            'buildDepends: $1',
            'obsoletes: $1',
            'conflicts: $1',
            '$0',
        );

        $_flag_subpackage = false;

        while (($buffer = fgets($this->handle, $this->blocksize)) !== false) {

            $result = preg_filter($pattern, $replace, $buffer);

            if ($result) {
                $info = explode(':', $result, 2);

                if (   $_flag_subpackage
                    && trim($result) == '') {
                    // stop subpackage info collection
                    $this->debug('Stop collecting subpackage info');
                    $_flag_subpackage = false;
                    $_subpackage = '';
                    $_collection = '';
                    $_data = '';

                }

                if (isset($info[1])) {

                    if (strlen(trim($info[1])) > 0
                        && ($info[0] == 'package'
                        || $info[0] == 'description'
                        || $info[0] == 'files')) {
                        // start subpackage info collection

                        $this->debug('Start collecting subpackage info: ' . $info[1]);
                        $_subpackage = trim($info[1]);
                        $_flag_subpackage = true;
                    }

                    // set where do we collect data; important if we collect info that spans over multiple lines
                    $_collection = trim($info[0]);

                    if (strlen($_collection) > 0) {

                        if (   ! isset($this->$info[0])
                            && ! $_flag_subpackage) {
                            // this is not a data we want to collect
                            $this->debug('Not useful data; continue looping: ' . $info[0]);
                            continue;
                        }
                        $_data = trim($info[1]);

                        // check if data has variable(s) that need(s) to be substituted
                        $_variables = preg_replace('/%{([^}]+)}/e', "self::_get('$1')", $_data);

                        if ($_variables != $_data) {
                            $this->debug('Substituted a variable in : ' . $_data . ' => ' . $_variables);
                            $_data = $_variables;
                        }

                        $this->debug('Collection is: ' . $_collection . ', data is singleline: ' . $_data);
                    }
                } else {
                    if (   isset($_collection)
                        && isset($info[0])
                        && $info[0][0] != '%') {
                        // no new _collection, so this must be a multiline info
                        $_data = "\n" . trim($info[0]);

                        // are we in subpackage mode?
                        if ($_flag_subpackage) {
                            $this->subpackages[$_subpackage][$_collection] .= $_data;
                        } elseif(isset($this->$_collection)) {
                            // let's see if this is a needed data and append it
                            if (! is_array($this->$_collection)) {
                                $this->$_collection .= $_data;
                            }
                        }
                        $this->debug('Collection is: ' . $_collection . ', data is multiline: ' . $_data);
                    }
                }

                // setting part
                if ($_flag_subpackage) {
                    // store the data to collection of that particular subpackage
                    $this->subpackages[$_subpackage][$_collection] = $_data;
                    //print_r($this->subpackages);
                } elseif (isset($this->$info[0])) {
                    $this->debug('OK, we can set: ' . $info[0]);

                    if (isset($_data)) {
                        if (is_array($this->$info[0])) {
                            // do we store in array, let's then push
                            array_push($this->$info[0], $_data);
                            $this->debug('Pushed: ' . $_data . ' to ' . $info[0]);
                        } else {
                            // ok, the internal attrib is a string
                            $this->$info[0] = $_data;
                            $this->debug("Set " . $this->$info[0] . ' = ' . $_data);
                        }
                        unset($_data);
                    } else {
                        // @todo: what here?
                    }
                }
            }
        }

        unset($buffer, $result, $info);
    }

    /**
     * Getter to use it in preg_* function calls
     * @param array with matched strings
     */
    function _get($matches) {
        $this->debug('Check key: ' . $matches);

        if (isset($this->$matches)) {
            $this->debug('Return key: ' . $this->$matches);
            return $this->$matches;
        } else {
            return null;
        }
    }

}

?>
