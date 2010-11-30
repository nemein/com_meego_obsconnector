<?php
/*
 * TestRpmSpecParser.php
 *
 * Tests the RpmSpecParser code
 *
 * @author The Midgard Project, http://www.midgard-project.org
 * @copyright The Midgard Project, http://www.midgard-project.org
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 *
 * @todo: get more spec files for testing
 *
 */
require_once('../RpmSpecParser.php');

// @todo: test for remote URL fetching
//$location = 'http://';

$location = getcwd() . '/test1.spec';
$dist = 'helloworld';

$spec = new RpmSpecParser($location, $dist);

echo "Spec file    : " . $location . "\n";
echo "Content lines: " . (count($spec->content) - 1) . "\n";
echo "Content bytes: " . filesize($location) . "\n";

echo "\n";

echo "Name         : " . $spec->name . "\n";
echo "Version      : " . $spec->version . "\n";
echo "Release      : " . $spec->release . "\n";
echo "Summary      : " . $spec->summary . "\n";
echo "License      : " . $spec->license . "\n";
echo "Homepage     : " . $spec->url . "\n";

?>
