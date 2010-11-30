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
require_once(realpath(__DIR__.'/..').'/RpmSpecParser.php');

// @todo: test for remote URL fetching
//$location = 'http://';

$location = __DIR__ . '/test1.spec';
$dist = 'helloworld';

$spec = new RpmSpecParser($location, $dist);

echo "Spec file    : " . $location . "\n";
echo "Content bytes: " . filesize($location) . "\n";

echo "\n";

echo "Name         : " . $spec->name . "\n";
echo "Version      : " . $spec->version . "\n";
echo "Release      : " . $spec->release . "\n";
echo "Summary      : " . $spec->summary . "\n";
echo "License      : " . $spec->license . "\n";
echo "Homepage     : " . $spec->url . "\n";
echo "Description  : " . $spec->description . "\n";

if (is_array($spec->depends)) {
    foreach ($spec->depends as $dependency) {
        echo "Depends      : " . $dependency . "\n";
    }
}

echo "\n";

if (is_array($spec->buildDepends)) {
    foreach ($spec->buildDepends as $dependency) {
        echo "BuildDepends : " . $dependency . "\n";
    }
}

echo "\n";

if (is_array($spec->provides)) {
    foreach ($spec->provides as $provided) {
        echo "Provides : " . $provided . "\n";
    }
}

echo "\n";

if (is_array($spec->obsoletes)) {
    foreach ($spec->obsoletes as $obsoleted) {
        echo "Obsoletes : " . $obsoleted . "\n";
    }
}

?>
