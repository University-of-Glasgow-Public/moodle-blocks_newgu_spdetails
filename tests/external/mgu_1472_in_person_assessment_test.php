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
 * Parent class from which all other test cases can extend.
 *
 * @package    block_newgu_spdetails
 * @author     Greg Pedder <greg.pedder@glasgow.ac.uk>
 * @copyright  2026 University of Glasgow
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_newgu_spdetails\external;

use core_external\external_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/blocks/newgu_spdetails/tests/external/newgu_spdetails_advanced_testcase.php');

/**
 * Unit tests for validating using an Assignment activity as a "grade placeholder".
 *
 * Basically set this up with only a due date, and no submissions, this returns as "Upcoming" for a due date in the future,
 * "Not yet graded" when the due date is in the past prior to grading, and "Graded" once a grade has been given. An Assignment
 * activity with these settings will never appear as Overdue.
 *
 * @see MGU-1472 for further details about the change to the purpose of this activity.
 */
final class mgu_1472_in_person_assessment_test extends \block_newgu_spdetails\external\newgu_spdetails_advanced_testcase {
    /**
     * Test that for an assignment set up to not accept submissions, where the due date is in the future, the Upcoming number
     * is correct on the charts.
     *
     * @covers \blocks\newgu_spdetails\classes\external\get_assessmentsoverview
     */
    public function test_in_person_assessment_future_due_date(): void {
        global $DB;

        // We're the test student.
        $this->setUser($this->student1->id);

        // Set a future due date.
        $duedate = mktime(date("H"), date("i"), date("s"), date("m"), date("d") + 7, date("Y"));
        // Not accepting submissions seems to be set to 1 by default.
        $ipaassignment = $this->getDataGenerator()->create_module('assign', [
            'name' => 'IPA Assignment 1',
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'course' => $this->mygradescourse->id,
            'duedate' => $duedate,
            'gradetype' => 2,
            'grademax' => 50,
            'scaleid' => $this->scalea->id,
        ]);

        // Create_module gives us stuff for free, however, it doesn't set the categoryid correctly.
        $params = [
            $this->mygradessummativesubcategory->id,
            $ipaassignment->id,
        ];
        $DB->execute("UPDATE {grade_items} SET categoryid = ? WHERE iteminstance = ?", $params);

        // Check that our stats values are returned as expected.
        $stats = get_assessmentsoverview::execute();
        $stats = external_api::clean_returnvalue(
            get_assessmentsoverview::execute_returns(),
            $stats
        );
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('upcoming', $stats[0]);
        $this->assertEquals(1, $stats[0]['upcoming']);
        $this->assertArrayHasKey('overdue', $stats[0]);
        $this->assertEquals(0, $stats[0]['overdue']);
        $this->assertArrayHasKey('sub_assess', $stats[0]);
        $this->assertEquals(0, $stats[0]['sub_assess']);
        $this->assertArrayHasKey('assess_marked', $stats[0]);
        $this->assertEquals(0, $stats[0]['assess_marked']);

        $gradeitems = $this->api->get_gradable_activities(
            'current',
            $this->student1->id,
            'duedate',
            'asc',
            $this->mygradessummativesubcategory->id
        );

        $this->assertIsArray($gradeitems);
        $this->assertCount(1, $gradeitems['coursedata']['courseitems']);

        // Check for the status.
        $this->assertStringContainsString(
            get_string('status_upcoming', 'block_newgu_spdetails'),
            $gradeitems['coursedata']['courseitems'][0]->grade_status
        );
    }

