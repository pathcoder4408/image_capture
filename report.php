<?php

/**
 * image_capture report.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2006-2012 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once($GLOBALS["srcdir"] . "/api.inc.php");

function image_capture_report($pid, $useless_encounter, $cols, $id)
{
    global $webserver_root, $web_root, $encounter;

    // In the case of a patient report, the passed encounter is vital.
    $thisenc = $useless_encounter ? $useless_encounter : $encounter;

    $data = sqlQuery("SELECT * " .
        "FROM form_image_capture WHERE " .
        "id = ? AND activity = '1'", array($id));

    if ($data) {
        if ($data['notes']) {
            echo "<span class='bold'>Comments: </span><span class='text'>";
            echo nl2br(text($data['notes'])) . "</span><br />\n";
        }

        $image_index = 0;

        while (true) {
            // Construct the image file path and URL
            $filename = "{$thisenc}_{$id}_{$image_index}.jpg";
            $imagepath = $GLOBALS['OE_SITE_DIR'] . "/documents/" . check_file_dir_name($pid) . "/encounters/" . $filename;
            $imageurl  = $web_root . "/sites/" . $_SESSION['site_id'] . "/documents/" . check_file_dir_name($pid) . "/encounters/" . $filename;

            // Check if the image file exists and is readable
            if (is_file($imagepath) && is_readable($imagepath)) {
                echo "<p>Displaying Image: $filename</p>";
                echo "<img src='$imageurl' alt='Captured Image'";
                $asize = @getimagesize($imagepath);
                if ($asize && $asize[0] > 750) {
                    echo " class='bigimage'";
                }
                echo " /><br />\n";
            } else {
                // Exit loop when no more images are found
                if ($image_index > 0) {
                    break;
                } else {
                    echo "<p>No images found for this encounter.</p>";
                    break;
                }
            }

            $image_index++;
        }
    } else {
        echo "<p>No image capture data found for this form.</p>";
    }
}
