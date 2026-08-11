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
 * Concrete implementation for mod_assign
 * @package    block_newgu_spdetails
 * @copyright  2024 University of Glasgow
 * @author     Howard Miller/Greg Pedder <greg.pedder@glasgow.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_newgu_spdetails\activities;

use cache;
use stdClass;

/**
 * Specific implementation for assignment activity.
 */
class assign_activity extends base {
    /**
     * @var object $cm
     */
    private $cm;

    /**
     * @var object $assign
     */
    private $assign;

    /**
     * The cache key name for this activity type.
     *
     * @var string
     */
    const CACHE_KEY = 'studentid_assessmentsduesoon:';

    /**
     * Constructor, set grade itemid.
     *
     * @param int $gradeitemid Grade item id
     * @param int $courseid
     * @param int $groupid
     */
    public function __construct(int $gradeitemid, int $courseid, int $groupid) {
        parent::__construct($gradeitemid, $courseid, $groupid);

        // Get the assignment object.
        $this->cm = \local_gugrades\users::get_cm_from_grade_item($gradeitemid, $courseid);
        $this->assign = $this->get_assign($this->cm);
    }

    /**
     * Get assignment object.
     *
     * @param object $cm course module
     * @return object
     */
    public function get_assign($cm): object {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $course = $DB->get_record('course', ['id' => $this->courseid], '*', MUST_EXIST);
        $coursemodulecontext = \context_module::instance($cm->id);
        $assign = new \assign($coursemodulecontext, $cm, $course);

        return $assign;
    }

    /**
     * Return the grade either from the assignment or
     * directly from Gradebook otherwise.
     *
     * @param int $userid
     * @return mixed object|bool
     */
    public function get_grade(int $userid): object|bool {
        global $DB;

        $activitygrade = new \stdClass();
        $activitygrade->finalgrade = null;
        $activitygrade->rawgrade = null;
        $activitygrade->grade = null;
        $activitygrade->gradedate = null;

        // Check if a MyGrades grade has been released and is not hidden.

        // If the grade is overridden in the Gradebook then we can
        // revert to the base - i.e., get the grade from the Gradebook.
        // We're only wanting grades that are deemed as 'released', i.e.
        // not 'hidden'.
        if ($grade = $DB->get_record('grade_grades', ['itemid' => $this->gradeitemid, 'hidden' => 0, 'userid' => $userid])) {
            if ($grade->overridden) {
                return parent::get_first_grade($userid);
            }

            // We want access to other properties, hence the returns...
            if ($grade->finalgrade != null && $grade->finalgrade > 0) {
                $activitygrade->finalgrade = $grade->finalgrade;
                $activitygrade->gradedate = $grade->timemodified;
                return $activitygrade;
            }

            if ($grade->rawgrade != null && $grade->rawgrade > 0) {
                $activitygrade->rawgrade = $grade->rawgrade;
                return $activitygrade;
            }
        }

        // This just pulls the grade from assign. Not sure it's that simple False, means do not create grade if it does not exist.
        // This is the grade object from mdl_assign_grades (check negative values).
        // Added the last parameter as w/o it, a mdl_assign_submission entry is created - a side effect I don't think we want here.
        $assigngrade = $this->assign->get_user_grade($userid, false, 0);

        if ($assigngrade !== false) {
            $activitygrade->grade = $assigngrade->grade;
            $activitygrade->gradedate = $assigngrade->timemodified;
            return $activitygrade;
        }

        return false;
    }

    /**
     * Return the Moodle URL to the item.
     *
     * @return string
     */
    public function get_assessmenturl(): string {
        return $this->get_itemurl() . $this->cm->id;
    }

    /**
     * Return the due date as the unix timestamp.
     *
     * @return int
     */
    public function get_rawduedate(): int {

        $dateinstance = $this->assign->get_instance();
        $rawdate = $dateinstance->duedate;

        return $rawdate;
    }