    /**
     * Test that for an assignment set up to not accept submissions, where the due date is in the past, and the assignment has not
     * been graded, the charts status' Upcoming, Overdue, Submitted and Graded
     *
     * @covers \blocks\newgu_spdetails\classes\external\get_assessmentsoverview
     */
    public function test_in_person_assessment_past_due_date_not_graded(): void {
        global $DB;

        // We're the test student.
        $this->setUser($this->student1->id);

        // Set a past due date.
        $duedate = mktime(date("H"), date("i"), date("s"), date("m"), date("d") - 7, date("Y"));
        // Not accepting submissions seems to be set to 1 by default.
        $ipaassignment = $this->getDataGenerator()->create_module('assign', [
            'name' => 'IPA Assignment 2',
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'course' => $this->mygradescourse->id,
            'duedate' => $duedate,
            'gradetype' => 2,
            'grademax' => 50,
            'scaleid' => $this->scalea->id,
        ]);

        // Create_module gives us stuff for free, however, it doesn't set the categoryid correctly.
        $params = [
            $this->mygradessummativesubcategory->id,
            $ipaassignment->id,
        ];
        $DB->execute("UPDATE {grade_items} SET categoryid = ? WHERE iteminstance = ?", $params);

        // Check that our stats values are returned as expected.
        $stats = get_assessmentsoverview::execute();
        $stats = external_api::clean_returnvalue(
            get_assessmentsoverview::execute_returns(),
            $stats
        );
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('upcoming', $stats[0]);
        $this->assertEquals(0, $stats[0]['upcoming']);
        $this->assertArrayHasKey('overdue', $stats[0]);
        $this->assertEquals(0, $stats[0]['overdue']);
        $this->assertArrayHasKey('sub_assess', $stats[0]);
        $this->assertEquals(1, $stats[0]['sub_assess']);
        $this->assertArrayHasKey('assess_marked', $stats[0]);
        $this->assertEquals(0, $stats[0]['assess_marked']);

        $gradeitems = $this->api->get_gradable_activities(
            'current',
            $this->student1->id,
            'duedate',
            'asc',
            $this->mygradessummativesubcategory->id
        );

        $this->assertIsArray($gradeitems);
        $this->assertCount(1, $gradeitems['coursedata']['courseitems']);

        // Check for the status.
        $this->assertStringContainsString(
            get_string('status_text_notyetgraded', 'block_newgu_spdetails'),
            $gradeitems['coursedata']['courseitems'][0]->status_text
        );
    }

    /**
     * Test that for an assignment set up to not accept submissions, Graded match.
     *
     * @covers \blocks\newgu_spdetails\classes\external\get_assessmentsoverview
     */
    public function test_in_person_assessment_past_due_date_graded(): void {
        global $DB;

        // We're the test student.
        $this->setUser($this->student1->id);

        // Set a past due date.
        $duedate = mktime(date("H"), date("i"), date("s"), date("m"), date("d") - 7, date("Y"));
        // Not accepting submissions seems to be set to 1 by default.
        $ipaassignment = $this->getDataGenerator()->create_module('assign', [
            'name' => 'IPA Assignment 2',
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'course' => $this->mygradescourse->id,
            'duedate' => $duedate,
            'gradetype' => 2,
            'grademax' => 50,
            'scaleid' => $this->scalea->id,
        ]);

        // Create_module gives us stuff for free, however, it doesn't set the categoryid correctly.
        $params = [
            $this->mygradessummativesubcategory->id,
            $ipaassignment->id,
        ];
        $DB->execute("UPDATE {grade_items} SET categoryid = ? WHERE iteminstance = ?", $params);

        // Create the assignment submission entries.
        $this->add_assignment_grade(
            $ipaassignment->id,
            $this->student1->id,
            $this->teacher->id,
            35,
            ASSIGN_SUBMISSION_STATUS_SUBMITTED
        );

        $gradeitemid = $this->get_grade_item('', 'assign', $ipaassignment->id);
        $DB->insert_record('grade_grades', [
            'itemid' => $gradeitemid,
            'userid' => $this->student1->id,
            'finalgrade' => 13,
        ]);

        // Check that our stats values are returned as expected.
        $stats = get_assessmentsoverview::execute();
        $stats = external_api::clean_returnvalue(
            get_assessmentsoverview::execute_returns(),
            $stats
        );
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('upcoming', $stats[0]);
        $this->assertEquals(0, $stats[0]['upcoming']);
        $this->assertArrayHasKey('overdue', $stats[0]);
        $this->assertEquals(0, $stats[0]['overdue']);
        $this->assertArrayHasKey('sub_assess', $stats[0]);
        $this->assertEquals(0, $stats[0]['sub_assess']);
        $this->assertArrayHasKey('assess_marked', $stats[0]);
        $this->assertEquals(1, $stats[0]['assess_marked']);

        $gradeitems = $this->api->get_gradable_activities(
            'current',
            $this->student1->id,
            'duedate',
            'asc',
            $this->mygradessummativesubcategory->id
        );

        $this->assertIsArray($gradeitems);
        $this->assertCount(1, $gradeitems['coursedata']['courseitems']);

        // Check for the status.
        $this->assertStringContainsString(
            get_string('status_graded', 'block_newgu_spdetails'),
            $gradeitems['coursedata']['courseitems'][0]->grade_status
        );
    }
}
