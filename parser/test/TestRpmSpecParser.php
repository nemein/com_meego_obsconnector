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
require_once(realpath(__DIR__ . '/..') . '/RpmSpecParser.php');

// @todo: test for remote URL fetching

$testFiles = array('test1.spec');//, 'test2.spec', 'test3.spec', 'test4.spec', 'test5.spec');

foreach ($testFiles as $testFile) {

    $location = __DIR__ . '/' . $testFile;
    $dist = 'helloworld';

    $spec = new RpmSpecParser($location, $dist, true);

    #echo "Spec file    : " . $location . "\n";
    #echo "Content bytes: " . filesize($location) . "\n";

    #echo "\n";

    echo "Name         : " . $spec->name . "\n";
    echo "Version      : " . $spec->version . "\n";
    echo "Group        : " . $spec->group . "\n";
    echo "Release      : " . $spec->release . "\n";
    echo "Summary      : " . $spec->summary . "\n";
    echo "License      : " . $spec->license . "\n";
    echo "Homepage     : " . $spec->url . "\n";
    echo "Description  : " . $spec->description . "\n";

    if (is_array($spec->depends)) {
        foreach ($spec->depends as $dependency) {
            echo "Depends      : " . $dependency->name  . ' ' . $dependency->constraint . ' ' . $dependency->version . "\n";
        }
    }

    echo "\n";

    if (is_array($spec->buildDepends)) {
        foreach ($spec->buildDepends as $dependency) {
            echo "BuildDepends : " . $dependency->name  . ' ' . $dependency->constraint . ' ' . $dependency->version . "\n";
        }
    }

    echo "\n";

    if (is_array($spec->provides)) {
        foreach ($spec->provides as $provided) {
            echo "Provides : " . $provided->name  . ' ' . $provided->constraint . ' ' . $provided->version . "\n";
        }
    }

    echo "\n";

    if (is_array($spec->obsoletes)) {
        foreach ($spec->obsoletes as $obsoleted) {
            echo "Obsoletes : " . $obsoleted->name  . ' ' . $obsoleted->constraint . ' ' . $obsoleted->version . "\n";
        }
    }

    echo "\n";

    if (is_array($spec->subpackages)) {
        foreach ($spec->subpackages as $subpackage) {
            echo "Subpackage: " . $subpackage->name . "\n";
            foreach ($subpackage as $key => $value) {
                if (   $key == 'depends'
                    || $key == 'buildDepends'
                    || $key == 'provides'
                    || $key == 'conflicts'
                    || $key == 'obsoletes') {
                    foreach ($value as $stuff) {
                        echo ucfirst($key) . ': ' . $stuff->name  . ' ' . $stuff->constraint . ' ' . $stuff->version . "\n";
                    }

                } else {
                    echo ucfirst($key) . ': ' . trim($value) . "\n";
                }

            }
            echo "\n";
        }
    }
}

?>
