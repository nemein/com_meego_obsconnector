<?php
/*
 * Package.php
 *
 * Generic package class
 *
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 *
 */
class Package {
    /**
     * RPM spec      : Name:
     * Debian control: Package @todo: maybe Source too?
     */
    var $name = '';

    /**
     * RPM spec      : Version:
     * Debian control: Version:
     */
    var $version = '';

    /**
     * RPM spec      : Summary:
     * Debian control: N/A
     * @todo: this is needed although debian has no specific field
     *        do we add the 1st line of description here?
     */
    var $summary = '';

    /**
     * RPM spec      : %description till next <blank line>\n% combination
     * Debian control: Description till next line that has nothing but \n
     */
    var $description = '';

    /**
     * Direct download URL to the package in question
     */
    var $downloadurl = '';

    /**
     * Install file that list needed repositories to fulfill runtime dependencies
     * Maemo (Debian): .install file, @see http://wiki.maemo.org/Installing_applications#.installs
     * RPM:
     */
    var $installfileurl = '';

    /**
     * RPM spec file      :  URL:
     * Debian control file:  Homepage
     */
    var $homepageurl = '';

    /**
     * RPM spec      : License:
     * Debian control: @todo
     * @todo: this is needed although debian stores this in the copyright file     *
     */
    var $license = '';

    /**
     * RPM spec      : Requires.*:
     * Debian control: Depends:
     * @see preDepends in debian control parser
     */
    var $depends = array();

    /**
     * RPM spec      : BuildRequires:
     * Debian control: Build-Depends:
     */
    var $buildDepends = array();

    /**
     * RPM spec      : Provides:
     * Debian control: Provides:
     */
    var $provides = array();

    /**
     * RPM spec      : Obsoletes:
     * Debian control: Replaces:
     */
    var $obsoletes = array();

    /**
     * RPM spec      : Conflicts:
     * Debian control: Conflicts:
     */
    var $conflicts = array();

    /**
     * RPM spec      : suggests ?
     * Debian control: recommends ?
     */
    var $suggests = array();

    /**
     * Name of the parent repository (if any)
     *
     */
    var $repository = '';

    /**
     * @param name of the package
     * @param version of the package
     */
    function __construct($name = '', $version = '') {
        $this->name = $name;
        $this->version = $version;
    }

    /**
     * debugger
     */
    function debug($message) {
        if ($this->_flag_debug) {
            $_ts = date("Y-m-d H:i:s", time());
            echo $_ts . ' [' . get_class($this) . ']: ' . trim($message) . "\n";
        }
    }
}
?>