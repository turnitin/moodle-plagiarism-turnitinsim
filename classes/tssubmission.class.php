<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Submission class for plagiarism_turnitinsim component
 *
 * @package   plagiarism_turnitinsim
 * @copyright 2017 John McGettrick <jmcgettrick@turnitin.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use plagiarism_turnitinsim\message\receipt_instructor;
use plagiarism_turnitinsim\message\receipt_student;

require_once($CFG->dirroot . '/plagiarism/turnitinsim/classes/modules/tsassign.class.php');
require_once($CFG->dirroot . '/plagiarism/turnitinsim/classes/modules/tsforum.class.php');
require_once($CFG->dirroot . '/plagiarism/turnitinsim/classes/modules/tsworkshop.class.php');

class tssubmission {

    public $id;
    public $cm;
    public $userid;
    public $groupid;
    public $submitter;
    public $turnitinid;
    public $status;
    public $identifier;
    public $itemid;
    public $type;
    public $submittedtime;
    public $togenerate;
    public $generationtime;
    public $requestedtime;
    public $overallscore;
    public $errormessage;
    public $tsrequest;

    public function __construct(tsrequest $tsrequest = null, $id = null) {
        global $DB;

        $this->setid($id);
        $this->tsrequest = ($tsrequest) ? $tsrequest : new tsrequest();
        $this->plagiarism_plugin_turnitinsim = new plagiarism_plugin_turnitinsim();

        if (!empty($id)) {
            $submission = $DB->get_record('plagiarism_turnitinsim_sub', array('id' => $id));

            $this->setcm($submission->cm);
            $this->setuserid($submission->userid);
            $this->setsubmitter($submission->submitter);
            $this->setgroupid($submission->groupid);
            $this->setturnitinid($submission->turnitinid);
            $this->setstatus($submission->status);
            $this->setidentifier($submission->identifier);
            $this->setitemid($submission->itemid);
            $this->settogenerate($submission->to_generate);
            $this->setgenerationtime($submission->generation_time);
            $this->settype($submission->type);
            $this->setsubmittedtime($submission->submitted_time);
            $this->setoverallscore($submission->overall_score);
            $this->setrequestedtime($submission->requested_time);
            $this->seterrormessage($submission->errormessage);
        }
    }

    /**
     * Save the submission data to the files table.
     */
    public function update() {
        global $DB;

        if (!empty($this->id)) {
            $DB->update_record('plagiarism_turnitinsim_sub', $this);
        } else {
            $id = $DB->insert_record('plagiarism_turnitinsim_sub', $this);
            $this->setid($id);
        }

        return true;
    }

    /**
     * Set the generation time for a paper.
     */
    public function calculate_generation_time($generated = false) {
        $cm = get_coursemodule_from_id('', $this->getcm());
        $plagiarismsettings = $this->plagiarism_plugin_turnitinsim->get_settings($cm->id);

        // Create module object.
        $moduleclass =  'ts'.$cm->modname;
        $moduleobject = new $moduleclass;

        $duedate = $moduleobject->get_due_date($cm->instance);

        // If the report has already generated then only proceed if report speed is 1.
        if ($generated && $plagiarismsettings->reportgeneration != TURNITINSIM_REPORT_GEN_IMMEDIATE_AND_DUEDATE) {
            $this->settogenerate(0);
            return;
        }

        // Set Generation Time dependent on report generation speed.
        switch ($plagiarismsettings->reportgeneration) {

            // Generate Immediately.
            case TURNITINSIM_REPORT_GEN_IMMEDIATE:
                $this->settogenerate(1);
                $this->setgenerationtime(time());
                break;

            // Generate Immediately, and on Due Date (only applicable to assignments).
            case TURNITINSIM_REPORT_GEN_IMMEDIATE_AND_DUEDATE:

                // If submission hasn't been processed yet then generate immediately.
                $immediatestatuses = array(
                    TURNITINSIM_SUBMISSION_STATUS_QUEUED,
                    TURNITINSIM_SUBMISSION_STATUS_UPLOADED
                );

                // Set the report generation time.
                $this->settogenerate(1);
                if (in_array($this->getstatus(), $immediatestatuses)) {
                    $this->setgenerationtime(time());
                } else {
                    $this->setgenerationtime($duedate);
                }

                // If the duedate has past and the report has already been generated then we don't want to regenerate.
                if ($duedate < time() && $generated) {
                    $this->settogenerate(0);
                    $this->setgenerationtime(null);
                }

                break;

            // Generate on Due Date (only applicable to assignments).
            case TURNITINSIM_REPORT_GEN_DUEDATE:
                $this->settogenerate(1);
                if ($duedate > time()) {
                    $this->setgenerationtime($duedate);
                } else {
                    $this->setgenerationtime(time());
                }
                break;
        }

        return;
    }

