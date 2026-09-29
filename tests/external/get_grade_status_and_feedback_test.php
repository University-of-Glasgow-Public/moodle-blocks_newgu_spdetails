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
 * Unit tests for the block_newgu_spdetails class.
 *
 * @package    block_newgu_spdetails
 * @author     Greg Pedder <greg.pedder@glasgow.ac.uk>
 * @copyright  2023 University of Glasgow
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_newgu_spdetails\external;
use core_external\external_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
require_once($CFG->dirroot . '/blocks/newgu_spdetails/tests/external/newgu_spdetails_advanced_testcase.php');

/**
 * Unit tests for retrieving grade, status and feedback.
 */
final class get_grade_status_and_feedback_test extends \block_newgu_spdetails\external\newgu_spdetails_advanced_testcase {
    /**
     * For a MyGrades course - we have the situation where if grades
     * haven't been imported/released, then it defaults to retrieving
     * them from gradebook. These tests should account for this, i.e.
     * as we're only dealing with released grades from local_gugrades -
     * there isn't a notion of provisional grades.
     *
     * @covers \blocks\newgu_spdetails\api
     */
    public function test_get_grade_status_and_feedback_mygrades(): void {
        $userid = $this->student1->id;

        global $DB;

        // We're the test student.
        $this->setUser($this->student1->id);

        // Fake some due dates.
        $duedate1 = mktime(date("H"), date("i"), date("s"), date("m"), date("d") + 5, date("Y"));

        $mygradesassignment1 = $this->getDataGenerator()->create_module('assign', [
            'name' => 'October lab 1A',
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'course' => $this->mygradescourse->id,
            'duedate' => $duedate1,
            'gradetype' => 2,
            'grademax' => 50,
            'scaleid' => $this->scalea->id,
        ]);

        // Create_module gives us stuff for free, however, it doesn't set the categoryid correctly.
        $mygradessummativesubcategoryid = $this->mygradessummativesubcategory->id;
        $params = [
            $mygradessummativesubcategoryid,
            $mygradesassignment1->id,
        ];
        $DB->execute("UPDATE {grade_items} SET categoryid = ? WHERE iteminstance = ?", $params);

        // Create_module also doesn't allow us to set an assignment plugin, which we check for in the main class.
        // Fake that we are allowing submissions.
        $params = [
            'nosubmissions' => 0,
            'id' => $mygradesassignment1->id,
        ];
        $DB->execute("UPDATE {assign} SET nosubmissions = ? WHERE id = ?", $params);

        // Create the assignment submission entries.
        $this->add_assignment_grade(
            $mygradesassignment1->id,
            $this->student1->id,
            $this->teacher->id,
            40,
            ASSIGN_SUBMISSION_STATUS_NEW
        );

        // Fake some more due dates.
        $duedate2 = mktime(date("H"), date("i"), date("s"), date("m"), date("d") + 5, date("Y"));

        $mygradesassignment2 = $this->getDataGenerator()->create_module('assign', [
            'name' => 'October lab 2A',
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'course' => $this->mygradescourse->id,
            'duedate' => $duedate2,
            'gradetype' => 2,
            'grademax' => 40,
            'scaleid' => $this->scalea->id,
        ]);

        // Create_module gives us stuff for free, however, it doesn't set the categoryid correctly.
        $params = [
            $mygradessummativesubcategoryid,
            $mygradesassignment2->id,
        ];
        $DB->execute("UPDATE {grade_items} SET categoryid = ? WHERE iteminstance = ?", $params);

        // Create_module also doesn't allow us to set an assignment plugin, which we check for in the main class.
        // Fake that we are allowing submissions.
        $params = [
            'nosubmissions' => 0,
            'id' => $mygradesassignment2->id,
        ];
        $DB->execute("UPDATE {assign} SET nosubmissions = ? WHERE id = ?", $params);

        // Create the assignment submission entries.
        $this->add_assignment_grade(
            $mygradesassignment2->id,
            $this->student1->id,
            $this->teacher->id,
            35,
            ASSIGN_SUBMISSION_STATUS_NEW
        );

        $mygradesgradeditems = $this->api->get_gradable_activities(
            'current',
            $userid,
            'duedate',
            'asc',
            $mygradessummativesubcategoryid
        );

        $this->assertIsArray($mygradesgradeditems);
        $this->assertCount(2, $mygradesgradeditems['coursedata']['courseitems']);

        // Check for the feedback.
        $this->assertStringContainsString(
            get_string('status_text_tobeconfirmed', 'block_newgu_spdetails'),
            $mygradesgradeditems['coursedata']['courseitems'][0]->grade_feedback
        );

        // Check for the final grade.
        $this->assertObjectHasProperty('grade_class', $mygradesgradeditems['coursedata']['courseitems'][1]);
        $this->assertFalse($mygradesgradeditems['coursedata']['courseitems'][1]->grade_provisional);
    }

