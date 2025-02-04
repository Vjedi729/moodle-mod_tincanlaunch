<?php

/**
 * Library of interface functions and constants for module tincanlaunch
 *
 * All the core Moodle functions, neeeded to allow the module to work
 * integrated in Moodle should be placed here.
 * All the tincanlaunch specific functions, needed to implement all the module
 * logic, should go to locallib.php. This will help to save some memory when
 * Moodle is performing actions across all modules.
 *
 * @package   mod_tincanlaunch
 * @copyright 2013 Andrew Downes
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use TinCan\Score;
use TinCan\Statement;

defined('MOODLE_INTERNAL') || die();

// TinCanPHP - required for interacting with the LRS in tincanlaunch_get_statements.
require_once "$CFG->dirroot/mod/tincanlaunch/TinCanPHP/autoload.php";

// SCORM library from the SCORM module. Required for its xml2Array class by tincanlaunch_process_new_package.
require_once "$CFG->dirroot/mod/scorm/datamodels/scormlib.php";

global $tincanlaunchsettings;
$tincanlaunchsettings = null;

// Moodle Core API.

/**
 * Returns the information on whether the module supports a feature
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * 
 * @see plugin_supports() in lib/moodlelib.php
 * 
 * @return mixed true if the feature is supported, null if unknown
 */
function tincanlaunch_supports($feature)
{
    switch($feature) {
    case FEATURE_MOD_INTRO:
        return true;
    case FEATURE_COMPLETION_TRACKS_VIEWS:
        return true;
    case FEATURE_COMPLETION_HAS_RULES:
        return true;
    case FEATURE_BACKUP_MOODLE2:
        return true;
    case FEATURE_GRADE_HAS_GRADE:
        return true;
    default:
        return null;
    }
}

/**
 * Saves a new instance of the tincanlaunch into the database
 *
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 * 
 * @param object $tincanlaunch An object from the form in mod_form.php
 * 
 * @global moodle_database $DB
 * 
 * @return int The id of the newly inserted tincanlaunch record
 */
function tincanlaunch_add_instance(stdClass $tincanlaunch) 
{
    global $DB;

    $tincanlaunch->timecreated = time();

    // Need the id of the newly created instance to return (and use if override defaults checkbox is checked).
    $tincanlaunch->id = $DB->insert_record('tincanlaunch', $tincanlaunch);

    $tincanlaunchlrs = tincanlaunch_build_lrs_settings($tincanlaunch);

    // Determine if override defaults checkbox is checked or we need to save watershed creds.
    if ($tincanlaunch->overridedefaults == '1' || $tincanlaunchlrs->lrsauthentication == '2') {
        $tincanlaunchlrs->tincanlaunchid = $tincanlaunch->id;

        // Insert data into tincanlaunch_lrs table.
        if (!$DB->insert_record('tincanlaunch_lrs', $tincanlaunchlrs)) {
            return false;
        }
    }

    // Process uploaded file.
    if (!empty($tincanlaunch->packagefile)) {
        tincanlaunch_process_new_package($tincanlaunch);
    }

    return $tincanlaunch->id;
}

/**
 * Updates an instance of the tincanlaunch in the database
 *
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $tincanlaunch An object from the form in mod_form.php
 * @return boolean Success/Fail
 */
function tincanlaunch_update_instance(stdClass $tincanlaunch) {
    global $DB;

    $tincanlaunch->timemodified = time();
    $tincanlaunch->id = $tincanlaunch->instance;

    $tincanlaunchlrs = tincanlaunch_build_lrs_settings($tincanlaunch);

    // Determine if override defaults checkbox is checked.
    if ($tincanlaunch->overridedefaults == '1') {
        // Check to see if there is a record of this instance in the table.
        $tincanlaunchlrsid = $DB->get_field(
            'tincanlaunch_lrs',
            'id',
            array('tincanlaunchid' => $tincanlaunch->instance),
            IGNORE_MISSING
        );
        // If not, will need to insert_record.
        if (!$tincanlaunchlrsid) {
            if (!$DB->insert_record('tincanlaunch_lrs', $tincanlaunchlrs)) {
                return false;
            }
        } else { // If it does exist, update it.
            $tincanlaunchlrs->id = $tincanlaunchlrsid;

            if (!$DB->update_record('tincanlaunch_lrs', $tincanlaunchlrs)) {
                return false;
            }
        }
    }

    if (!$DB->update_record('tincanlaunch', $tincanlaunch)) {
        return false;
    }

    // Process uploaded file.
    if (!empty($tincanlaunch->packagefile)) {
        tincanlaunch_process_new_package($tincanlaunch);
    }

    return true;
}