    /**
     * Return a formatted date.
     *
     * @param int|null $unformatteddate
     * @return string
     */
    public function get_formattedduedate(int|null $unformatteddate = null): string {

        $dateinstance = $this->assign->get_instance();
        $rawdate = $dateinstance->duedate;
        if ($unformatteddate) {
            $rawdate = $unformatteddate;
        }

        if ($rawdate > 0) {
             $duedate = userdate($rawdate, get_string('strftimedate', 'core_langconfig'));
        } else {
            $duedate = 'N/A';
        }

        return $duedate;
    }

    /**
     * Has a group override been set for this activity.
     *
     * @param object $statusobj
     * @param int $assignid
     * @param int $userid
     * @return object
     */
    private function has_group_override(object $statusobj, int $assignid, int $userid): object {
        global $DB;

        // Check if any group overrides have been created for this assignment.
        $groupselect = 'assignid = :assignid AND groupid IS NOT NULL AND userid IS NULL';
        $groupparams = ['assignid' => $assignid];
        $groupoverrides = $DB->get_records_select(
            'assign_overrides',
            $groupselect,
            $groupparams,
            '',
            'groupid, duedate, cutoffdate'
        );

        if (!empty($groupoverrides)) {
            foreach ($groupoverrides as $groupoverride) {
                // An override for this assignment exists - is our user a member of the group?
                $groupmembers = $DB->record_exists('groups_members', ['groupid' => $groupoverride->groupid, 'userid' => $userid]);
                if ($groupmembers === true) {
                    // If any of these fields are NULL, the override is using the default activity settings.
                    if ($groupoverride->allowsubmissionsfromdate != null) {
                        $statusobj->allowsubmissionsfromdate = $groupoverride->allowsubmissionsfromdate;
                    }

                    if ($groupoverride->duedate != null) {
                        $statusobj->due_date = $groupoverride->duedate;
                        $statusobj->raw_due_date = $groupoverride->duedate;
                    }

                    if ($groupoverride->cutoffdate != null) {
                        $statusobj->cutoff_date = $groupoverride->cutoffdate;
                    }
                }
            }
        }

        return $statusobj;
    }

    /**
     * Has an override for the individual student been set for this activity.
     * Individual overrides take precedence - based on how Moodle does things.
     *
     * @param object $statusobj
     * @param int $assignid
     * @param int $userid
     * @return object
     */
    private function has_override(object $statusobj, int $assignid, int $userid): object {
        global $DB;

        $overrides = $DB->get_record('assign_overrides', ['assignid' => $assignid, 'userid' => $userid]);

        if (!empty($overrides)) {
            if ($overrides->allowsubmissionsfromdate != null) {
                $statusobj->allowsubmissionsfromdate = $overrides->allowsubmissionsfromdate;
            }

            if ($overrides->duedate != null) {
                $statusobj->due_date = $overrides->duedate;
                $statusobj->raw_due_date = $overrides->duedate;
            }

            if ($overrides->cutoffdate != null) {
                $statusobj->cutoff_date = $overrides->cutoffdate;
            }
        }

        return $statusobj;
    }

    /**
     * Does this assessment require a submission of any type.
     *
     * @param int $nosubmissions
     * @param object $statusobj
     * @return array
     */
    private function submission_required(int $nosubmissions, object $statusobj): array {

        // This variable seems counterintuitive at first, but we're checking if any of the "Submission Types" are checked.
        if ($nosubmissions == 1) {
            $statusobj->nosubmissions = 1;

            return [false, $statusobj];
        }

        return [true, $statusobj];
    }