    /**
     * Build a user array entry from a passed in user object for submission metadata.
     *
     * @param $user
     * @return mixed
     */
    public function build_user_array_entry($user) {

        // If there is no user object return false.
        if (empty($user)) {
             return;
        }

        // Create tsuser object so we get turnitin id.
        $tsuser = new tsuser($user->id);

        return array(
            'id' => $tsuser->get_turnitinid(),
            'family_name' => $user->lastname,
            'given_name' => $user->firstname,
            'email' => $user->email
        );
    }

    /*
     * Compile metadata for submission request.
     *
     * return mixed
     */
    public function create_group_metadata() {
        global $DB;

        if (!$cm = get_coursemodule_from_id('', $this->getcm())) {
            return false;
        }

        // Add assignment metadata.
        $assignment = array(
            'id'   => $cm->id,
            'name' => $cm->name,
            'type' => TURNITINSIM_GROUP_TYPE_ASSIGNMENT
        );

        // Add course metadata.
        $coursedetails = $DB->get_record('course', array('id' => $cm->course), 'fullname');
        $course = array(
            'id'   => $cm->course,
            'name' => $coursedetails->fullname
        );

        // Get all the instructors in the course.
        $instructors = get_enrolled_users(
            context_module::instance($cm->id),
            'plagiarism/turnitinsim:viewfullreport',
            0, 'u.id, u.firstname, u.lastname, u.email', 'u.id'
        );

        // Add instructors to the owners array.
        foreach ($instructors as $instructor) {
            $course['owners'][] = $this->build_user_array_entry($instructor);
        }

        // Add metadata to request.
        return array(
            'group'         => $assignment,
            'group_context' => $course
        );
    }

    /**
     * Add the owners in to the metadata.
     *
     * return array of userdata / empty
     */
    public function create_owners_metadata() {
        global $DB;
        $owners = array();

        // If this is a group submission then add all group users as owners.
        if (!empty($this->getgroupid())) {
            $groupmembers = groups_get_members($this->getgroupid(), "u.id, u.firstname, u.lastname, u.email", "u.id");
            foreach ($groupmembers as $member) {
                $owners[] = $this->build_user_array_entry($member);
            }
        } else if (!empty($this->getuserid())) {
            $owner = $DB->get_record('user', array('id' => $this->getuserid()));
            $owners[] = $this->build_user_array_entry($owner);
        } else {
            return;
        }

        return $owners;
    }

    /**
     * Return the submission owner, this will be the group id for group submissions.
     *
     * @return integer Turnitin id identifying the owner.
     */
    public function get_owner() {
        if (!empty($this->getgroupid())) {
            $tsgroup = new tsgroup($this->getgroupid());
            return $tsgroup->get_turnitinid();
        }

        $tsauthor = new tsuser($this->getuserid());
        return $tsauthor->get_turnitinid();
    }

