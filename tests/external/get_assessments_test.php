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
 * Testing the get assessments web service and the various stats that are returned.
 *
 * @package    block_newgu_spdetails
 * @copyright  2026 University of Glasgow
 * @author     Greg Pedder
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_newgu_spdetails\external;

use core_external\external_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/blocks/newgu_spdetails/tests/external/newgu_spdetails_advanced_testcase.php');

/**
 * Unit tests for the main get_assessments method.
 */
final class get_assessments_test extends \block_newgu_spdetails\external\newgu_spdetails_advanced_testcase {
    /**
     * Test that the call to get_assessments returns the currently enrolled courses.
     *
     * @covers \blocks\newgu_spdetails\classes\external\get_assessments
     */
    public function test_get_assessments_current(): void {
        global $DB;

        // We're the test student.
        $this->setUser($this->student1->id);

        // Fake a due date.
        $duedate = mktime(date("H"), date("i"), date("s"), date("m"), date("d") + 5, date("Y"));

        $assignment = $this->getDataGenerator()->create_module('assign', [
            'name' => 'Regular Assignment 1',
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'course' => $this->mygradescourse->id,
            'duedate' => $duedate,
            'gradetype' => 2,
            'grademax' => 40,
            'scaleid' => $this->scalea->id,
        ]);

        // Create_module gives us stuff for free, however, it doesn't set the categoryid correctly.
        $params = [
            $this->mygradessummativecategory->id,
            $assignment->id,
        ];
        $DB->execute("UPDATE {grade_items} SET categoryid = ? WHERE iteminstance = ?", $params);

        // Check that our stats values are returned as expected.
        $activetab = 'current';
        $page = 0;
        $sortby = 'shortname';
        $sortorder = 'asc';
        $subcategory = null;
        $response = get_assessments::execute($activetab, $page, $sortby, $sortorder, $subcategory);
        $response = external_api::clean_returnvalue(
            get_assessments::execute_returns(),
            $response
        );
        $this->assertIsArray($response);
        $this->assertArrayHasKey('result', $response);

        $data = json_decode($response['result']);
        $parent = $data->parent;
        $coursename = $data->coursedata[0]->coursename;
        $subcategories = $data->coursedata[0]->subcategories;
        $this->assertEquals(0, $parent);
        $this->assertIsString($coursename);
        $this->assertEquals($this->mygradescourse->shortname, $coursename);
        $this->assertIsArray($subcategories);
        $this->assertObjectHasProperty('subcategories', $data->coursedata[0]);
        $this->assertEquals($this->mygradessummativecategory->fullname, $subcategories[0]->name);
        $this->assertEquals('Summative', $subcategories[0]->assessmenttype);
    }

    /**
     * Test that the call to get_assessments returns past enrolled courses.
     *
     * @covers \blocks\newgu_spdetails\classes\external\get_assessments
     */
    public function test_get_assessments_past(): void {
        global $DB;

        // We're the test student.
        $this->setUser($this->student1->id);

        // Some dates for our mock course.
        $startdate = mktime(0, 0, 0, date("m"), date("d"), date("Y") - 1);
        $enddate  = mktime(0, 0, 0, date("m") + 5, date("d"), date("Y") - 1);

        $pastcourse = $this->getDataGenerator()->create_course([
            'fullname' => 'Past Course Test',
            'shortname' => 'PCT1',
            'startdate' => $startdate,
            'enddate' => $enddate,
        ]);

        // Create some context.
        $mygradescontext = \context_course::instance($pastcourse->id);

        // This requires further mock "enabling" this as a MyGrades type course.
        // Find the custom field.
        $field = $DB->get_record('customfield_field', ['shortname' => 'studentmygrades'], '*', MUST_EXIST);
        $data = new \stdClass();
        $data->fieldid = $field->id;
        $data->instanceid = $pastcourse->id;
        $data->intvalue = 1;
        $data->value = 1;
        $data->valueformat = 0;
        $data->valuetrust = 0;
        $data->timecreated = time();
        $data->timemodified = time();
        $data->context = $mygradescontext;
        $DB->insert_record('customfield_data', $data);

        // Add some scales that this course can use.
        // Range 1 to 23.
        $scaleitems = 'H:0, G2:1, G1:2, F3:3, F2:4, F1:5, E3:6, E2:7, E1:8, D3:9, D2:10, D1:11,
            C3:12, C2:13, C1:14, B3:15, B2:16, B1:17, A5:18, A4:19, A3:20, A2:21, A1:22';
        $this->getDataGenerator()->create_scale([
            'name' => 'UofG 22 point scale with numeric values',
            'scale' => $scaleitems,
            'courseid' => $pastcourse->id,
        ]);

        // We need to enrol our student onto this course.
        $pastcoursecontext = \context_course::instance($pastcourse->id);
        $this->getDataGenerator()->enrol_user($this->student1->id, $pastcourse->id, $this->get_roleid());
        $this->getDataGenerator()->role_assign('student', $this->student1->id, $pastcoursecontext);

        // Create the parent grade category.
        $pastsummativecategory = $this->getDataGenerator()->create_grade_category([
            'fullname' => 'Summative Assessments',
            'courseid' => $pastcourse->id,
        ]);
        // Now create the sub categories that live under this parent.
        $pastsummativesubcategory = $this->getDataGenerator()->create_grade_category([
            'fullname' => 'Past Assessments Aggregated',
            'courseid' => $pastcourse->id,
            'parent' => $pastsummativecategory->id,
        ]);

        // Fake some due dates.
        $duedate1 = mktime(date("H"), date("i"), date("s"), date("m") + 2, date("d"), date("Y") - 1);

        $pastassignment1 = $this->getDataGenerator()->create_module('assign', [
            'name' => 'Past lab 1A',
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'course' => $pastcourse->id,
            'duedate' => $duedate1,
            'gradetype' => 2,
            'grademax' => 40,
            'scaleid' => $this->scalea->id,
        ]);

        // Create_module gives us stuff for free, however, it doesn't set the categoryid correctly.
        $pastsummativesubcategoryid = $pastsummativesubcategory->id;
        $params = [
            $pastsummativesubcategoryid,
            $pastassignment1->id,
        ];
        $DB->execute("UPDATE {grade_items} SET categoryid = ? WHERE iteminstance = ?", $params);

        // Check that our stats values are returned as expected.
        $activetab = 'past';
        $page = 0;
        $sortby = 'shortname';
        $sortorder = 'asc';
        $subcategory = null;
        $response = get_assessments::execute($activetab, $page, $sortby, $sortorder, $subcategory);
        $response = external_api::clean_returnvalue(
            get_assessments::execute_returns(),
            $response
        );
        $this->assertIsArray($response);
        $this->assertArrayHasKey('result', $response);

        $data = json_decode($response['result']);
        $parent = $data->parent;
        $coursename = $data->coursedata[0]->coursename;
        $subcategories = $data->coursedata[0]->subcategories;
        $this->assertEquals(0, $parent);
        $this->assertIsString($coursename);
        $this->assertEquals($pastcourse->shortname, $coursename);
        $this->assertIsArray($subcategories);
        $this->assertObjectHasProperty('subcategories', $data->coursedata[0]);
        $this->assertEquals($pastsummativecategory->fullname, $subcategories[0]->name);
        $this->assertEquals('Summative', $subcategories[0]->assessmenttype);
    }
}