    /**
     * Is the assignment open.
     *
     * @param object $statusobj
     * @param int $now
     * @return object
     */
    private function get_assignment_availability(object $statusobj, int $now): object {

        if ($statusobj->allowsubmissionsfromdate) {
            if ($statusobj->allowsubmissionsfromdate > $now) {
                $statusobj->hasfuturestartdate = true;
                $statusobj->grade_status = get_string('status_submissionnotopen', 'block_newgu_spdetails');
                $statusobj->status_text = get_string('status_text_submissionnotopen', 'block_newgu_spdetails');
                $statusobj->grade_to_display = get_string('status_text_tobeconfirmed', 'block_newgu_spdetails');
                $statusobj->due_date = $this->get_formattedduedate($statusobj->due_date);

                // No further checks should be necessary if the lesson isn't available yet.
                return $statusobj;
            }

            if ($statusobj->allowsubmissionsfromdate < $now) {
                $statusobj->isavailable = true;
            }
        }

        if ($statusobj->due_date) {
            $statusobj->isavailable = true;
            $statusobj->due_date = $this->get_formattedduedate($statusobj->due_date);
        }

        if (!$statusobj->due_date) {
            $statusobj->due_date = self::get_formattedduedate();
        }

        if ($statusobj->cutoff_date) {
            // If there is an extension, this makes it potentially available still.
            if ($statusobj->hasextension) {
                // The due_date will have been set to the extension due date at this point.
                if ($statusobj->due_date < $now) {
                    $statusobj->isavailable = true;
                }
            }
        }

        return $statusobj;
    }

    /**
     * Have any assignment submissions been made.
     *
     * @param object $statusobj
     * @param object $assigninstance
     * @param int $userid
     * @return object
     */
    private function check_attempts_made(object $statusobj, object $assigninstance, int $userid): object {
        global $DB;

        // Now determine if we process this activity for this student, as a group or as an individual submission.
        if ($assigninstance->teamsubmission) {
            $cansubmitassessment = true;
            $anyteammembersubmits = false;
            $allteammemberssubmit = false;

            // MGU-1239 - Group submissions by any students were still displaying as overdue, even if a submission was made.
            if (!$assigninstance->submissiondrafts) {
                $anyteammembersubmits = true;
            }

            // The submission as part of a group is determined by a combination of the 'Require student to click...' option
            // being set to 'Yes' as well as 'Require all group members submit' option being set to 'Yes'. Note - this option
            // can be set to 'Yes' but also 'disabled' in the settings page - so we need to check that this isn't the case.
            if ($assigninstance->submissiondrafts) {
                if ($assigninstance->requireallteammemberssubmit) {
                    [$allteammemberssubmit, $statusobj] = self::submit_as_group($statusobj, $userid);
                }

                if (!$assigninstance->requireallteammemberssubmit) {
                    $anyteammembersubmits = true;
                }
            }

            // If this activity can only be submitted by a student who is in a group, check this first...
            if ($assigninstance->preventsubmissionnotingroup) {
                [$cansubmitassessment, $statusobj, $assignsubmission] = self::submit_as_group_member($statusobj, $userid);
            }

            // Not entirely sure we need this if we get here, $cansubmitassessment is initially true anyway.
            if (!$assigninstance->preventsubmissionnotingroup) {
                $cansubmitassessment = true;
            }

            if ($cansubmitassessment) {
                if ($anyteammembersubmits) {
                    $assignsubmission = self::any_team_member_submits($assigninstance->id);
                }
            } else {
                $statusobj->grade_status = get_string('status_text_submissionunavailable', 'block_newgu_spdetails');
            }
        }

        if (
            !$assigninstance->teamsubmission || (isset($allteammemberssubmit) &&
            $allteammemberssubmit == true)
        ) {
            $assignsubmission = $DB->get_record('assign_submission', [
                'assignment' => $assigninstance->id,
                'userid' => $userid,
            ]);
        }

        // Now check what state the assignmentsubmission object is in.
        if (empty($assignsubmission)) {
            $this->set_displaystate($statusobj);
        } else {
            $statusobj->grade_status = $assignsubmission->status;

            // There is a bug in class assign->get_user_grade() where get_user_submission() is called
            // and an assignment entry is created regardless -i.e. "true" is passed instead of an arg.
            // This will always result in a mdl_assign_submission entry with a status of "new" created.
            // We also have to cater for status 'draft' here as essay 'submissions' begin life in that state.
            if (
                $statusobj->grade_status == get_string('status_new', 'block_newgu_spdetails') ||
                $statusobj->grade_status == get_string('status_draft', 'block_newgu_spdetails')
            ) {
                $this->set_displaystate($statusobj);
            }

            if ($statusobj->grade_status == get_string('status_submitted', 'block_newgu_spdetails')) {
                $statusobj->status_text = get_string('status_text_submitted', 'block_newgu_spdetails');
                $statusobj->status_class = get_string('status_class_submitted', 'block_newgu_spdetails');
                $statusobj->status_link = '';

                if ($assigninstance->markingworkflow) {
                    $statusobj = self::get_marking_workflow_state($statusobj);
                }
            }
        }

        return $statusobj;
    }