function tincanlaunch_build_lrs_settings(stdClass $tincanlaunch) {

    // Data for tincanlaunch_lrs table.
    $tincanlaunchlrs = new stdClass();
    $tincanlaunchlrs->lrsendpoint = $tincanlaunch->tincanlaunchlrsendpoint;
    $tincanlaunchlrs->lrsauthentication = $tincanlaunch->tincanlaunchlrsauthentication;
    $tincanlaunchlrs->customacchp = $tincanlaunch->tincanlaunchcustomacchp;
    $tincanlaunchlrs->useactoremail = $tincanlaunch->tincanlaunchuseactoremail;
    $tincanlaunchlrs->lrsduration = $tincanlaunch->tincanlaunchlrsduration;
    $tincanlaunchlrs->tincanlaunchid = $tincanlaunch->instance;
    $tincanlaunchlrs->lrslogin = $tincanlaunch->tincanlaunchlrslogin;
    $tincanlaunchlrs->lrspass = $tincanlaunch->tincanlaunchlrspass;

    return $tincanlaunchlrs;
}

/**
 * Removes an instance of the tincanlaunch from the database
 *
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id Id of the module instance
 * @return boolean Success/Failure
 */
function tincanlaunch_delete_instance($id) {
    global $DB;

    if (! $tincanlaunch = $DB->get_record('tincanlaunch', array('id' => $id))) {
        return false;
    }

    // Determine if there is a record of this (ever) in the tincanlaunch_lrs table.
    $strictness = IGNORE_MISSING;
    $tincanlaunchlrsid = $DB->get_field('tincanlaunch_lrs', 'id', array('tincanlaunchid' => $id), $strictness);
    if ($tincanlaunchlrsid) {
        // If there is, delete it.
        $DB->delete_records('tincanlaunch_lrs', array('id' => $tincanlaunchlrsid));
    }

    $DB->delete_records('tincanlaunch', array('id' => $tincanlaunch->id));

    return true;
}

/**
 * Returns a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @return stdClass|null
 */
function tincanlaunch_user_outline() {
    $return = new stdClass();
    $return->time = 0;
    $return->info = '';
    return $return;
}

/**
 * Given a course and a time, this module should find recent activity
 * that has occurred in tincanlaunch activities and print it out.
 * Return true if there was output, or false is there was none.
 *
 * @return boolean
 */
function tincanlaunch_print_recent_activity() {
    return false;  // True if anything was printed, otherwise false.
}

/**
 * Function to be run periodically according to the moodle cron
 * This function searches for things that need to be done, such
 * as sending out mail, toggling flags etc ...
 *
 * @return boolean
 * @todo Finish documenting this function
 **/
function tincanlaunch_cron() {
    return true;
}

/**
 * Returns all other caps used in the module
 *
 * @example return array('moodle/site:accessallgroups');
 * @return array
 */
function tincanlaunch_get_extra_capabilities() {
    return array();
}

// File API.

/**
 * Returns the lists of all browsable file areas within the given module context
 *
 * The file area 'intro' for the activity introduction field is added automatically
 * by {@link file_browser::get_file_info_context_module()}
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @return array of [(string)filearea] => (string)description
 */
function tincanlaunch_get_file_areas() {
    $areas = array();
    $areas['content'] = get_string('areacontent', 'scorm');
    $areas['package'] = get_string('areapackage', 'scorm');
    return $areas;
}

/**
 * File browsing support for tincanlaunch file areas
 *
 * @package mod_tincanlaunch
 * @category files
 *
 * @param file_browser $browser
 * @param array $areas
 * @param stdClass $course course object
 * @param stdClass $cm course module
 * @param stdClass $context
 * @param string $filearea
 * @param int $itemid item ID
 * @param string $filepath
 * @param string $filename
 * 
 * @return file_info instance or null if not found
 */
function tincanlaunch_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    global $CFG;

    if (!has_capability('moodle/course:managefiles', $context)) {
        return null;
    }

    $fs = get_file_storage();

    if ($filearea === 'package') {
        $filepath = is_null($filepath) ? '/' : $filepath;
        $filename = is_null($filename) ? '.' : $filename;

        $urlbase = $CFG->wwwroot.'/pluginfile.php';
        if (!$storedfile = $fs->get_file($context->id, 'mod_tincanlaunch', 'package', 0, $filepath, $filename)) {
            if ($filepath === '/' and $filename === '.') {
                $storedfile = new virtual_root_file($context->id, 'mod_tincanlaunch', 'package', 0);
            } else {
                // Not found.
                return null;
            }
        }
        return new file_info_stored($browser, $context, $storedfile, $urlbase, $areas[$filearea], false, true, false, false);
    }

    return false;
}

/**
 * Serves Tin Can content, introduction images and packages. Implements needed access control ;-)
 *
 * 
 * @param stdClass $course        course object
 * @param stdClass $cm            course module object
 * @param stdClass $context       context object
 * @param string   $filearea      file area
 * @param array    $args          extra arguments
 * @param bool     $forcedownload whether or not force download
 * @param array    $options       additional options affecting the file serving
 * 
 * @package  mod_tincanlaunch
 * @category files

 * @return bool false if file not found, if found, sends file and returns no value
 */
