<?php
/*
 * Parser.php
 *
 * The parser parent that should be extended by certain parsers
 *
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 *
 * This class takes care of the following:
 *
 * - file opening (unless it is constructed with a stream handler)
 * - populating properties
 *
 */

require_once("Package.php");
require_once("Dependency.php");

class Parser extends Package {
    /**
     * debug flag
     */
    var $_flag_debug = true;

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
     * @param distribution the package is built for (used on version numbers)
     */
    function __construct($location = null, $distribution = '') {

        parent::__construct();

        if (! isset($this->location))
        {
            return false;
        }
        else
        {
            $this->location = $location;
            $this->distribution = $distribution;

            if (   is_resource($location)
                && get_resource_type($location) == 'stream')
            {

                $this->handle = $location;

            }
            else
            {
                $this->handle = @fopen($this->location, 'r');

                if (! $this->handle)
                {
                    throw new RuntimeException('Opening file: ' . $location . " failed\n");
                }
            }
        }
    }

    /**
     * Getter to use it in preg_* function calls
     * Child can overload, but remember to call this always
     *
     * @param array with matched strings
     */
    private function _get($matches) {
        $this->debug('Check key: ' . $matches);

        if (isset($this->$matches))
        {
            $this->debug('Return key: ' . $this->$matches);
            return $this->$matches;
        }
        else
        {
            return null;
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