    /**
     * This table is used for extensions to the due date. But it also contain entries for when
     * Marking Workflow has been enabled - but these only appear when marking has begun however.
     * Point of interest - "extensionduedate" trumps the settings/override "cut-off date".
     *
     * @param object $statusobj
     * @param int $assignid
     * @param int $userid
     * @return object
     */
    private function has_extension(object $statusobj, int $assignid, int $userid): object {
        global $DB;

        $userflags = $DB->get_record('assign_user_flags', ['assignment' => $assignid, 'userid' => $userid]);

        if (!empty($userflags)) {
            if ($userflags->extensionduedate > 0) {
                $statusobj->due_date = $userflags->extensionduedate;
                $statusobj->raw_due_date = $userflags->extensionduedate;
                $statusobj->hasextension = true;
            } else {
                $statusobj->tmpworkflowstate = $userflags->workflowstate ?? '';
            }
        }

        return $statusobj;
    }

    /**
     * Can this student submit the assignment as part of a group.
     *
     * @param object $statusobj
     * @param int $userid
     * @return array
     */
    private function submit_as_group(object $statusobj, int $userid): array {
        global $DB;

        // I don't think we need to do anything special here, since 'this' item that we are checking will be for
        // each student anyway. We perhaps need to check if the current student is in a group, just to be sure.
        $isgroupmember = $DB->get_record('groups_members', ['userid' => $userid]);

        if ($isgroupmember == false) {
            $statusobj->grade_status = get_string('status_submissionunavailable', 'block_newgu_spdetails');
            $statusobj->status_text = get_string('status_text_submissionunavailable', 'block_newgu_spdetails');
            $assignsubmission = new stdClass();
            $assignsubmission->status = get_string('status_submissionunavailable', 'block_newgu_spdetails');

            return [false, $statusobj];
        }

        return [true, $statusobj];
    }

    /**
     * So this doesn't come back to bite us in the proverbial, the Moodle tooltip in the settings page states that
     * if the user is "not members of a group", then they won't be able to submit this assessment. Pay close
     * attention to the wording "not members of ^a^ group" - which, to me says "any group" and not one specific to
     * this activity.
     * Ferenc: We need all the groups the student is a member of, but only available to this specific assignment activity.
     *
     * @param object $statusobj
     * @param int $userid
     * @return array
     */
    private function submit_as_group_member(object $statusobj, int $userid): array {
        $usergroups = $this->assign->get_all_groups($userid);

        if (count($usergroups) !== 1) {
            $statusobj->grade_status = get_string('status_submissionunavailable', 'block_newgu_spdetails');
            $statusobj->status_text = get_string('status_text_submissionunavailable', 'block_newgu_spdetails');
            $assignsubmission = new stdClass();
            $assignsubmission->status = get_string('status_submissionunavailable', 'block_newgu_spdetails');

            return [false, $statusobj, $assignsubmission];
        }

        return [true, $statusobj];
    }