function tincanlaunch_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array())
{

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, true, $cm);

    $filename = array_pop($args);
    $filepath = implode('/', $args);
    if ($filearea === 'content') {
        $lifetime = null;
    } else if ($filearea === 'package') {
        $lifetime = 0; // No caching here.
    } else {
        return false;
    }

    $fs = get_file_storage();

    if (
        !$file = $fs->get_file($context->id, 'mod_tincanlaunch', $filearea, 0, '/'.$filepath.'/', $filename)
        or $file->is_directory()
    ) {
        if ($filearea === 'content') { // Return file not found straight away to improve performance.
            send_header_404();
            die;
        }
        return false;
    }

    // Finally send the file.
    send_stored_file($file, $lifetime, 0, false, $options);
}

/**
 * Export file resource contents for web service access.
 *
 * @param cm_info $cm Course module object.
 * @param string $baseurl Base URL for Moodle.
 * @return array array of file content
 */
function tincanlaunch_export_contents($cm, $baseurl) {
    $contents = array();
    $context = context_module::instance($cm->id);

    $fs = get_file_storage();
    $files = $fs->get_area_files($context->id, 'mod_tincanlaunch', 'package', 0, 'sortorder DESC, id ASC', false);

    foreach ($files as $fileinfo) {
        $file = array();
        $file['type'] = 'file';
        $file['filename']     = $fileinfo->get_filename();
        $file['filepath']     = $fileinfo->get_filepath();
        $file['filesize']     = $fileinfo->get_filesize();
        $fileurl = new moodle_url(
            $baseurl . '/'.$context->id.'/mod_tincanlaunch/package'. $fileinfo->get_filepath().$fileinfo->get_filename());
        $file['fileurl']      = $fileurl;
        $file['timecreated']  = $fileinfo->get_timecreated();
        $file['timemodified'] = $fileinfo->get_timemodified();
        $file['sortorder']    = $fileinfo->get_sortorder();
        $file['userid']       = $fileinfo->get_userid();
        $file['author']       = $fileinfo->get_author();
        $file['license']      = $fileinfo->get_license();
        $contents[] = $file;
    }

    return $contents;
}

// Called by Moodle core.
function tincanlaunch_get_completion_state($course, $cm, $userid, $type) {
    global $DB;
    $result = $type; // Default return value.

     // Get tincanlaunch.
    if (!$tincanlaunch = $DB->get_record('tincanlaunch', array('id' => $cm->instance))) {
        throw new Exception("Can't find activity {$cm->instance}"); // TODO: localise this.
    }

    $tincanlaunchsettings = tincanlaunch_settings($cm->instance);

    $expirydate = null;
    $expirydays = $tincanlaunch->tincanexpiry;
    if ($expirydays > 0) {
        $expirydatetime = new DateTime(); // Current data/time
        $expirydatetime->sub(new DateInterval('P'.$expirydays.'D')); // Date/time before which a completion should be counted as "expired"
        $expirydate = $expirydatetime->format('c');
    }

    if (!empty($tincanlaunch->tincanverbid)) {
        /*
         * Retrieve statements from LRS matching actor, object, and
         * completion verb (specificed in activity completion settings).
         */
        $user = $DB->get_record('user', array ('id' => $userid));
        $statementquery = tincanlaunch_get_statements(
            $tincanlaunchsettings['tincanlaunchlrsendpoint'],
            $tincanlaunchsettings['tincanlaunchlrslogin'],
            $tincanlaunchsettings['tincanlaunchlrspass'],
            $tincanlaunchsettings['tincanlaunchlrsversion'],
            $tincanlaunch->tincanactivityid,
            tincanlaunch_getactor($cm->instance, $user),
            $tincanlaunch->tincanverbid,
            $expirydate
        );

        // If the statement exists, return true else return false.
        if (!empty($statementquery->content) && $statementquery->success) {
            $result = true;
            // Check to see if the actual timestamp is within expiry.
            foreach ($statementquery->content as $statement) {
                $statementtimestamp = $statement->getTimestamp();
                if ($expirydate > $statementtimestamp) {
                    $result = false;
                }
            }
        } else {
            $result = false;
        }
    }

    return $result;
}

// TinCanLaunch specific functions.

/*
The functions below should really be in locallib, however they are required for one
or more of the functions above so need to be here.
It looks like the standard Quiz module does that same thing, so I don't feel so bad.
*/

