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
 * Concrete implementation for mod_lesson.
 *
 * @package    block_newgu_spdetails
 * @copyright  2024 University of Glasgow
 * @author     Greg Pedder <greg.pedder@glasgow.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_newgu_spdetails\activities;

use cache;

/**
 * Implementation for a lesson activity.
 */
class lesson_activity extends base {
    /**
     * @var object $cm
     */
    private $cm;

    /**
     * @var object $lesson
     */
    private $lesson;

    /**
     * The cache key name for this activity type.
     *
     * @var string
     */
    const CACHE_KEY = 'studentid_lessonsduesoon:';

    /**
     * Constructor, set grade itemid.
     *
     * @param int $gradeitemid Grade item id
     * @param int $courseid
     * @param int $groupid
     */
    public function __construct(int $gradeitemid, int $courseid, int $groupid) {
        parent::__construct($gradeitemid, $courseid, $groupid);

        // Get the lesson object.
        $this->cm = \local_gugrades\users::get_cm_from_grade_item($gradeitemid, $courseid);
        $this->lesson = $this->get_lesson();
    }

    /**
     * Get lesson object.
     *
     * @return object
     */
    public function get_lesson() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/lesson/lib.php');
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');
        $lessonid = $this->gradeitem->iteminstance;
        $lessonrecord = $DB->get_record('lesson', ['id' => $lessonid]);
        $lesson = new \lesson($lessonrecord);