    /**
     * Does this activty require submission as part of a team.
     * @param object $assigninstance
     * @param object $statusobj
     * @param int $userid
     * @param int $now
     * @param array $assignmentdata
     * @return array
     */
    private function submit_as_team(
        object $assigninstance,
        object $statusobj,
        int $userid,
        int $now,
        array $assignmentdata
    ): array {
        // Now determine if we process this activity for this student, as a group or as an individual submission.
        $cansubmitassessment = true;
        $anyteammembersubmits = false;
        $allteammemberssubmit = false;

        // MGU-1239 - Group submissions by any students were still displaying as overdue, even if a submission was made.
        if (!$assigninstance->submissiondrafts) {
            $anyteammembersubmits = true;
        }

        // The submission as part of a group is determined by a combination of the 'Require student to click...' option
        // being set to 'Yes' as well as 'Require all group members submit' option being set to 'Yes'. Note - this option
        // can be set to 'Yes' but also 'disabled' in the settings page - so we need to check that this isn't the case.
        if ($assigninstance->submissiondrafts) {
            if ($assigninstance->requireallteammemberssubmit) {
                [$allteammemberssubmit, $statusobj] = self::submit_as_group($statusobj, $userid);
            }
            if (!$assigninstance->requireallteammemberssubmit) {
                $anyteammembersubmits = true;
            }
        }

        // If this activity can only be submitted by a student who is in a group, check this first...
        if ($assigninstance->preventsubmissionnotingroup) {
            [$cansubmitassessment, $statusobj] = self::submit_as_group_member($statusobj, $userid);
        }

        // Not entirely sure we need this if we get here, $cansubmitassessment is initially true anyway.
        if (!$assigninstance->preventsubmissionnotingroup) {
            $cansubmitassessment = true;
        }

        if ($cansubmitassessment) {
            if ($anyteammembersubmits) {
                $assignmentsubmitted = self::any_team_member_submits($assigninstance->id);
                if (!$assignmentsubmitted) {
                    if ($statusobj->due_date > $now) {
                        $obj = new \stdClass();
                        $obj->id = $assigninstance->id;
                        $obj->name = $assigninstance->name;
                        $obj->duedate = (int) $statusobj->due_date;
                        $assignmentdata[] = $obj;
                    }
                }
            }
        }

        return [$allteammemberssubmit, $assignmentdata];
    }

    /**
     * Can any team member make a submission.
     *
     * @param int $assignid
     * @return object|bool
     */
    private function any_team_member_submits(int $assignid): object|bool {
        global $DB;

        $assignsubmissions = $DB->get_records('assign_submission', ['assignment' => $assignid]);

        if ($assignsubmissions != false) {
            // If any submission has been made and is in a 'submitted' state, then
            // we can class this activity as having been submitted by the group.
            $assignmentsubmitted = false;
            foreach ($assignsubmissions as $assignmentsubmission) {
                if ($assignmentsubmission->status == get_string('status_submitted', 'block_newgu_spdetails')) {
                    $assignmentsubmitted = true;
                    break;
                }
            }

            if ($assignmentsubmitted) {
                $assignsubmission = new stdClass();
                $assignsubmission->status = get_string('status_submitted', 'block_newgu_spdetails');

                return $assignsubmission;
            }

            return false;
        }

        return false;
    }