/**
 * Handles uploaded zip packages when a module is added or updated. Unpacks the zip contents
 * and extracts the launch url and activity id from the tincan.xml file.
 * Note: This takes the *first* activity from the tincan.xml file to be the activity intended
 * to be launched. It will not go hunting for launch URLs any activities listed below.
 * Based closely on code from the SCORM and (to a lesser extent) Resource modules.
 * @package  mod_tincanlaunch
 * @category tincan
 * @param object $tincanlaunch An object from the form in mod_form.php
 * @return array empty if no issue is found. Array of error message otherwise
 */

function tincanlaunch_process_new_package($tincanlaunch) {
    global $DB, $CFG;

    $cmid = $tincanlaunch->coursemodule;
    $context = context_module::instance($cmid);

    // Reload TinCan instance.
    $record = $DB->get_record('tincanlaunch', array('id' => $tincanlaunch->id));

    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'mod_tincanlaunch', 'package');
    file_save_draft_area_files(
        $tincanlaunch->packagefile,
        $context->id,
        'mod_tincanlaunch',
        'package',
        0,
        array('subdirs' => 0, 'maxfiles' => 1)
    );

    // Get filename of zip that was uploaded.
    $files = $fs->get_area_files($context->id, 'mod_tincanlaunch', 'package', 0, '', false);
    if (count($files) < 1) {
        return false;
    }

    $zipfile = reset($files);
    $zipfilename = $zipfile->get_filename();

    $packagefile = false;

    $packagefile = $fs->get_file($context->id, 'mod_tincanlaunch', 'package', 0, '/', $zipfilename);

    $fs->delete_area_files($context->id, 'mod_tincanlaunch', 'content');

    $packer = get_file_packer('application/zip');
    $packagefile->extract_to_storage($packer, $context->id, 'mod_tincanlaunch', 'content', 0, '/');

    // If the tincan.xml file isn't there, don't do try to use it.
    // This is unlikely as it should have been checked when the file was validated.
    if ($manifestfile = $fs->get_file($context->id, 'mod_tincanlaunch', 'content', 0, '/', 'tincan.xml')) {
        $xmltext = $manifestfile->get_content();

        $pattern = '/&(?!\w{2,6};)/';
        $replacement = '&amp;';
        $xmltext = preg_replace($pattern, $replacement, $xmltext);

        $objxml = new xml2Array();
        $manifest = $objxml->parse($xmltext);

        // Update activity id from the first activity in tincan.xml, if it is found.
        // Skip without error if not. (The Moodle admin will need to enter the id manually).
        if (isset($manifest[0]["children"][0]["children"][0]["attrs"]["ID"])) {
            $record->tincanactivityid = $manifest[0]["children"][0]["children"][0]["attrs"]["ID"];
        }

        // Update launch from the first activity in tincan.xml, if it is found.
        // Skip if not. (The Moodle admin will need to enter the url manually).
        foreach ($manifest[0]["children"][0]["children"][0]["children"] as $property) {
            if ($property["name"] === "LAUNCH") {
                $record->tincanlaunchurl = $CFG->wwwroot."/pluginfile.php/".$context->id."/mod_tincanlaunch/"
                .$manifestfile->get_filearea()."/".$property["tagData"];
            }
        }
    }
    // Save reference.
    return $DB->update_record('tincanlaunch', $record);
}

/**
 * Check that a Zip file contains a tincan.xml file in the right place. Used in mod_form.php.
 * Heavily based on scorm_validate_package in /mod/scorm/lib.php
 * @package  mod_tincanlaunch
 * @category tincan
 * @param stored_file $file a Zip file.
 * @return array empty if no issue is found. Array of error message otherwise
 */
function tincanlaunch_validate_package($file) {
    $packer = get_file_packer('application/zip');
    $errors = array();
    $filelist = $file->list_files($packer);
    if (!is_array($filelist)) {
        $errors['packagefile'] = get_string('badarchive', 'tincanlaunch');
    } else {
        $badmanifestpresent = false;
        foreach ($filelist as $info) {
            if ($info->pathname == 'tincan.xml') {
                return array();
            } else if (strpos($info->pathname, 'tincan.xml') !== false) {
                // This package has tincan xml file inside a folder of the package.
                $badmanifestpresent = true;
            }
            if (preg_match('/\.cst$/', $info->pathname)) {
                return array();
            }
        }
        if ($badmanifestpresent) {
            $errors['packagefile'] = get_string('badimsmanifestlocation', 'tincanlaunch');
        } else {
            $errors['packagefile'] = get_string('nomanifest', 'tincanlaunch');
        }
    }
    return $errors;
}

/**
 * Fetches Statements from the LRS. This is used for completion tracking -
 * we check for a statement matching certain criteria for each learner.
 *
 * @package  mod_tincanlaunch
 * @category tincan
 * @param string $url LRS endpoint URL
 * @param string $basiclogin login/key for the LRS
 * @param string $basicpass pass/secret for the LRS
 * @param string $version version of xAPI to use
 * @param string $activityid Activity Id to filter by
 * @param TinCan Agent $agent Agent to filter by
 * @param string $verb Verb Id to filter by
 * @param string $since Since date to filter by
 * @return TinCan LRS Response
 */