        return $lesson;
    }

    /**
     * Return the grade directly from Gradebook.
     *
     * @param int $userid
     * @return mixed object|bool
     */
    public function get_grade(int $userid): object|bool {
        global $DB;

        $activitygrade = new \stdClass();
        $activitygrade->finalgrade = null;
        $activitygrade->rawgrade = null;
        $activitygrade->gradedate = null;

        // If the grade is overridden in the Gradebook then we can
        // revert to the base - i.e., get the grade from the Gradebook.
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

        $dateinstance = lesson_get_user_deadline($this->courseid);
        $rawdate = $dateinstance[$this->gradeitem->iteminstance]->userdeadline;

        return $rawdate;
    }

    /**
     * Return a formatted date.
     *
     * @param int|null $unformatteddate
     * @return string
     */
    public function get_formattedduedate(int|null $unformatteddate = null): string {
        $dateinstance = $this->lesson;
        $rawdate = $dateinstance->deadline;
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
     * @param int $lessonid
     * @param int $userid
     * @return object
     */
    private function has_group_override(object $statusobj, int $lessonid, int $userid): object {
        global $DB;

        // We first check if any group overrides have been created for this lesson.
        $groupselect = 'lessonid = :lessonid AND groupid IS NOT NULL AND userid IS NULL';
        $groupparams = ['lessonid' => $lessonid];
        $groupoverrides = $DB->get_records_select(
            'lesson_overrides',
            $groupselect,
            $groupparams, '',
            'groupid, available, deadline'
        );
        if (!empty($groupoverrides)) {
            foreach ($groupoverrides as $groupoverride) {
                // An override for this lesson exists - is our user a member of the group?
                $groupmembers = $DB->record_exists('groups_members', ['groupid' => $groupoverride->groupid, 'userid' => $userid]);
                if ($groupmembers === true) {
                    // If any of these fields are NULL, the override is using the default activity settings.
                    if ($groupoverride->available != null) {
                        $statusobj->availablefrom = $groupoverride->available;
                    }
                    if ($groupoverride->deadline != null) {
                        $statusobj->due_date = $groupoverride->deadline;
                        $statusobj->raw_due_date = $groupoverride->deadline;
                    }
                    // I don't think timelimit, review, maxattempts and retake are useful to us for the rest of these 'checks'.
                }
            }
        }

        return $statusobj;
    }

    /**
     * Has an override for the individual student been set for this activity.
     *
     * @param object $statusobj
     * @param int $lessonid
     * @param int $userid
     * @return object
     */
    private function has_override(object $statusobj, int $lessonid, int $userid): object {
        global $DB;

        // Individual overrides however, take precedence - based on how Moodle does things.
        $overrides = $DB->get_record('lesson_overrides', ['lessonid' => $lessonid, 'userid' => $userid]);
        if (!empty($overrides)) {
            $statusobj->availablefrom = $overrides->available;
            $statusobj->due_date = $overrides->deadline;
            $statusobj->raw_due_date = $overrides->deadline;
        }

        return $statusobj;
    }

    /**
     * Is the lesson open.
     *
     * @param object $statusobj
     * @param int $now
     * @return object
     */
    private function get_lesson_availability(object $statusobj, int $now): object {

        if ($statusobj->availablefrom) {
            if ($statusobj->availablefrom > $now) {
                $statusobj->hasfuturestartdate = true;
                $statusobj->grade_status = get_string('status_submissionnotopen', 'block_newgu_spdetails');
                $statusobj->status_text = get_string('status_text_submissionnotopen', 'block_newgu_spdetails');
                $statusobj->grade_to_display = get_string('status_text_tobeconfirmed', 'block_newgu_spdetails');
                $statusobj->due_date = $this->get_formattedduedate($statusobj->due_date);

                // No further checks should be necessary if the lesson isn't available yet.
                return $statusobj;
            }

            if ($statusobj->availablefrom < $now) {
                $statusobj->isavailable = true;
            }
        }

        if ($statusobj->due_date) {
            $statusobj->isavailable = false;
            if ($statusobj->due_date > $now) {
                $statusobj->isavailable = true;
            }

            $statusobj->due_date = $this->get_formattedduedate($statusobj->due_date);

            return $statusobj;
        }

        if (!$statusobj->due_date) {
            $statusobj->due_date = self::get_formattedduedate();
        }

        return $statusobj;
    }

    /**
     * Have any lesson attempts been made.
     *
     * @param object $statusobj
     * @param int $userid
     * @return object
     */
    private function check_attempts_made(object $statusobj, int $userid): object {
        global $DB;

        // Begin by saying this lesson can be submitted.
        $statusobj->grade_status = get_string('status_submit', 'block_newgu_spdetails');
        $statusobj->status_text = get_string('status_text_submit', 'block_newgu_spdetails');
        $statusobj->status_class = get_string('status_class_submit', 'block_newgu_spdetails');
        $statusobj->status_link = $statusobj->assessment_url;

        $lessonattempts = $DB->count_records('lesson_attempts', ['lessonid' => $this->lesson->id, 'userid' => $userid]);

        if ($lessonattempts > 0) {
            $statusobj->grade_status = get_string('status_submitted', 'block_newgu_spdetails');
            $statusobj->status_text = get_string('status_text_submitted', 'block_newgu_spdetails');
            $statusobj->status_class = get_string('status_class_submitted', 'block_newgu_spdetails');
            $statusobj->status_link = '';

            // Now query the specialist table for this activity.
            if ($lessongrades = $DB->get_record('lesson_grades', ['lessonid' => $this->lesson->id, 'userid' => $userid,
            'completed' => 1, ])) {
                $statusobj->grade_status = get_string('status_graded', 'block_newgu_spdetails');
                $statusobj->status_text = get_string('status_text_graded', 'block_newgu_spdetails');
                $statusobj->status_class = get_string('status_class_graded', 'block_newgu_spdetails');
                $statusobj->grade_to_display = $lessongrades->grade;

                return $statusobj;
            }

            return $statusobj;

        }

        return $statusobj;
    }

    /**
     * Method to return the current status of the lesson.
     *
     * @param int $userid
     * @return object
     */
    public function get_status(int $userid): object {

        $now = usertime(time());
        $statusobj = new \stdClass();
        $statusobj->assessment_url = $this->get_assessmenturl();
        $statusobj->grade_status = '';
        $statusobj->status_text = '';
        $statusobj->status_class = '';
        $statusobj->status_link = '';
        $statusobj->grade_to_display = get_string('status_text_tobeconfirmed', 'block_newgu_spdetails');
        $statusobj->grade_class = false;
        $statusobj->grade_date = '';
        $statusobj->availablefrom = $this->lesson->available;
        $statusobj->due_date = $this->lesson->deadline;
        $statusobj->raw_due_date = $this->get_rawduedate();
        $statusobj->hasfuturestartdate = false;
        $statusobj->isavailable = false;

        // We're following the layout in the settings page, checking for any dates (available, overrides etc)
        // first, this seems to make more sense as these properties become necessary further on.
        $statusobj = self::has_group_override($statusobj, $this->lesson->id, $userid);
        $statusobj = self::has_override($statusobj, $this->lesson->id, $userid);
        $statusobj = self::get_lesson_availability($statusobj, $now);

        if ($statusobj->hasfuturestartdate) {
            return $statusobj;
        }

        if ($statusobj->isavailable) {
            $statusobj = self::check_attempts_made($statusobj, $userid);
            return $statusobj;
        }

        // There is no Overdue state with a lesson activity, just a "deadline".
        if (!$statusobj->isavailable) {
            $statusobj->grade_status = get_string('status_notsubmitted', 'block_newgu_spdetails');
            $statusobj->status_text = get_string('status_text_notsubmitted', 'block_newgu_spdetails');
            $statusobj->status_class = get_string('status_class_notsubmitted', 'block_newgu_spdetails');
            $statusobj->status_link = '';
        }

        return $statusobj;

    }

    /**
     * Return the due date of the lesson if it hasn't been submitted.
     * Given that a Lesson activity can have a number of permutations with regards opening/deadline dates,
     * this gives us a number of...tbc.
     *
     * @return array
     */
    public function get_assessmentsdue(): array {
        global $USER, $DB;

        // Cache this query as it's going to get called for each lesson in the course otherwise.
        $cache = cache::make('block_newgu_spdetails', 'lessonsduequery');
        $now = usertime(time());
        $currenttime = usertime(time());
        $fiveminutes = $currenttime - 300;
        $cachekey = self::CACHE_KEY . $USER->id;
        $cachedata = $cache->get_many([$cachekey]);
        $lessondata = [];

        if (!$cachedata[$cachekey] || $cachedata[$cachekey][0]['updated'] < $fiveminutes) {
            $lastmonth = usertime(mktime(date('H'), date('i'), date('s'), date('m') - 1, date('d'), date('Y')));
            $select = 'userid = :userid AND ((lessontime BETWEEN :lastmonth AND :now) OR (lessontime BETWEEN :tlastmonth AND
            :tnow))';
            $params = [
                'userid' => $USER->id,
                'lastmonth' => $lastmonth,
                'now' => $now,
                'tlastmonth' => $lastmonth,
                'tnow' => $now,
            ];
            // This table seems to be the only practical table to query for submission deadlines. lesson_attempts only stores when
            // an attempt was made.
            $timedsubmissions = $DB->get_records_select('lesson_timer', $select, $params, '', 'lessonid, starttime,
            completed');

            $submissionsdata = [
                'updated' => $currenttime,
                'timedlessonsubmissions' => $timedsubmissions,
            ];

            $cachedata = [
                $cachekey => [
                    $submissionsdata,
                ],
            ];
            $cache->set_many($cachedata);
        } else {
            $cachedata = $cache->get_many([$cachekey]);
            $timedsubmissions = $cachedata[$cachekey][0]['timedlessonsubmissions'];
        }

        // We have to set availablefrom and raw_due_date even though we don't need them.
        $lesson = $this->lesson;
        $statusobj = new \stdClass();
        $statusobj->availablefrom = $lesson->available;
        $statusobj->due_date = $lesson->deadline;
        $statusobj->raw_due_date = $lesson->deadline;

        // We're following the layout in the settings page, checking for any dates (available, overrides etc)
        // first, this seems to make more sense as these properties become necessary further on.
        $statusobj = self::has_group_override($statusobj, $lesson->id, $USER->id);
        $statusobj = self::has_override($statusobj, $lesson->id, $USER->id);

        // Much like activity type Assignment, we end up with a 'submission' that we now need to check if it's 'completed'.
        if (!array_key_exists($lesson->id, $timedsubmissions) ||
        (array_key_exists($lesson->id, $timedsubmissions) &&
        (is_object($timedsubmissions[$lesson->id]) && property_exists($timedsubmissions[$lesson->id], 'completed') &&
        $timedsubmissions[$lesson->id]->completed == 0))) {
            // For the assessments due chart, we're only interested in if there's a due date essentially.
            if (($statusobj->due_date != 0) && ($statusobj->due_date > $now)) {
                $obj = new \stdClass();
                $obj->id = $lesson->id;
                $obj->name = $lesson->name;
                $obj->duedate = $statusobj->due_date;
                $lessondata[] = $obj;
            }
        }

        return $lessondata;
    }
}