    /**
     * If Marking Workflow has been enabled, what stage are we at.
     *
     * @param object $statusobj
     * @return object
     */
    private function get_marking_workflow_state(object $statusobj): object {
        $gtd = get_string('status_text_tobeconfirmed', 'block_newgu_spdetails');

        if ($statusobj->tmpworkflowstate != '') {
            switch ($statusobj->tmpworkflowstate) {
                case "notmarked":
                    $gtd = get_string('notmarked', 'block_newgu_spdetails');
                    break;

                case "inmarking":
                    $gtd = get_string('inmarking', 'block_newgu_spdetails');
                    break;

                case "inreview":
                    $gtd = get_string('inreview', 'block_newgu_spdetails');
                    break;

                case "readyforreview":
                    $gtd = get_string('readyforreview', 'block_newgu_spdetails');
                    break;

                case "readyforrelease":
                    $gtd = get_string('readyforrelease', 'block_newgu_spdetails');
                    break;

                case "released":
                    $gtd = get_string('released', 'block_newgu_spdetails');
                    $statusobj->workflowstate = $statusobj->tmpworkflowstate;
                    break;
            }
        }
        $statusobj->grade_to_display = $gtd;

        return $statusobj;
    }

    /**
     * This method takes the $statusobj object and sets the display values for the grade status.
     *
     * @param object $statusobj
     * @return object
     */
    private function set_displaystate(object $statusobj): object {
        $now = usertime(time());

        // MGU-1472 - Assignments with no submmissions still require to be date checked for the charts.
        if ($statusobj->nosubmissions == 1) {
            if ($now > $statusobj->due_date) {
                $statusobj->grade_status = get_string('status_nosubmissionrequired', 'block_newgu_spdetails');
                $statusobj->status_text = get_string('status_text_notyetgraded', 'block_newgu_spdetails'); // Yes, really.
                $statusobj->status_class = get_string('status_class_nosubmissionrequired', 'block_newgu_spdetails');
                if ($statusobj->tmpworkflowstate != '') {
                    $statusobj = self::get_marking_workflow_state($statusobj);
                }
            } else {
                $statusobj->hasfuturestartdate = true;
                $statusobj->grade_status = get_string('status_upcoming', 'block_newgu_spdetails');
                $statusobj->status_text = get_string('status_text_upcoming', 'block_newgu_spdetails');
                $statusobj->grade_to_display = get_string('status_text_tobeconfirmed', 'block_newgu_spdetails');
            }

            $statusobj->due_date = $this->get_formattedduedate($statusobj->due_date);

            return $statusobj;
        }

        if ($statusobj->allowsubmissionsfromdate) {
            if ($statusobj->allowsubmissionsfromdate > $now) {
                $statusobj->grade_status = get_string('status_submissionnotopen', 'block_newgu_spdetails');
                $statusobj->status_text = get_string('status_text_submissionnotopen', 'block_newgu_spdetails');
                $statusobj->grade_to_display = get_string('status_text_tobeconfirmed', 'block_newgu_spdetails');

                // No further checks should be necessary if the assignment isn't available yet.
                return $statusobj;
            }
        }

        // Now start by saying the student is still able to make a submission.
        $statusobj->grade_status = get_string('status_submit', 'block_newgu_spdetails');
        $statusobj->status_text = get_string('status_text_submit', 'block_newgu_spdetails');
        $statusobj->status_class = get_string('status_class_submit', 'block_newgu_spdetails');
        $statusobj->status_link = $statusobj->assessment_url;
        $statusobj->grade_to_display = get_string('status_text_tobeconfirmed', 'block_newgu_spdetails');

        // The student can still submit if they have exceeded the due date at this point.
        if ($statusobj->raw_due_date != 0 && $now > $statusobj->raw_due_date) {
            $statusobj->grade_status = get_string('status_overdue', 'block_newgu_spdetails');
            $statusobj->status_text = get_string('status_text_overdue', 'block_newgu_spdetails');
            $statusobj->status_class = get_string('status_class_overdue', 'block_newgu_spdetails');
            $statusobj->status_link = $statusobj->assessment_url;
        }

        // Cut-off date is the more 'finite' state - exceed this and you're not allowed to submit at all.
        if ($statusobj->cutoff_date > 0) {
            if (!$statusobj->hasextension) {
                // If the student has exceeded the cut-off date then we can no longer submit anything.
                if ($now > $statusobj->cutoff_date) {
                    $statusobj->grade_status = get_string('status_notsubmitted', 'block_newgu_spdetails');
                    $statusobj->status_text = get_string('status_text_notsubmitted', 'block_newgu_spdetails');
                    $statusobj->status_class = get_string('status_class_notsubmitted', 'block_newgu_spdetails');
                    $statusobj->status_link = '';
                }
            }
        }

        return $statusobj;
    }