function tincanlaunch_get_statements($url, $basiclogin, $basicpass, $version, $activityid, $agent, $verb, $since = null) {

    $lrs = new \TinCan\RemoteLRS($url, $version, $basiclogin, $basicpass);

    $statementsquery = array(
        "agent" => $agent,
        "verb" => new \TinCan\Verb(array("id" => trim($verb))),
        "activity" => new \TinCan\Activity(array("id" => trim($activityid))),
        "related_activities" => "false",
        "format" => "ids"
    );

    if (!is_null($since)) {
        $statementsquery["since"] = $since;
    }

    // Get all the statements from the LRS.
    $statementsresponse = $lrs->queryStatements($statementsquery);

    if ($statementsresponse->success == false) {
        return $statementsresponse;
    }

    $allthestatements = $statementsresponse->content->getStatements();
    $morestatementsurl = $statementsresponse->content->getMore();
    while (!empty($morestatementsurl)) {
        $morestmtsresponse = $lrs->moreStatements($morestatementsurl);
        if ($morestmtsresponse->success == false) {
            return $morestmtsresponse;
        }
        $morestatements = $morestmtsresponse->content->getStatements();
        $morestatementsurl = $morestmtsresponse->content->getMore();
        // Note: due to the structure of the arrays, array_merge does not work as expected.
        foreach ($morestatements as $morestatement) {
            array_push($allthestatements, $morestatement);
        }
    }

    return new \TinCan\LRSResponse(
        $statementsresponse->success,
        $allthestatements,
        $statementsresponse->httpResponse
    );
}

/**
 * Build a TinCan Agent based on the current user
 *
 * @package  mod_tincanlaunch
 * @category tincan
 * @return TinCan Agent $agent Agent
 */
function tincanlaunch_getactor($instance, $user = false) {
    global $USER, $CFG;

    // If Moodle cron didn't initiate this, user global $USER.
    if ($user == false) {
        $user = $USER;
    }

    $settings = tincanlaunch_settings($instance);

    if ($user->idnumber && $settings['tincanlaunchcustomacchp']) {
        $agent = array(
            "name" => fullname($user),
            "account" => array(
                "homePage" => $settings['tincanlaunchcustomacchp'],
                "name" => $user->idnumber
            ),
            "objectType" => "Agent"
        );
    } else if ($user->email && $settings['tincanlaunchuseactoremail']) {
        $agent = array(
            "name" => fullname($user),
            "mbox" => "mailto:".$user->email,
            "objectType" => "Agent"
        );
    } else {
        $agent = array(
            "name" => fullname($user),
            "account" => array(
                "homePage" => $CFG->wwwroot,
                "name" => $user->username
            ),
            "objectType" => "Agent"
        );
    }

    return new \TinCan\Agent($agent);
}



// GRADEBOOK API functions
function tincanlaunch_grade_settings_helper($modinstance, $tincanlaunchsettings=null)
{
    if ($tincanlaunchsettings == null) {
        $tincanlaunchsettings = new stdClass();
    }

    $settingvals = [
        'GRADE_TYPE' => [
            "NONE" => 0,
            "PASS_FAIL" => 1,
            "SCORED" => 2,
            "PERCENTAGE" => 3,
        ],
        'SUM_STAT' => [
            "MAX" => 0,
            "AVG" => 1,
            "RECENT" => 2,
        ]
    ];

    // Set default values in place.
    $settingvals['gradetype'] = $settingvals['GRADE_TYPE']['PERCENTAGE'];
    $settingvals["sumstat"] = $settingvals['SUM_STAT']['RECENT'];

    if (isset($tincanlaunchsettings->tincanlaunchlrsGradeOption)) {
        $settingvals['gradetype'] = $tincanlaunchsettings->tincanlaunchlrsGradeOption;
    }
    if (isset($tincanlaunchsettings->tincanlaunchGradeComboMethod)) {
        $settingvals["sumstat"] = $tincanlaunchsettings->tincanlaunchGradeComboMethod;
    }

    return $settingvals;
}