    /**
     * Creates a submission record in Turnitin.
     */
    public function create_submission_in_turnitin() {

        $tssubmitter = new tsuser($this->getsubmitter());
        $filedetails = $this->get_file_details();

        // Initialise request with owner and submitter.
        $request = array(
            'owner' => $this->get_owner(),
            'submitter' => $tssubmitter->get_turnitinid()
        );

        // Add submission title to request.
        if ($filedetails) {
            $request['title'] = str_replace('%20', ' ', rawurlencode($filedetails->get_filename()));
        } else {
            $request['title'] = 'onlinetext_'.$this->id.'_'.$this->cm.'_'.$this->itemid.'.txt';
        }

        // Create group related metadata.
        $request['metadata'] = $this->create_group_metadata();

        // Add owners to the metadata.
        $request['metadata']['owners'] = $this->create_owners_metadata();

        // Add EULA acceptance details to submission if the submitter has accepted it.
        $language = $this->tsrequest->get_language()->localecode;
        $locale = ($tssubmitter->get_lasteulaacceptedlang()) ? $tssubmitter->get_lasteulaacceptedlang() : $language;

        // Get the features enabled so we can check if EULAis required for this tenant.
        $features = json_decode(get_config('plagiarism', 'turnitin_features_enabled'));

        // Include EULA metadata if necessary.
        if (!empty($tssubmitter->get_lasteulaaccepted()) || !(bool)$features->tenant->require_eula) {
            $request['eula'] = array(
                'accepted_timestamp' => gmdate("Y-m-d\TH:i:s\Z", ($tssubmitter->get_lasteulaacceptedtime())),
                'language' => $locale,
                'version' => $tssubmitter->get_lasteulaaccepted()
            );
        }

        // Make request to create submission record in Turnitin.
        try {
            $response = $this->tsrequest->send_request(
                TURNITINSIM_ENDPOINT_CREATE_SUBMISSION,
                json_encode($request),
                'POST'
            );
            $responsedata = json_decode($response);

            $this->handle_create_submission_response($responsedata);

        } catch (Exception $e) {

            // This should only ever fail due to a failed connection to Turnitin so we will leave the paper as queued.
            $this->tsrequest->handle_exception($e, 'taskoutputfailedconnection');
        }
    }

    /**
     * Handle the API create submission response.
     *
     * @param $params
     */
    public function handle_create_submission_response($params) {

        switch ($params->httpstatus) {
            case TURNITINSIM_HTTP_CREATED:
                // Handle a TURNITINSIM_HTTP_CREATED repsonse.
                $this->setturnitinid($params->id);
                $this->setstatus($params->status);
                $this->setsubmittedtime(strtotime($params->created_time));

                mtrace(get_string('taskoutputsubmissioncreated', 'plagiarism_turnitinsim', $params->id));

                break;

            case TURNITINSIM_HTTP_UNAVAILABLE_FOR_LEGAL_REASONS:
                // Handle the response for a user who has not accepted the EULA.
                $this->setstatus(TURNITINSIM_SUBMISSION_STATUS_EULA_NOT_ACCEPTED);
                $this->setsubmittedtime(time());

                mtrace(get_string('taskoutputsubmissionnotcreatedeula', 'plagiarism_turnitinsim'));

                break;

            default:
                $this->setstatus(TURNITINSIM_SUBMISSION_STATUS_ERROR);
                mtrace(get_string('taskoutputsubmissionnotcreatedgeneral', 'plagiarism_turnitinsim'));
                break;
        }

        $this->update();
    }

    /**
     * Uploads a file to the Turnitin submission.
     */
    public function upload_submission_to_turnitin() {
        // Create request body with file attached.
        if ($this->type == "file") {
            $filedetails = $this->get_file_details();
            // Encode filename.
            $filename = str_replace('%20', ' ', rawurlencode($filedetails->get_filename()));

            $textcontent = $filedetails->get_content();
        } else {
            // Get cm and modtype.
            $cm = get_coursemodule_from_id('', $this->getcm());

            // Create module object.
            $moduleclass =  'ts'.$cm->modname;
            $moduleobject = new $moduleclass;

            // Add text content to request.
            $filename = 'onlinetext_'.$this->id.'_'.$this->cm.'_'.$this->itemid.'.txt';
            $textcontent = html_to_text($moduleobject->get_onlinetext($this->getitemid()));
        }

        // Add content to request.
        $request = $textcontent;

        // Add additional headers to request.
        $additionalheaders = array(
            'Content-Type: binary/octet-stream',
            'Content-Disposition: inline; filename="'.$filename.'"'
        );

        $this->tsrequest->add_additional_headers($additionalheaders);

        // Make request to add file to submission.
        try {
            $endpoint = TURNITINSIM_ENDPOINT_UPLOAD_SUBMISSION;
            $endpoint = str_replace('{{submission_id}}', $this->getturnitinid(), $endpoint);
            $response = $this->tsrequest->send_request($endpoint, $request, 'PUT', 'submission');
            $responsedata = json_decode($response);

            // Handle response from the API.
            $this->handle_upload_response($responsedata, $filename);
        } catch (Exception $e) {

            $this->tsrequest->handle_exception($e, 'taskoutputfailedupload', $this->getturnitinid());
        }
    }

