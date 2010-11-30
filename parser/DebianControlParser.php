<?php
/*
 * DebianControlParser.php
 *
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 *
 */
require_once('Parser.php');

/**
 * Parses Debian control files
 *
 * @todo: complete the code
 */
class DebianControlParser {
    /**
     * RPM spec      : N/A
     * Debian control: Pre-Depends:
     */
    var $preDepends = array();

    /**
     * RPM spec      : N/A
     * Debian control: Brakes:
     */
    var $breakes = array();

    /**
     * Constructor
     *
     * @todo finish
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
     * @todo finish
     *
     */
    function parse() {
    }
}
?>