function tincanlaunch_get_lrs_grade_data($modinstance, $userid) {
    require_once($CFG->libdir.'/gradelib.php');

    // Get tincanlaunch.
    if (!$tincanlaunch = $DB->get_record('tincanlaunch', array('id' => $modinstance->cmidnumber))) {
        throw new Exception("Can't find activity {$modinstance->cmidnumber}"); // TODO: localise this.
    }

    $tincanlaunchsettings = tincanlaunch_settings($modinstance->cmidnumber);

    $gradesettings = tincanlaunch_grade_settings_helper($modinstance, $tincanlaunchsettings);

    $expiryenabled = false;
    $expirydate = null;
    $expirydays = $tincanlaunch->tincanexpiry;
    if ($expirydays > 0) {
        $expirydatetime = new DateTime(); // Current data/time
        $expirydatetime->sub(new DateInterval('P'.$expirydays.'D')); // Date/time before which a completion should be counted as "expired"
        $expirydate = $expirydatetime->format('c');
    }

    $gradesource = new stdClass();

    // Assign grade if user exists and grade type is not "NONE".
    if ($gradesettings['gradetype'] != $gradesettings['GRADE_TYPE']['NONE'] && $userid != 0) {
        $user = $DB->get_record('user', array ('id' => $userid));
        $statementquery = tincanlaunch_get_statements(
            $tincanlaunchsettings['tincanlaunchlrsendpoint'],
            $tincanlaunchsettings['tincanlaunchlrslogin'],
            $tincanlaunchsettings['tincanlaunchlrspass'],
            $tincanlaunchsettings['tincanlaunchlrsversion'],
            $tincanlaunch->tincanactivityid,
            tincanlaunch_getactor($modinstance->cmidnumber, $user),
            $tincanlaunch->tincanverbid
        );

        // If the statement exists, return true else return false.
        if (!empty($statementquery->content) && $statementquery->success) {

            // Collect all scores where the actual timestamp is within expiry.
            $scores = array();
            $mostrecentstatement;
            $mostrecentstatementtimestamp = 0;
            foreach ($statementquery->content as $statement) {
                $statementtimestamp = $statement->getTimestamp();
                if ($expiryenabled && $expirydate <= $statementtimestamp) {
                    $score = $statement->getResult()->getScore();
                    $scores[] = $score;

                    if (!isset($mostrecentstatement) || $statementtimestamp > $mostrecentstatementtimestamp) {
                        $mostrecentstatementtimestamp = $statementtimestamp;
                        $mostrecentstatement = $statement;
                    }
                }
            }

            // If no scores are within expiration, end.
            if (count($scores) != 0) {
                $mostrecentscore = $mostrecentstatement->getResult()->getScore();

                $gradesource->min = $mostrecentscore->getMin();
                $gradesource->max = $mostrecentscore->getMax();

                // Calculate score information.
                if ($gradesettings['sum_stat'] == $gradesettings['SUM_STAT']['RECENT']) {
                    $gradesource->raw = $mostrecentscore->getRaw();
                } else { // Any setting which combines scores
                    // Filter scores for only those which have the same scoring structure as the most recent score
                    // because all scores must have the same structure to be averaged or summed.
                    $filteredscores = array();
                    foreach ($scores as $score) {
                        if ($score->getMin() == $mostrecentscore->getMin() && $score->getMax() == $mostrecentscore->getMax()) {
                            $filteredscores[] = $score;
                        }
                    }

                    // Aggregate results (method determined by setting).
                    if ($gradesettings['gradetype'] == $gradesettings['GRADE_TYPE']['AVG']) {
                        $gradesource->raw = 0;
                        foreach ($filteredscores as $score) {
                            $gradesource->raw += $score->getRaw();
                        }
                        $gradesource->raw /= count($filteredscores);
                    } else if ($gradesettings['gradetype'] == $gradesettings['GRADE_TYPE']['BEST']) {
                        $gradesource->raw = 0;
                        foreach ($filteredscores as $score) {
                            if ($score->getRaw() > $gradesource->raw) {
                                $gradesource->raw = $score->getRaw();
                            }
                        }
                    }
                }

                return $gradesource;
            }
        }
    }

    return null;
}

function tincanlaunch_get_user_grades($modinstance, $userid = 0) {

    $tincanlaunchsettings = tincanlaunch_settings($modinstance->cmidnumber);
    $gradesettings = tincanlaunch_grade_settings_helper($modinstance, $tincanlaunchsettings);

    if ($userid == 0) {
        return null;
    }

    $gradesource = tincanlaunch_get_lrs_grade_data($modinstance, $userid);

    // Actually send call to tincanlaunch_grade_item_update().
    $grade = new stdClass();
    $grade->userid = $userid;
    switch($gradesettings['gradetype']) {
        case $gradesettings['GRADE_TYPE']['SCORE']:
            $grade->rawgrade = $gradesource->raw;
            $grade->rawgrademin = $gradesource->min;
            $grade->rawgrademax = $gradesource->max;
        case $gradesettings['GRADE_TYPE']['PERCENTAGE']:
            $grade->rawgrade = 100 * $gradesource->raw / $gradesource->max;
            $grade->rawgrademin = 0;
            $grade->rawgrademax = 100;
            break;
        default:
            break;
    }

    return $grade;
}