    /**
     * Method to return the current status of the assignment.
     *
     * Specific to activity type Assignment however, we can have group or individual
     * submissions. Begin by taking the wider group centric view, but scale down to
     * the individual view if no group submissions have been set up.
     *
     * With regards dates - a date value of 0 in the settings page indicates
     * there is no exclusion - e.g. an assignment is open for submission anytime.
     * For overrides however, NULL values signal that the main activity settings
     * should be used instead.
     * Due dates that have been exceeded usually cause the assignment to end up as
     * Overdue and will drop off the "assements due in" chart.
     * Be aware of how the cut-off date can be affected by an extension. An assignment
     * can end up in overdue unless any cut-off date has been exceeded, but if there
     * is an extension which has a cut off date, check this has not been exceeded either
     * if assignments don't show up as expected.
     *
     * @see MGU-1472 - this activity has an additional purpose as a "grade placeholder",
     * basically an assignment that is set up not to accept submissions.
     *
     * @param int $userid
     * @return object
     */
    public function get_status(int $userid): object {

        $now = usertime(time());
        $assigninstance = $this->assign->get_instance();
        $statusobj = new \stdClass();
        $statusobj->assessment_url = $this->get_assessmenturl();
        $statusobj->grade_status = '';
        $statusobj->status_text = '';
        $statusobj->status_class = '';
        $statusobj->status_link = '';
        $statusobj->grade_to_display = get_string('status_text_tobeconfirmed', 'block_newgu_spdetails');
        $statusobj->grade_class = false;
        $statusobj->allowsubmissionsfromdate = $assigninstance->allowsubmissionsfromdate;
        $statusobj->due_date = $assigninstance->duedate;
        $statusobj->raw_due_date = $assigninstance->duedate;
        $statusobj->cutoff_date = $assigninstance->cutoffdate;
        $statusobj->markingworkflow = $assigninstance->markingworkflow;
        $statusobj->grade_date = '';
        $statusobj->tmpworkflowstate = '';
        $statusobj->nosubmissions = 0;
        $statusobj->hasfuturestartdate = false;
        $statusobj->hasextension = false;
        $statusobj->isavailable = false;

        // We're following the layout in the settings page, checking for any dates (available, overrides etc)
        // first, this seems to make more sense as these properties become necessary further on.
        $statusobj = self::has_group_override($statusobj, $assigninstance->id, $userid);
        $statusobj = self::has_override($statusobj, $assigninstance->id, $userid);
        $statusobj = self::has_extension($statusobj, $assigninstance->id, $userid);
        [$submissionrequired, $statusobj] = self::submission_required($assigninstance->nosubmissions, $statusobj);

        if ($submissionrequired === false) {
            $this->set_displaystate($statusobj);

            if ($statusobj->hasfuturestartdate) {
                return $statusobj;
            }
        }

        if ($submissionrequired === true) {
            $statusobj = self::get_assignment_availability($statusobj, $now);

            if ($statusobj->hasfuturestartdate) {
                return $statusobj;
            }

            if ($statusobj->isavailable) {
                $statusobj = self::check_attempts_made($statusobj, $assigninstance, $userid);
                return $statusobj;
            }
        }

        return $statusobj;
    }

