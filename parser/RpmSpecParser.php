<?php
/*
 * RpmSpecParser.php
 *
 * RPM .spec file parser
 *
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 *
 */

require_once("Parser.php");
require_once("Dependency.php");

class RpmSpecParser extends Parser {

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
     * Constructor
     */
    function __construct($path = '', $distribution = '', $debug = false) {
        parent::__construct($path, $distribution);
        $this->_flag_debug = $debug;
        $this->parse();
        fclose($this->handle);
        parent::__destruct();
    }

    /**
     *
     * Parse the file in 1 go
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
            '/^Obsoletes\s*:(.*)$/i',
            '/^Conflicts\s*:(.*)$/i',
            '/^Provides\s*:(.*)$/i',
            '/^BuildRequires\s*:(.*)$/i',
            '/^%(\S+)\s*(.*)$/i',
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
            'obsoletes: $1',
            'conflicts: $1',
            'provides: $1',
            'buildDepends: $1',
            '$1: $2',
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

                    if (   strlen(trim($info[1])) > 0
                        && ($info[0] == 'package'
                        || $info[0] == 'description')) {
                        // start subpackage info collection

                        $this->debug('Start collecting subpackage info [' . $info[0] . ']: ' . $info[1]);
                        $_subpackage = trim($info[1]);
                        $_flag_subpackage = true;

                        if (   is_array($this->subpackages)
                            && ! isset($this->subpackages[$_subpackage])) {
                            $this->subpackages[$_subpackage] = new Package($_subpackage);
                        }
                    }

                    // set where do we collect data; important if we collect info that spans over multiple lines
                    $_collection = trim($info[0]);

                    if (strlen($_collection) > 0) {

                        if (   ! isset($this->$info[0])
                            && ! $_flag_subpackage) {
                            // this is not a data we want to collect
                            $_collection = '';
                            $this->debug('Not useful data; continue looping: ' . $info[0]);
                            continue;
                        }

                        $_data = trim($info[1]);
                        // @fix: a recursive pattern to filter out :(.*) stuff
                        $i = -1;
                        $j = 0;
                        $_stuff = '';
                        do {
                            if (substr($_data, $j, 2) == '(:') {
                                $_count = null;
                                $_done = false;
                                do {
                                    $i++;
                                    if (substr($_data, $i, 1) == '(') {
                                        $_count++;
                                        //echo "Count (: $_count; j: $j; i: $i\n";
                                    }
                                    if (substr($_data, $i, 1) == ')') {
                                        $_count--;
                                        //echo "Count ): $_count; pos: $j; i: $i\n";
                                    }
                                    if (   isset($_count)
                                        && $_count == 0
                                        || $i > strlen($_data)) {
                                        //echo "Count is 0; finish\n";
                                        $_done = true;
                                    }
                                } while ( ! $_done );
                                $this->debug("Wipe out stuff from $j till " . ($i + 1));
                                $_data = substr_replace($_data, ' ', $j, ($i - $j + 1)) . ' ';
                            }
                            $i = $j;
                            $j++;
                        } while ($j < strlen($_data));
                        $this->debug('Data is: ' . $_data);

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
                        $_data = $info[0];

                        // are we in subpackage mode?

                        if ($_flag_subpackage) {

                            $this->_setData(&$this->subpackages[$_subpackage], $_collection, $_data);

                        } elseif (isset($this->$_collection)) {
                            // let's see if this is a needed data and append it to collection
                            if ( ! is_array($this->$_collection) ) {
                                $this->$_collection .= $_data;
                            }
                        }
                        $this->debug('Collection is: ' . $_collection . ', data is multiline: ' . $_data);
                    }
                }

                // setting part
                if ($_flag_subpackage) {
                    // store the data to collection of that particular subpackage
                    // $this->_setData(&$this->subpackages[$_subpackage], $_collection, $_data);
                } elseif (isset($this->$info[0])) {

                    if (isset($_data)) {
                        $this->debug('Check this if array: ' . $info[0]);

                        $this->_setData(&$this, $info[0], $_data);

                        unset($_data);
                        $this->debug('----------------------');
                    } else {
                        // @todo: what here?
                    }
                }
            }
        }

        if ($this->_flag_debug) {
            echo "\nDependencies\n";
            echo "---------------------\n";
            print_r($this->depends);

            echo "\nBuild Dependencies\n";
            echo "---------------------\n";
            print_r($this->buildDepends);

            echo "\nProvides\n";
            echo "---------------------\n";
            print_r($this->provides);

            echo "\nObsoletes\n";
            echo "---------------------\n";
            print_r($this->obsoletes);

            echo "\nConflicts\n";
            echo "---------------------\n";
            print_r($this->conflicts);

            echo "\nSubpackages\n";
            echo "---------------------\n";
            print_r($this->subpackages);
        }

        unset($buffer, $result, $info);
    }

    /**
     * Sets data
     *
     * @param holder where collection is located
     * @param collection where data is placed
     * @data
     */
    private function _setData(&$holder, $collection, $data) {
        if (   isset($holder->$collection)
            && is_array($holder->$collection)) {
            // do we store in array, let's then push an object there
            if (   $collection == 'subpackages'
                || $collection == 'depends'
                || $collection == 'buildDepends'
                || $collection == 'provides'
                || $collection == 'obsoletes'
                || $collection == 'conflicts') {

                    // first split at each ,
                    $_exp = explode(',', $data);//preg_split('/[,]+/', $data);

                    // if we still have strings spearated by spaces
                    // within these string we may have definitions with or without a version info
                    foreach ($_exp as $_def) {
                        // get all package name, constraint, version definitions
                        preg_match_all('/(\S*)\s+([<>=]+)\s+(\S*)/', $_def, $_matches, PREG_SET_ORDER);

                        foreach($_matches as $_match) {
                            //remove from the original string
                            $_def = str_replace($_match[0], '', $_def);
                            // create object and push to collection
                            $_obj = new Dependency($_match[1], $_match[2], $_match[3]);
                            $this->debug('Push ' . $_match[1] . ' ' . $_match[2] . ' ' . $_match[3] . ' to ' . $collection . ' of ' . $holder->name);
                            array_push($holder->$collection, $_obj);
                            unset($_obj);
                        }

                        $_def = trim($_def);
                        // we must have only single package names left separated by space
                        $_matches = preg_split('/[\s]+/', $_def);

                        // add all single package names to our pieces array
                        foreach($_matches as $_match) {
                            // create object and push to collection
                            if (strlen(trim($_match))) {
                                $_obj = new Dependency($_match);
                                $this->debug('Push ' . $_match . ' to ' . $collection . ' of ' . $holder->name);
                                array_push($holder->$collection, $_obj);
                                unset($_obj);
                            }
                        }
                    }
                    unset($_exp, $_def, $_matches, $_match);
            } else {
                array_push($holder[$collection], $data);
                $this->debug('Pushed string: ' . $data . ' to array ' . $collection);
            }
        } else {
            $holder->$collection .= $data;
            $this->debug('Set property: ' . $collection . ' to ' . $data . ' of holder: ' . $holder->name);
        }
    }

    /**
     * Getter to use it in preg_* function calls
     * @param array with matched strings
     */
    private function _get($matches) {
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