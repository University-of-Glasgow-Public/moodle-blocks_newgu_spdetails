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
use local_gugrades\external\release_grades;
use local_gugrades\external\get_alter_weight_form;
use local_gugrades\external\save_altered_weights;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/blocks/newgu_spdetails/tests/external/newgu_spdetails_custom_testcase.php');

/**
 * Tests for grade category weights and their appearance on Student MyGrades. Courses are assumed to be MyGrades enabled.
 */
final class mgu_1410_category_weights_test extends \block_newgu_spdetails\external\newgu_spdetails_custom_testcase {
    /**
     * @var object $gradeitemobj
     */
    public object $gradeitemobj;

    /**
     * Called before every test
     */
    protected function setUp(): void {

        parent::setUp();
        $this->resetAfterTest(true);

        // We're the teacher in the context of these tests.
        $this->setUser($this->teacher->id);

        // Install test schema.
        $this->gradeitemids = $this->load_schema('schema_mgu1410');

        // Import grades only for one student (so far).
        $userlist = [
            $this->student1->id,
        ];

        // Install test data for student.
        $this->load_data('data1a', $this->student1->id);

        // Import ALL gradeitems.
        foreach ($this->gradeitemids as $gradeitemid) {
            $this->import_grades($this->mygradescourse->id, $gradeitemid, $userlist);
        }

        // Let the individual tests deal with the mechanics of changing and releasing things.
    }


    /**
     * Test that a course with an unreleased grade category returns the category weight from the
     * default settings - this is effectively whatever has been set in Gradebook.
     *
     * @covers \blocks\newgu_spdetails\classes\activity
     */
    public function test_unreleased_grade_category_default_weight(): void {

        // Get gradeitem object for category "Workshop Tests".
        $this->gradeitemobj = $this->get_gradeitem_from_grade_category('Workshop Tests');
        $this->gradeitemobj->category = new stdClass();
        $this->gradeitemobj->category->id = $this->gradeitemobj->iteminstance;
        $this->gradeitemobj->category->hidden = 0;
        $this->gradeitemobj->category->fullname = 'Workshop Tests';
        $this->gradeitemobj->category->aggregation = $this->gradeitemobj->aggregation;
        $this->gradeitemobj->category->droplow = $this->gradeitemobj->droplow;
        $this->gradeitemobj->category->itemid = $this->gradeitemobj->id;
        $this->gradeitemobj->category->iteminstance = $this->gradeitemobj->iteminstance;
        $gradecategory = new stdClass();
        $gradecategory->category = $this->gradeitemobj->category;
        $this->gradeitemobj->categories[] = $gradecategory;

        // Get grade item data which is represents the activity.
        $gradeitem = $this->get_gradeitem_from_id('Maths Workshop');
        $gradeitem->itemmodule = 'quiz';
        $gradeitem->courseid = $this->gradeitemobj->courseid;
        $this->gradeitemobj->items[] = $gradeitem;

        // Same calculation as used in course::return_weight().
        $weight = (($this->gradeitemobj->aggregationcoef > 1) ? $this->gradeitemobj->aggregationcoef :
            $this->gradeitemobj->aggregationcoef * 100);
        $expected = ($weight > 0) ? round($weight, 2) : 0;
        $data = $this->activityapi->process_get_activities(
            $this->gradeitemobj,
            $this->gradeitemobj->courseid,
            $this->gradeitemobj->id,
            $this->student1->id,
            'current',
            'summative'
        );
        $this->assertEquals($expected, $data['courseitems'][0]->raw_category_weight);
    }