function tincanlaunch_update_grades($modinstance, $userid=0, $nullifnone=true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if ($quiz->grade == 0) {
        tincanlaunch_grade_item_update($quiz);

    } else if ($grades = tincanlaunch_get_user_grades($quiz, $userid)) {
        tincanlaunch_grade_item_update($quiz, $grades);

    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        tincanlaunch_grade_item_update($quiz, $grade);

    } else {
        tincanlaunch_grade_item_update($quiz);
    }
}

function tincanlaunch_grade_item_update($modinstance, $grades=null) {
    global $CFG, $DB;
    if (!function_exists('grade_update')) { // Workaround for buggy PHP versions.
        require_once($CFG->libdir.'/gradelib.php');
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    } else {
        $gradesettings = tincanlaunch_grade_settings_helper($modinstance);

        $params = array('itemname' => $modinstance->name, 'idnumber' => $modinstance->cmidnumber);

        switch($gradesettings['gradetype']){
            case $gradesettings['GRADE_TYPE']['NONE']:
                $params['gradetype'] = GRADE_TYPE_NONE;
                break;
            case $gradesettings['GRADE_TYPE']['PERCENTAGE']:
                $params['gradetype'] = GRADE_TYPE_VALUE;
                $params['grademax'] = 100;
                $params['grademin'] = 0;
                break;
            default:
                break;
        }
    }

    return grade_update('mod/tincanlaunch', $modinstance->course, 'mod', 'tincanlaunch', $modinstance->id, 0, $grades, $params);
}

// function tincanlaunch_update_grades($modinstance, $userid=0, $nullifnone=true) {
//     global $CFG, $DB;
//     require_once($CFG->libdir.'/gradelib.php');

//     // Get tincanlaunch.
//     if (!$tincanlaunch = $DB->get_record('tincanlaunch', array('id' => $modinstance->cmidnumber))) {
//         throw new Exception("Can't find activity {$modinstance->cmidnumber}"); // TODO: localise this.
//     }

//     $tincanlaunchsettings = tincanlaunch_settings($modinstance->cmidnumber);

//     $gradesettings = tincanlaunch_grade_settings_helper($modinstance, $tincanlaunchsettings);

//     $expiryenabled = false;
//     $expirydate = null;
//     $expirydays = $tincanlaunch->tincanexpiry;
//     if ($expirydays > 0) {
//         $expirydatetime = new DateTime(); // Current data/time
//         $expirydatetime->sub(new DateInterval('P'.$expirydays.'D')); // Date/time before which a completion should be counted as "expired"
//         $expirydate = $expirydatetime->format('c');
//     }

//     $gradesource = new stdClass();

//     // Assign grade if user exists and grade type is not "NONE".
//     if ($gradesettings['gradetype'] != $gradesettings['GRADE_TYPE']['NONE'] && $userid != 0) {
//         $user = $DB->get_record('user', array ('id' => $userid));
//         $statementquery = tincanlaunch_get_statements(
//             $tincanlaunchsettings['tincanlaunchlrsendpoint'],
//             $tincanlaunchsettings['tincanlaunchlrslogin'],
//             $tincanlaunchsettings['tincanlaunchlrspass'],
//             $tincanlaunchsettings['tincanlaunchlrsversion'],
//             $tincanlaunch->tincanactivityid,
//             tincanlaunch_getactor($modinstance->cmidnumber, $user),
//             $tincanlaunch->tincanverbid
//         );

//         // If the statement exists, return true else return false.
//         if (!empty($statementquery->content) && $statementquery->success) {

//             // Collect all scores where the actual timestamp is within expiry.
//             $scores = array();
//             $mostrecentstatement;
//             $mostrecentstatementtimestamp = 0;
//             foreach ($statementquery->content as $statement) {
//                 $statementtimestamp = $statement->getTimestamp();
//                 if ($expiryenabled && $expirydate <= $statementtimestamp) {
//                     $score = $statement->getResult()->getScore();
//                     $scores[] = $score;

//                     if (!isset($mostrecentstatement) || $statementtimestamp > $mostrecentstatementtimestamp) {
//                         $mostrecentstatementtimestamp = $statementtimestamp;
//                         $mostrecentstatement = $statement;
//                     }
//                 }
//             }

//             // If no scores are within expiration, end.
//             if (count($scores) != 0) {
//                 $mostrecentscore = $mostrecentstatement->getResult()->getScore();

//                 $gradesource->min = $mostrecentscore->getMin();
//                 $gradesource->max = $mostrecentscore->getMax();

//                 // Calculate score information.
//                 if ($gradesettings['sum_stat'] == $gradesettings['SUM_STAT']['RECENT']) {
//                     $gradesource->raw = $mostrecentscore->getRaw();
//                 } else { // Any setting which combines scores
//                     // Filter scores for only those which have the same scoring structure as the most recent score
//                     // because all scores must have the same structure to be averaged or summed.
//                     $filteredscores = array();
//                     foreach ($scores as $score) {
//                         if ($score->getMin() == $mostrecentscore->getMin() && $score->getMax() == $mostrecentscore->getMax()) {
//                             $filteredscores[] = $score;
//                         }
//                     }

