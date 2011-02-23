<?php
/**
 * Dependency.php
 *
 * Generic dependency class
 *
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 *
 * @extends Package
 *
 * Used in Parser for storing packages thar are:
 *
 *  dependencies
 *  build dependencies
 *  obsoleted
 *  provided
 *  conflicting
 *
 */

require_once("Package.php");

class Dependency extends Package {
    /*
     * Tells the nature of the dependency, like:
     *
     *  package = x.y   => strict dependency
     *  package >= x.y  => newer or exact version
     *  package <= x.y  => the exact or older version
     */
    var $constraint = '';
    var $_flag_debug = false;

    /**
     * Constructs a new dependency object
     * @param name of the package
     * @param constraint constraint to the version
     * @param version of the package
     */
    function __construct($name = '', $constraint = '', $version = '') {
        parent::__construct($name, $version);
        $this->constraint = $constraint;
        $this->debug('New dependency object: ' . $this->name . '' . $this->constraint . ' ' . $this->version);
    }
}
?>