    /**
     * Handle the API submission response and callback from Turnitin.
     *
     * @param $params
     */
    public function handle_upload_response($params, $filename) {
        // Update submission status.
        mtrace( get_string('taskoutputfileuploaded', 'plagiarism_turnitinsim', $this->getturnitinid()));
        if (!empty($params->httpstatus)) {
            $status = ($params->httpstatus == TURNITINSIM_HTTP_ACCEPTED) ?
                TURNITINSIM_SUBMISSION_STATUS_UPLOADED : TURNITINSIM_SUBMISSION_STATUS_ERROR;
        } else {
            $status = (!empty($params->status) && $params->status == TURNITINSIM_SUBMISSION_STATUS_COMPLETE) ?
                TURNITINSIM_SUBMISSION_STATUS_UPLOADED : TURNITINSIM_SUBMISSION_STATUS_ERROR;
        }
        $this->setstatus($status);

        // Save error message if request has errored, otherwise send digital receipts.
        if ($status == TURNITINSIM_SUBMISSION_STATUS_ERROR) {
            $this->seterrormessage($params->message);
        } else {
            $this->send_digital_receipts($filename);
        }
        $this->update();
    }

    /**
     * Send digital receipts to the instructors and student.
     *
     * @param $filename
     */
    public function send_digital_receipts($filename) {
        global $DB;

        // Get user and course details.
        $user = $DB->get_record('user', array('id' => $this->getuserid()));
        $cm = get_coursemodule_from_id('', $this->getcm());
        $course = $DB->get_record('course', array('id' => $cm->course));

        // Send a message to the user's Moodle inbox with the digital receipt.
        $receiptcontent = array(
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'submission_title' => $filename,
            'module_name' => $cm->name,
            'course_fullname' => $course->fullname,
            'submission_date' => date('d-M-Y h:iA', $this->getsubmittedtime()),
            'submission_id' => $this->getturnitinid()
        );

        // Student digital receipt.
        $receipt = new receipt_student();
        $message = $receipt->build_message($receiptcontent);
        $receipt->send_message($user->id, $message, $course->id);

        // Instructor digital receipt.
        $receipt = new receipt_instructor();
        $message = $receipt->build_message($receiptcontent);

        // Get Instructors.
        $instructors = get_enrolled_users(
            context_module::instance($cm->id),
            'plagiarism/turnitinsim:viewfullreport',
            groups_get_activity_group($cm),
            'u.id'
        );

        $receipt->send_message($instructors, $message, $course->id);
    }

