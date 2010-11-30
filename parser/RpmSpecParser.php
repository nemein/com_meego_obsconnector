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
     * Constructor
     */
    function __construct(&$path, &$distribution) {
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
            '/^Name:\s*(.*)$/i',
            '/^Version:\s*(.*)$/i',
            '/^Release:\s*(\S*)(%\{\?dist\}).*$/',
            '/^Release:\s*(\S*)$/',
            '/^Summary:\s*(.*)$/i',
            '/^License:\s*(.*)$/i',
            '/^URL:\s*(.*)$/i',
            '/^Epoch:\s(.*)$/i',
            '/^Group:\s(.*)$/i',
            '/^%define\s*(\S*)\s*(\S*)\s*$/i',
            '/^%description\s*(.*)$/i',
            '/%package\s*(.*)$/i',

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
            'package: $1',
        );

        while (($buffer = fgets($this->handle, $this->blocksize)) !== false) {
            $result = preg_filter($pattern, $replace, $buffer);
            if ($result) {
                $info = explode(':', $result);
                $this->$info[0] = $info[1];
            }
        }

        unset($buffer, $result, $info);
    }
}

?>