//                     // Aggregate results (method determined by setting).
//                     if ($gradesettings['gradetype'] == $gradesettings['GRADE_TYPE']['AVG']) {
//                         $gradesource->raw = 0;
//                         foreach ($filteredscores as $score) {
//                             $gradesource->raw += $score->getRaw();
//                         }
//                         $gradesource->raw /= count($filteredscores);
//                     } else if ($gradesettings['gradetype'] == $gradesettings['GRADE_TYPE']['BEST']) {
//                         $gradesource->raw = 0;
//                         foreach ($filteredscores as $score) {
//                             if ($score->getRaw() > $gradesource->raw) {
//                                 $gradesource->raw = $score->getRaw();
//                             }
//                         }
//                     }
//                 }

//                 // Actually send call to tincanlaunch_grade_item_update().
//                 $grade = new stdClass();
//                 $grade->userid = $userid;
//                 switch($gradesettings['gradetype']) {
//                     case $gradesettings['GRADE_TYPE']['PERCENTAGE']:
//                         $grade->rawgrade = $gradesource->raw / $gradesource->max;
//                         break;
//                     default:
//                         break;
//                 }

//                 tincanlaunch_grade_item_update($modinstance, $grade);
//             }
//         }
//     }

//     if ($nullifnone) {
//         $grade = new stdClass();
//         $grade->userid   = $userid;
//         $grade->rawgrade = null;
//         tincanlaunch_grade_item_update($forum, $grade);
//     }
// }

/**
 * Helper function to reset gradebook data
 * @package mod_tincanlaunch
 * @param number $courseid the courseID number
 * @param string $type this isn't used?
 */
function tincanlauch_reset_gradebook($courseid, $type='') {
    global $DB;

    // TODO: Get Quizzes from DB.
    $activities = array();
    /*
    $DB->get_records_sql("
            SELECT q.*, cm.idnumber as cmidnumber, q.course as courseid
            FROM {modules} m
            JOIN {course_modules} cm ON m.id = cm.module
            JOIN {quiz} q ON cm.instance = q.id
            WHERE m.name = 'quiz' AND cm.course = ?", array($courseid));
    */

    foreach ($activities as $activity) {
        tincanlaunch_grade_item_update($activity, 'reset');
    }
}

// End GRADEBOOK API functions

/**
 * Returns the LRS settings relating to a Tin Can Launch module instance
 *
 * @package  mod_tincanlaunch
 * @category tincan
 * @param string $instance The Moodle id for the Tin Can module instance.
 * @return array LRS settings to use
 */
function tincanlaunch_settings($instance) {
    global $DB, $tincanlaunchsettings;

    if (!is_null($tincanlaunchsettings)) {
        return $tincanlaunchsettings;
    }

    $expresult = array();
    $conditions = array('tincanlaunchid' => $instance);
    $fields = '*';
    $strictness = 'IGNORE_MISSING';
    $activitysettings = $DB->get_record('tincanlaunch_lrs', $conditions, $fields, $strictness);

    // If global settings are not used, retrieve activity settings.
    if (!use_global_lrs_settings($instance)) {
        $expresult['tincanlaunchlrsendpoint'] = $activitysettings->lrsendpoint;
        $expresult['tincanlaunchlrsauthentication'] = $activitysettings->lrsauthentication;
        $expresult['tincanlaunchlrslogin'] = $activitysettings->lrslogin;
        $expresult['tincanlaunchlrspass'] = $activitysettings->lrspass;
        $expresult['tincanlaunchcustomacchp'] = $activitysettings->customacchp;
        $expresult['tincanlaunchuseactoremail'] = $activitysettings->useactoremail;
        $expresult['tincanlaunchlrsduration'] = $activitysettings->lrsduration;
    } else { // Use global lrs settings.
        $result = $DB->get_records('config_plugins', array('plugin' => 'tincanlaunch'));
        foreach ($result as $value) {
            $expresult[$value->name] = $value->value;
        }
    }

    $expresult['tincanlaunchlrsversion'] = '1.0.0';

    $tincanlaunchsettings = $expresult;
    return $expresult;
}

/**
 * Should the global LRS settings be used instead of the instance specific ones?
 *
 * @package  mod_tincanlaunch
 * @category tincan
 * @param string $instance The Moodle id for the Tin Can module instance.
 * @return bool
 */
function use_global_lrs_settings($instance) {
    global $DB;
    // Determine if there is a row in tincanlaunch_lrs matching the current activity id.
    $activitysettings = $DB->get_record('tincanlaunch', array('id' => $instance));
    if ($activitysettings->overridedefaults == 1) {
        return false;
    }
    return true;
}