    /**
     * Request a Turnitin report to be generated.
     */
    public function request_turnitin_report_generation() {

        // Get module settings.
        $plugin = new plagiarism_plugin_turnitinsim();
        $modulesettings = $plugin->get_settings($this->getcm());
        $cm = get_coursemodule_from_id('', $this->getcm());

        // Create module helper object.
        $moduleclass =  'ts'.$cm->modname;
        $moduleobject = new $moduleclass;

        // Configure request body array.
        $request = array();

        // Indexing settings. Don't index drafts.
        $draft = $moduleobject->is_submission_draft($this->getitemid());
        if (!empty($modulesettings->addtoindex) && !$draft) {
            $request['indexing_settings'] = array('add_to_index' => true);
        }

        // Generation Settings.
        // Configure repositories to search.
        $features = json_decode(get_config('plagiarism', 'turnitin_features_enabled'));
        $searchrepositories = $features->similarity->generation_settings->search_repositories;
        $request['generation_settings'] = array('search_repositories' => $searchrepositories);
        $request['generation_settings']['auto_exclude_self_matching_scope'] = TURNITINSIM_REPORT_GEN_EXCLUDE_SELF_GROUP;

        // View Settings.
        $request['view_settings'] = array(
            'exclude_quotes' => (!empty($modulesettings->excludequotes)) ? true : false,
            'exclude_bibliography' => (!empty($modulesettings->excludebiblio)) ? true : false
        );

        // Make request to generate report.
        try {
            $endpoint = TURNITINSIM_ENDPOINT_SIMILARITY_REPORT;
            $endpoint = str_replace('{{submission_id}}', $this->getturnitinid(), $endpoint);
            $response = $this->tsrequest->send_request($endpoint, json_encode($request), 'PUT');
            $responsedata = json_decode($response);

            // Update submission status.
            mtrace('Turnitin Originality Report requested for: '.$this->getturnitinid());

            $status = ($responsedata->httpstatus == TURNITINSIM_HTTP_ACCEPTED) ?
                TURNITINSIM_SUBMISSION_STATUS_REQUESTED : TURNITINSIM_SUBMISSION_STATUS_ERROR;
            $this->setstatus($status);
            // Save error message if request has errored.
            if ($status == TURNITINSIM_SUBMISSION_STATUS_ERROR) {
                $this->seterrormessage($responsedata->message);
            }
            $this->setrequestedtime(time());
            $this->calculate_generation_time(true);
            $this->update();

        } catch (Exception $e) {

            $this->tsrequest->handle_exception($e, 'taskoutputfailedreportrequest', $this->getturnitinid());
        }
    }

    /**
     * Request a report score from Turnitin.
     */
    public function request_turnitin_report_score() {

        // Make request to get report score.
        try {
            $endpoint = TURNITINSIM_ENDPOINT_SIMILARITY_REPORT;
            $endpoint = str_replace('{{submission_id}}', $this->getturnitinid(), $endpoint);
            $response = $this->tsrequest->send_request($endpoint, json_encode(array()), 'GET');
            $responsedata = json_decode($response);

            $this->handle_similarity_response($responsedata);
        } catch (Exception $e) {

            $this->tsrequest->handle_exception($e, 'taskoutputfailedscorerequest', $this->getturnitinid());
        }
    }

    /**
     * Handle the API similarity response and callback from Turnitin.
     *
     * @param $params
     */
    public function handle_similarity_response($params) {
        // Update submission details.
        mtrace('Turnitin Originality Report score retrieved for: ' . $this->getturnitinid());

        if (isset($params->status)) {
            $this->setstatus($params->status);
        }
        if (isset($params->overall_match_percentage)) {
            $this->setoverallscore($params->overall_match_percentage);
            $this->calculate_generation_time(true);
        }
        $this->update();
    }

    /**
     * Get the details for a submission from the Moodle database.
     *
     * @param $linkarray
     * @return mixed
     */
    public static function get_submission_details($linkarray) {
        global $DB;

        static $cm;
        if (empty($cm)) {
            $cm = get_coursemodule_from_id('', $linkarray["cmid"]);

            if ($cm->modname == 'forum') {
                if (! $forum = $DB->get_record("forum", array("id" => $cm->instance))) {
                    print_error('invalidforumid', 'forum');
                }
            }
        }

        if (!empty($linkarray['file'])) {
            $file = $linkarray['file'];
            $itemid = $file->get_itemid();
            $identifier = $file->get_pathnamehash();

            // Get correct user id that submission is for rather than who submitted it this only affects
            // mod_assign file submissions and group submissions.
            if ($itemid != 0 && $cm->modname == "assign") {
                $assignsubmission = $DB->get_record('assign_submission', array('id' => $itemid), 'groupid, userid');

                if (empty($assignsubmission->userid)) {
                    // Group submission.
                    return $DB->get_record('plagiarism_turnitinsim_sub', array('itemid' => $itemid,
                        'cm' => $linkarray['cmid'], 'identifier' => $identifier));
                } else {
                    // Submitted on behalf of student.
                    $linkarray['userid'] = $assignsubmission->userid;
                }
            }
        } else if (!empty($linkarray["content"])) {

            $identifier = sha1($linkarray['content']);

            // If user id is empty this must be a group submission.
            if (empty($linkarray['userid'])) {
                return $DB->get_record('plagiarism_turnitinsim_sub', array('identifier' => $identifier,
                    'type' => 'content', 'cm' => $linkarray['cmid']));
            }
        }

        return $DB->get_record('plagiarism_turnitinsim_sub', array('userid' => $linkarray['userid'],
            'cm' => $linkarray['cmid'], 'identifier' => $identifier));
    }