    /**
     * Return the due date of the assignment if it hasn't been submitted.
     * This method also checks if the assignment is part of a group submission.
     * For students not in a group, this assignment won't be included in their chart data.
     *
     * @return array
     */
    public function get_assessmentsdue(): array {
        global $USER, $DB;

        // Cache this query as it's going to get called for each assignment in the course otherwise.
        $cache = cache::make('block_newgu_spdetails', 'assignmentsduequery');
        $now = usertime(time());
        $currenttime = usertime(time());
        $fiveminutes = $currenttime - 300;
        $cachekey = self::CACHE_KEY . $USER->id;
        $cachedata = $cache->get_many([$cachekey]);
        $assignmentdata = [];

        if (!$cachedata[$cachekey] || $cachedata[$cachekey][0]['updated'] < $fiveminutes) {
            $lastmonth = usertime(mktime(date('H'), date('i'), date('s'), date('m') - 1, date('d'), date('Y')));
            $select = 'userid = :userid AND ((timecreated BETWEEN :lastmonth AND :now) OR (timemodified BETWEEN :tlastmonth AND
            :tnow))';
            $params = [
                'userid' => $USER->id,
                'lastmonth' => $lastmonth,
                'now' => $now,
                'tlastmonth' => $lastmonth,
                'tnow' => $now,
            ];
            $assignsubmissions = $DB->get_records_select('assign_submission', $select, $params, '', 'assignment, status');

            $submissionsdata = [
                'updated' => $currenttime,
                'assignmentsubmissions' => $assignsubmissions,
            ];

            $cachedata = [
                $cachekey => [
                    $submissionsdata,
                ],
            ];
            $cache->set_many($cachedata);
        } else {
            $cachedata = $cache->get_many([$cachekey]);
            $assignsubmissions = $cachedata[$cachekey][0]['assignmentsubmissions'];
        }

        $assigninstance = $this->assign->get_instance();
        $statusobj = new \stdClass();
        $statusobj->due_date = (int) $assigninstance->duedate;
        $statusobj->raw_due_date = (int) $assigninstance->duedate;
        $statusobj->cutoff_date = (int) $assigninstance->cutoffdate;
        $statusobj->tmpworkflowstate = '';
        $statusobj->nosubmissions = 0;

        // We're following the layout in the settings page, checking for any dates (available, overrides etc)
        // first, this seems to make more sense as these properties become necessary further on.
        $statusobj = self::has_group_override($statusobj, $assigninstance->id, $USER->id);
        $statusobj = self::has_override($statusobj, $assigninstance->id, $USER->id);
        $statusobj = self::has_extension($statusobj, $assigninstance->id, $USER->id);
        [$submissionrequired, $statusobj] = self::submission_required($assigninstance->nosubmissions, $statusobj);

        // If no submission is required for this activity, then we only need to check its due date further on.
        if ($submissionrequired === false) {
            $allteammemberssubmit = true;
        }

        if ($submissionrequired === true) {
            if ($assigninstance->teamsubmission) {
                [$allteammemberssubmit, $assignmentdata] = self::submit_as_team(
                    $assigninstance,
                    $statusobj,
                    $USER->id,
                    $now,
                    $assignmentdata
                );
            }
        }

        if (
            !$assigninstance->teamsubmission || (isset($allteammemberssubmit) && $allteammemberssubmit == true)
        ) {
            // Looks like when visiting an activity, you end up with a submission entry by default.
            if (
                !array_key_exists($assigninstance->id, $assignsubmissions) ||
                (array_key_exists($assigninstance->id, $assignsubmissions) &&
                (is_object($assignsubmissions[$assigninstance->id]) &&
                property_exists($assignsubmissions[$assigninstance->id], 'status') &&
                $assignsubmissions[$assigninstance->id]->status == 'new'))
            ) {
                // For the assessments due chart, we're only interested in if there's a due date essentially.
                if (($statusobj->due_date != 0) && ($statusobj->due_date > $now)) {
                    $obj = new \stdClass();
                    $obj->id = $assigninstance->id;
                    $obj->name = $assigninstance->name;
                    $obj->duedate = (int) $statusobj->due_date;
                    $assignmentdata[] = $obj;
                }
            }
        }

        return $assignmentdata;
    }
}