    /**
     * Test that the Feedback link that becomes available after an activity
     * has been graded in Gradebook, links to the activity grade page.
     * See MGU-1540 for further information.
     */
    public function test_get_gradebook_feedback_link(): void {
        global $DB;

        // We're the test student.
        $this->setUser($this->student1->id);

        // Fake some due dates.
        $duedate1 = mktime(date("H"), date("i"), date("s"), date("m"), date("d") + 5, date("Y"));

        $this->mygradesassignment1 = $this->getDataGenerator()->create_module('assign', [
            'name' => 'Gradebook Feedback link',
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'course' => $this->mygradescourse->id,
            'duedate' => $duedate1,
            'gradetype' => 2,
            'grademax' => 50,
            'scaleid' => $this->scalea->id,
        ]);

        // Create_module gives us stuff for free, however, it doesn't set the categoryid correctly.
        $mygradessummativesubcategoryid = $this->mygradessummativesubcategory->id;
        $params = [
            $mygradessummativesubcategoryid,
            $this->mygradesassignment1->id,
        ];
        $DB->execute("UPDATE {grade_items} SET categoryid = ? WHERE iteminstance = ?", $params);

        // Create_module also doesn't allow us to set an assignment plugin, which we check for in the main class.
        // Fake that we are allowing submissions.
        $params = [
            'nosubmissions' => 0,
            'id' => $this->mygradesassignment1->id,
        ];
        $DB->execute("UPDATE {assign} SET nosubmissions = ? WHERE id = ?", $params);

        // Create the assignment grade, along with some feedback.
        $this->add_assignment_grade(
            $this->mygradesassignment1->id,
            $this->student1->id,
            $this->teacher->id,
            17,
            ASSIGN_SUBMISSION_STATUS_NEW,
            'Excellent work.'
        );

        $gradeitem = $DB->get_record('grade_items',
            [
                'courseid' => $this->mygradesassignment1->course,
                'iteminstance' => $this->mygradesassignment1->id
            ], 
            '*', 
            MUST_EXIST
        );

        // This should update the assign_submission table and the grades_grade table.
        $this->release_assignment_grades($this->student1->id, $this->mygradesassignment1, $gradeitem->id);

        $activity = \block_newgu_spdetails\activity::activity_factory($gradeitem->id, $this->mygradesassignment1->course, 0);
        $activityurl = $activity->get_assessmenturl();

        $gradestatobj = \block_newgu_spdetails\grade::get_grade_status_and_feedback(
            $this->mygradescourse->id,
            $gradeitem->id,
            $this->student1->id,
            2,
            17,
            $this->scalea->id,
        );

        $this->assertIsObject($gradestatobj);
        $this->assertObjectHasProperty('grade_feedback_link', $gradestatobj);
        $this->assertStringContainsString($gradestatobj->grade_feedback_link, $activityurl . "#page-footer");
    }

    /**
     * Test that the Feedback link that becomes available after an activity has been graded and released
     * from MyGrades, links to the activity grade page and no longer the Grader Report page.
     * See MGU-1540 for further information.
     */
    public function test_get_mygrades_feedback_link(): void {
        global $DB;

        // We're the teacher to begin with.
        $this->setUser($this->teacher->id);

        // Fake some due dates.
        $duedate1 = mktime(date("H"), date("i"), date("s"), date("m"), date("d") + 6, date("Y"));

        $this->mygradesassignment1 = $this->getDataGenerator()->create_module('assign', [
            'name' => 'MyGrades Feedback link',
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'course' => $this->mygradescourse->id,
            'duedate' => $duedate1,
            'gradetype' => 2,
            'grademax' => 50,
            'scaleid' => $this->scalea->id,
        ]);

        // Create_module gives us stuff for free, however, it doesn't set the categoryid correctly.
        $mygradessummativesubcategoryid = $this->mygradessummativesubcategory->id;
        $params = [
            $mygradessummativesubcategoryid,
            $this->mygradesassignment1->id,
        ];
        $DB->execute("UPDATE {grade_items} SET categoryid = ? WHERE iteminstance = ?", $params);

        // Create_module also doesn't allow us to set an assignment plugin, which we check for in the main class.
        // Fake that we are allowing submissions.
        $params = [
            'nosubmissions' => 0,
            'id' => $this->mygradesassignment1->id,
        ];
        $DB->execute("UPDATE {assign} SET nosubmissions = ? WHERE id = ?", $params);

        // Create the assignment grade, along with some feedback.
        $this->add_assignment_grade(
            $this->mygradesassignment1->id,
            $this->student1->id,
            $this->teacher->id,
            14,
            ASSIGN_SUBMISSION_STATUS_NEW,
            'Outstanding work.'
        );

        $gradeitem = $DB->get_record('grade_items',
            [
                'courseid' => $this->mygradescourse->id,
                'iteminstance' => $this->mygradesassignment1->id
            ], 
            '*', 
            MUST_EXIST
        );

        // This should update the assign_submission table and the grades_grade table.
        $this->release_assignment_grades($this->student1->id, $this->mygradesassignment1, $gradeitem->id);

        // The above has now set gradetype to points, we need to reset this to Scale.
        $params = [
            2,
            $this->scalea->id,
            $this->mygradesassignment1->id,
        ];
        $DB->execute("UPDATE {grade_items} SET gradetype = ?, scaleid = ? WHERE iteminstance = ?", $params);

        // We need to now import this item into MyGrades
        $userlist = [
            $this->student1->id,
        ];
        $this->import_grades($this->mygradescourse->id, $gradeitem->id, $userlist);

        // Release aggregated grade "MyGrades Feedback link".
        $status = \local_gugrades\external\release_grades::execute($this->mygradescourse->id, $gradeitem->id, 0, false);
        $status = external_api::clean_returnvalue(
            \local_gugrades\external\release_grades::execute_returns(),
            $status
        );

        // Revert to being the student.
        $this->setUser($this->student1->id);
        
        $activities = \block_newgu_spdetails\activity::get_activityitems($mygradessummativesubcategoryid, $this->student1->id, "current");
        $coursedata = $activities['coursedata'];
        $courseitems = $coursedata['courseitems'];
        $courseitem = $courseitems[0];
        $activity = \block_newgu_spdetails\activity::activity_factory($gradeitem->id, $this->mygradesassignment1->course, 0);
        $activityurl = $activity->get_assessmenturl();

        // This should now point to the activity grade page.
        $this->assertStringContainsString($courseitem->grade_feedback_link, $activityurl . "#page-footer");
    }
}