    /**
     * Create the cloud viewer permissions array to send when requesting a viewer launch URL.
     *
     * @return array
     */
    public function create_report_viewer_permissions() {
        $turnitinviewerviewfullsource = get_config('plagiarism', 'turnitinviewerviewfullsource');
        $turnitinviewermatchsubinfo = get_config('plagiarism', 'turnitinviewermatchsubinfo');
        $turnitinviewersavechanges = get_config('plagiarism', 'turnitinviewersavechanges');

        return array(
            'may_view_submission_full_source' => (!empty($turnitinviewerviewfullsource)) ? true : false,
            'may_view_match_submission_info' => (!empty($turnitinviewermatchsubinfo)) ? true : false,
            'may_view_save_viewer_changes' => (!empty($turnitinviewersavechanges)) ? true : false
        );
    }

    /**
     * Create the similarity report settings overrides to send when requesting a viewer launch URL.
     *
     * These are true but may be configurable in the future.
     *
     * @return array
     */
    public function create_similarity_overrides() {
        $turnitinviewersavechanges = get_config('plagiarism', 'turnitinviewersavechanges');

        return array(
            'modes' => array(
                'match_overview' => true,
                'all_sources' => true
            ),
            "view_settings" => array(
                "save_changes"  => (!empty($turnitinviewersavechanges)) ? true : false
            )
        );
    }

    /**
     * Request Cloud Viewer Launch URL.
     */
    public function request_cv_launch_url() {
        global $DB, $USER;

        // Make request to get cloud viewer launch url.
        $endpoint = TURNITINSIM_ENDPOINT_CV_LAUNCH;
        $endpoint = str_replace('{{submission_id}}', $this->getturnitinid(), $endpoint);

        // Build request.
        $lang = $this->tsrequest->get_language();
        $viewinguser = new tsuser($USER->id);
        $request = array(
            "locale" => $lang->langcode,
            "viewer_user_id" => $viewinguser->get_turnitinid()
        );

        // If submission is anonymous then do not send the student name.
        if (!$this->is_submission_anonymous()) {
            // Get submitter's details.
            $author = $DB->get_record('user', array('id' => $this->getuserid()));

            $request['given_name'] = $author->firstname;
            $request['family_name'] = $author->lastname;
        }

        // Send correct user role in request.
        if (has_capability('plagiarism/turnitinsim:viewfullreport', context_module::instance($this->getcm()))) {
            $request['viewer_default_permission_set'] = TURNITINSIM_ROLE_INSTRUCTOR;
        } else {
            $request['viewer_default_permission_set'] = TURNITINSIM_ROLE_LEARNER;
        }

        // Override viewer permissions depending on admin options.
        $request['viewer_permissions'] = $this->create_report_viewer_permissions();

        // Add similarity overrides - all true for now but this may change in future.
        $request['similarity'] = $this->create_similarity_overrides();

        // Make request to get Cloud Viewer URL.
        try {
            $response = $this->tsrequest->send_request($endpoint, json_encode($request), 'POST');
            return $response;
        } catch (Exception $e) {

            $this->tsrequest->handle_exception($e, 'taskoutputfailedcvlaunchurl', $this->getturnitinid());
        }
    }

    /**
     * Check whether the submission is anonymous.
     *
     * @return bool
     */
    public function is_submission_anonymous() {
        global $DB;

        // Get module details.
        $cm = get_coursemodule_from_id('', $this->getcm());
        $moduledata = $DB->get_record($cm->modname, array('id' => $cm->instance));

        $blindmarkingon = !empty($moduledata->blindmarking);
        $identitiesrevealed = !empty($moduledata->revealidentities);

        // Return true if hide identities is on, otherwise go by module blind marking settings.
        $turnitinhideidentity = get_config('plagiarism', 'turnitinhideidentity');
        if ($turnitinhideidentity) {
            $anon = true;
        } else {
            $anon = $blindmarkingon && !$identitiesrevealed;
        }

        return $anon;

    }

