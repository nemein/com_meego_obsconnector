<?php
/*
 * Parser.php
 *
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 *
 */

/**
 * Parser base class that takes care of the following
 * - file opening
 * - populating internal content member
 * - file closing
 *
 * This also holds the common attributes that are the same in
 * Debian and RPM packages.
 */
class Parser {
    /**
     * Distribuiton name as string
     * Used when composing package version numbers
     */
    var $distribution = '';

    /**
     * Location as string
     * Can be either a URL pointong to a remote file
     * or a simple location to a local file
     */
    var $location = '';

    /**
     * Content as array
     * Each line of the files (location) is put in a separate item
     */
    var $content = array();

    /**
     * Package specific attributes
     */

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
     * RPM spec      : License:
     * Debian control: @todo
     * @todo: this is needed although debian stores this in the copyright file     *
     */
    var $license = '';

    /**
     * RPM spec file      :  URL:
     * Debian control file:  Homepage
     */
    var $url = '';

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
     * File handler
     */
    var $handle = null;

    /**
     * Block size for reading files
     */
    var $blocksize = 1024;

    /**
     * Contsructor opens the file
     * @param location of the file
     * @param distribution the package is built for (used on version numbers)c
     */
    function __construct($location = null, $distribution = '') {
        if (! isset($this->location)) {
            return false;
        } else {
            $this->location = $location;
            $this->distribution = $distribution;

            if (file_exists($this->location)) {

                $this->handle = fopen($this->location, 'r');

                if (! $this->handle) {
                    die('Opening file: ' . $location . " failed\n");
                }

            } else {
                die('File unavailable at ' . $location . "\n");
            }
        }
    }

    /**
     * Kill 'em all
     *
     * @todo: needed?
     */
    function __destruct() {
        unset($this);
    }
}
?>