    /**
     * Test that a course with an unreleased grade category with an altered weight, returns the category weight
     * from the default settings - this is effectively whatever has been set in Gradebook.
     *
     * @covers \blocks\newgu_spdetails\classes\activity
     */
    public function test_unreleased_grade_category_with_altered_weight(): void {

        // Get gradeitem object for category "Workshop Tests".
        $this->gradeitemobj = $this->get_gradeitem_from_grade_category('Workshop Tests');

        // Create items array for changing the weight of the grade category.
        $saveitems = [
            [
                'gradeitemid' => $this->gradeitemobj->id,
                'weight' => '0.30',
            ],
        ];

        // Reason for the update.
        $reason = 'Unreleased grade category weight change.';

        // Save weights.
        $nothing = save_altered_weights::execute(
            $this->gradeitemobj->courseid,
            $this->gradeitemobj->iteminstance,
            $this->student1->id,
            false,
            $reason,
            $saveitems
        );
        $nothing = external_api::clean_returnvalue(
            save_altered_weights::execute_returns(),
            $nothing
        );

        // Get gradeitem object for category "Workshop Tests".
        $this->gradeitemobj = $this->get_gradeitem_from_grade_category('Workshop Tests');
        $this->gradeitemobj->category = new stdClass();
        $this->gradeitemobj->category->id = $this->gradeitemobj->iteminstance;
        $this->gradeitemobj->category->hidden = 0;
        $this->gradeitemobj->category->fullname = 'Workshop Tests';
        $this->gradeitemobj->category->aggregation = $this->gradeitemobj->aggregation;
        $this->gradeitemobj->category->droplow = $this->gradeitemobj->droplow;
        $this->gradeitemobj->category->itemid = $this->gradeitemobj->id;
        $this->gradeitemobj->category->iteminstance = $this->gradeitemobj->iteminstance;
        $gradecategory = new stdClass();
        $gradecategory->category = $this->gradeitemobj->category;
        $this->gradeitemobj->categories[] = $gradecategory;

        // Get grade item data which is represents the activity.
        $gradeitem = $this->get_gradeitem_from_id('Maths Workshop');
        $gradeitem->itemmodule = 'quiz';
        $gradeitem->courseid = $this->gradeitemobj->courseid;
        $this->gradeitemobj->items[] = $gradeitem;

        // Same calculation as used in course::return_weight().
        $weight = (($this->gradeitemobj->aggregationcoef > 1) ? $this->gradeitemobj->aggregationcoef :
            $this->gradeitemobj->aggregationcoef * 100);
        $expected = ($weight > 0) ? round($weight, 2) : 0;
        $data = $this->activityapi->process_get_activities(
            $this->gradeitemobj,
            $this->gradeitemobj->courseid,
            $this->gradeitemobj->id,
            $this->student1->id,
            'current',
            'summative'
        );
        $this->assertEquals($expected, $data['courseitems'][0]->raw_category_weight);
    }

    /**
     * Test that a course with a released grade category returns the category weight from the
     * default settings - this is effectively whatever has been set in Gradebook.
     *
     * @covers \blocks\newgu_spdetails\classes\activity
     */
    public function test_released_grade_category_default_weight(): void {

        // Get gradeitem object for category "Workshop Tests".
        $this->gradeitemobj = $this->get_gradeitem_from_grade_category('Workshop Tests');

        // Release aggregated grade "Workshop Tests".
        $status = release_grades::execute($this->mygradescourse->id, $this->gradeitemobj->id, 0, false);
        $status = external_api::clean_returnvalue(
            release_grades::execute_returns(),
            $status
        );

        $this->gradeitemobj->category = new stdClass();
        $this->gradeitemobj->category->id = $this->gradeitemobj->iteminstance;
        $this->gradeitemobj->category->hidden = 0;
        $this->gradeitemobj->category->fullname = 'Workshop Tests';
        $this->gradeitemobj->category->aggregation = $this->gradeitemobj->aggregation;
        $this->gradeitemobj->category->droplow = $this->gradeitemobj->droplow;
        $this->gradeitemobj->category->itemid = $this->gradeitemobj->id;
        $this->gradeitemobj->category->iteminstance = $this->gradeitemobj->iteminstance;
        $gradecategory = new stdClass();
        $gradecategory->category = $this->gradeitemobj->category;
        $this->gradeitemobj->categories[] = $gradecategory;

        // Get grade item data which represents the activity.
        $gradeitem1 = $this->get_gradeitem_from_id('Maths Workshop');
        $gradeitem1->itemmodule = 'quiz';
        $gradeitem1->courseid = $this->gradeitemobj->courseid;
        $gradeitem2 = $this->get_gradeitem_from_id('Chem Workshop');
        $gradeitem2->itemmodule = 'quiz';
        $gradeitem2->courseid = $this->gradeitemobj->courseid;
        $gradeitem3 = $this->get_gradeitem_from_id('Physics Workshop');
        $gradeitem3->itemmodule = 'quiz';
        $gradeitem3->courseid = $this->gradeitemobj->courseid;
        $gradeitem4 = $this->get_gradeitem_from_id('Maths Workshop');
        $gradeitem4->itemmodule = 'quiz';
        $gradeitem4->courseid = $this->gradeitemobj->courseid;
        $this->gradeitemobj->items[] = $gradeitem1;
        $this->gradeitemobj->items[] = $gradeitem2;
        $this->gradeitemobj->items[] = $gradeitem3;
        $this->gradeitemobj->items[] = $gradeitem4;

        // Same calculation as used in course::return_weight().
        $weight = (($this->gradeitemobj->aggregationcoef > 1) ? $this->gradeitemobj->aggregationcoef :
            $this->gradeitemobj->aggregationcoef * 100);
        $expected = (($weight > 0) ? round($weight, 2) : 0) . "%";
        $data = $this->activityapi->process_get_activities(
            $this->gradeitemobj,
            $this->gradeitemobj->courseid,
            $this->gradeitemobj->iteminstance,
            $this->student1->id,
            'current',
            'summative'
        );
        $this->assertEquals($expected, $data['weighttowardscourse']);
    }