    /**
     * Get the path to the file from the pathnamehash
     *
     * @return $filepath
     */
    public function get_file_details() {
        $fs = get_file_storage();
        $file = $fs->get_file_by_hash($this->getidentifier());

        return $file;
    }

    /**
     * @return int
     */
    public function getid() {
        return $this->id;
    }

    /**
     * @param int $id
     */
    public function setid($id) {
        $this->id = $id;
    }

    /**
     * @return int
     */
    public function getcm() {
        return $this->cm;
    }

    /**
     * @param int $cm
     */
    public function setcm($cm) {
        $this->cm = $cm;
    }

    /**
     * @return int
     */
    public function getuserid() {
        return $this->userid;
    }

    /**
     * @param int $userid
     */
    public function setuserid($userid) {
        $this->userid = $userid;
    }

    /**
     * @return mixed
     */
    public function getgroupid() {
        return $this->groupid;
    }

    /**
     * @param mixed $ownertype
     */
    public function setgroupid($groupid) {
        $this->groupid = $groupid;
    }

    /**
     * @return mixed
     */
    public function getsubmitter() {
        return $this->submitter;
    }

    /**
     * @param mixed $submitter
     */
    public function setsubmitter($submitter) {
        $this->submitter = $submitter;
    }

    /**
     * @return int
     */
    public function getturnitinid() {
        return $this->turnitinid;
    }

    /**
     * @param int $turnitinid
     */
    public function setturnitinid($turnitinid) {
        $this->turnitinid = $turnitinid;
    }

    /**
     * @return mixed
     */
    public function getstatus() {
        return $this->status;
    }

    /**
     * @param mixed $status
     */
    public function setstatus($status) {
        $this->status = $status;
    }

    /**
     * @return mixed
     */
    public function getidentifier() {
        return $this->identifier;
    }

    /**
     * @param mixed $identifier
     */
    public function setidentifier($identifier) {
        $this->identifier = $identifier;
    }

    /**
     * @return mixed
     */
    public function getsubmittedtime() {
        return $this->submitted_time;
    }

    /**
     * @param mixed $submittedtime
     */
    public function setsubmittedtime($submittedtime) {
        $this->submitted_time = $submittedtime;
    }

    /**
     * @return mixed
     */
    public function getoverallscore() {
        return $this->overall_score;
    }

    /**
     * @param mixed $overallscore
     */
    public function setoverallscore($overallscore) {
        $this->overall_score = $overallscore;
    }

    /**
     * @return mixed
     */
    public function getitemid() {
        return $this->itemid;
    }

    /**
     * @param mixed $itemid
     */
    public function setitemid($itemid) {
        $this->itemid = $itemid;
    }

    /**
     * @return mixed
     */
    public function getrequestedtime() {
        return $this->requested_time;
    }

    /**
     * @param mixed $requestedtime
     */
    public function setrequestedtime($requestedtime) {
        $this->requested_time = $requestedtime;
    }

    /**
     * @return mixed
     */
    public function geterrormessage() {
        return $this->errormessage;
    }

    /**
     * @param mixed $errormessage
     */
    public function seterrormessage($errormessage) {
        $this->errormessage = $errormessage;
    }

    /**
     * @return mixed
     */
    public function gettype() {
        return $this->type;
    }

    /**
     * @param mixed $type
     */
    public function settype($type) {
        $this->type = $type;
    }

    /**
     * @return mixed
     */
    public function gettogenerate() {
        return $this->to_generate;
    }

    /**
     * @param mixed $togenerate
     */
    public function settogenerate($togenerate) {
        $this->to_generate = $togenerate;
    }

    /**
     * @return mixed
     */
    public function getgenerationtime() {
        return $this->generation_time;
    }

    /**
     * @param mixed $generationtime
     */
    public function setgenerationtime($generationtime) {
        $this->generation_time = $generationtime;
    }
}