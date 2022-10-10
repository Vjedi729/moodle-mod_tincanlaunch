<?php

// A teacher or student clicking on the activity name in the gradebook is sent to grade.php within the activity.
// It should redirect the user to the appropriate page.
// For example, it could send students to the activity itself while sending teachers to a list of participating students.
//
// grade.php is supplied with the following parameters.

require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once 'header.php';
require_login();
require_once 'locallib.php';

$id = required_param('id', PARAM_INT);          // Course module ID
$itemnumber = optional_param('itemnumber', 0, PARAM_INT); // Item number, may be != 0 for activities that allow more than one grade per user
$userid = optional_param('userid', 0, PARAM_INT); // Graded user ID (optional)

// Typically you will use has_capability() to determine where to send the user then call redirect().

echo(
    "<script>console.log(\"Location: ".tincanlaunch_get_grade_url()."\")</script>"
);

// debugging(
//     "Location: " . tincanlaunch_get_launch_url($registrationid)
// );

?>

<!DOCTYPE html>
<heading>Grade Report</heading>