    /**
     * Test that a course where the grade category is released with an altered weight, the altered weight
     * value is returned instead of the default weight.
     *
     * @covers \blocks\newgu_spdetails\classes\activity
     */
    public function test_released_grade_category_with_altered_weight(): void {

        global $DB;

        // Get gradeitem object for category "Workshop Tests".
        $this->gradeitemobj = $this->get_gradeitem_from_grade_category('Workshop Tests');

        // Create items array for changing the weight of the grade category.
        $saveitems = [
            [
                'gradeitemid' => $this->gradeitemobj->id,
                'weight' => '0.30',
            ],
        ];

        // Reason for the update.
        $reason = 'Grade category weight change.';

        // Save weights.
        $nothing = save_altered_weights::execute(
            $this->gradeitemobj->courseid,
            $this->gradeitemobj->iteminstance,
            $this->student1->id,
            false,
            $reason,
            $saveitems
        );
        $nothing = external_api::clean_returnvalue(
            save_altered_weights::execute_returns(),
            $nothing
        );

        // Release aggregated grade "Workshop Tests".
        $status = release_grades::execute($this->mygradescourse->id, $this->gradeitemobj->id, 0, false);
        $status = external_api::clean_returnvalue(
            release_grades::execute_returns(),
            $status
        );

        $this->gradeitemobj->category = new stdClass();
        $this->gradeitemobj->category->id = $this->gradeitemobj->iteminstance;
        $this->gradeitemobj->category->hidden = 0;
        $this->gradeitemobj->category->fullname = 'Workshop Tests';
        $this->gradeitemobj->category->aggregation = $this->gradeitemobj->aggregation;
        $this->gradeitemobj->category->droplow = $this->gradeitemobj->droplow;
        $this->gradeitemobj->category->itemid = $this->gradeitemobj->id;
        $this->gradeitemobj->category->iteminstance = $this->gradeitemobj->iteminstance;
        $gradecategory = new stdClass();
        $gradecategory->category = $this->gradeitemobj->category;
        $this->gradeitemobj->categories[] = $gradecategory;

        // Get grade item data which represents the activity.
        $gradeitem1 = $this->get_gradeitem_from_id('Maths Workshop');
        $gradeitem1->itemmodule = 'quiz';
        $gradeitem1->courseid = $this->gradeitemobj->courseid;
        $gradeitem2 = $this->get_gradeitem_from_id('Chem Workshop');
        $gradeitem2->itemmodule = 'quiz';
        $gradeitem2->courseid = $this->gradeitemobj->courseid;
        $gradeitem3 = $this->get_gradeitem_from_id('Physics Workshop');
        $gradeitem3->itemmodule = 'quiz';
        $gradeitem3->courseid = $this->gradeitemobj->courseid;
        $gradeitem4 = $this->get_gradeitem_from_id('Maths Workshop');
        $gradeitem4->itemmodule = 'quiz';
        $gradeitem4->courseid = $this->gradeitemobj->courseid;
        $this->gradeitemobj->items[] = $gradeitem1;
        $this->gradeitemobj->items[] = $gradeitem2;
        $this->gradeitemobj->items[] = $gradeitem3;
        $this->gradeitemobj->items[] = $gradeitem4;

        // Check that they have been written to local_gugrades_altered_weight.
        $alteredweight = $DB->get_records('local_gugrades_altered_weight', ['courseid' => $this->gradeitemobj->courseid]);
        $alteredweight = array_values($alteredweight);

        $weight = (($alteredweight[0]->weight > 1) ? $alteredweight[0]->weight :
            $alteredweight[0]->weight * 100);
        $expected = (($weight > 0) ? round($weight, 2) : 0) . "%";
        $data = $this->activityapi->process_get_activities(
            $this->gradeitemobj,
            $this->gradeitemobj->courseid,
            $this->gradeitemobj->iteminstance,
            $this->student1->id,
            'current',
            'summative'
        );
        $this->assertEquals($expected, $data['weighttowardscourse']);
    }
}
