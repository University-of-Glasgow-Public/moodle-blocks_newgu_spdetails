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
 * Custom class which sets up (complex) gradebook schemas and data for grade category testing.
 *
 * @package    block_newgu_spdetails
 * @author     Greg Pedder <greg.pedder@glasgow.ac.uk>
 * @copyright  2026 University of Glasgow
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_newgu_spdetails\external;

use core_external\external_api;
use local_gugrades\external\import_grades_users;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/blocks/newgu_spdetails/tests/external/newgu_spdetails_base_testcase.php');

/**
 * This deals with the scaffolding for the tests.
 */
class newgu_spdetails_custom_testcase extends \block_newgu_spdetails\external\newgu_spdetails_base_testcase {
    /**
     * @var array $gradeitems
     */
    protected array $gradeitems;

    /**
     * @var array $gradeitemids
     */
    protected array $gradeitemids;

    /**
     * Have the parent set the initial test structure up for us.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Process schema json (recursive)
     * $gradeitemid specifies where to put new grade items
     * @param array $items
     * @param int $gradeitemid
     */
    protected function build_schema(array $items, int $gradeitemid) {
        global $DB;

        // Array defines which aggregation type calls which function.
        $lookup = [
            'mean' => \GRADE_AGGREGATE_MEAN,
            'median' => \GRADE_AGGREGATE_MEDIAN,
            'min' => \GRADE_AGGREGATE_MIN,
            'max' => \GRADE_AGGREGATE_MAX,
            'mode' => \GRADE_AGGREGATE_MODE,
            'weighted_mean' => \GRADE_AGGREGATE_WEIGHTED_MEAN,
            'weighted_mean2' => \GRADE_AGGREGATE_WEIGHTED_MEAN2,
            'extracredit_mean' => \GRADE_AGGREGATE_EXTRACREDIT_MEAN,
            'sum' => \GRADE_AGGREGATE_MEAN, // Natural does the same thing as mean.
        ];

        $this->gradeitems = [];

        foreach ($items as $item) {
            // Get weight ('aggregationcoef' in the grade_items table).
            if (isset($item->weight)) {
                $weight = $item->weight;
            } else {
                $weight = 1;
            }

            // Get grademax for points only (default is 100).
            if (isset($item->grademax)) {
                $grademax = $item->grademax;
            } else {
                $grademax = 100;
            }

            // Is it a grade item?
            if (!$item->category) {
                $gradeitem = $this->getDataGenerator()->create_grade_item(
                    ['courseid' => $this->mygradescourse->id, 'itemname' => $item->name]
                );

                // Default is points.
                $type = empty($item->type) ? "points" : $item->type;

                // Is it a scale (default is points)?
                if ($type == 'schedulea') {
                    $gradeitem->gradetype = GRADE_TYPE_SCALE;
                    $gradeitem->grademax = 23.0;
                    $gradeitem->grademin = 1.0;
                    $gradeitem->scaleid = $this->scalea->id;
                    $gradeitem->aggregationcoef = $weight;
                    $DB->update_record('grade_items', $gradeitem);
                } else if ($type == 'scheduleb') {
                    $gradeitem->gradetype = GRADE_TYPE_SCALE;
                    $gradeitem->grademax = 8.0;
                    $gradeitem->grademin = 1.0;
                    $gradeitem->scaleid = $this->scaleb->id;
                    $gradeitem->aggregationcoef = $weight;
                    $DB->update_record('grade_items', $gradeitem);
                } else if ($type == "points") {
                    $gradeitem->gradetype = GRADE_TYPE_VALUE;
                    $gradeitem->grademax = $grademax;
                    $gradeitem->grademin = 0;
                    $gradeitem->aggregationcoef = $weight;
                    $DB->update_record('grade_items', $gradeitem);
                } else {
                    throw new \moodle_exception('JSON contains invalid grade type - ' . $type, '', '', '');
                }
                $this->move_gradeitem_to_category($gradeitem->id, $gradeitemid);
                $this->gradeitems[] = $gradeitem;
            } else {
                // Aggregation? (default is weighted_mean).
                if (!empty($item->aggregation)) {
                    $aggregation = $lookup[$item->aggregation];
                } else {
                    $aggregation = \GRADE_AGGREGATE_WEIGHTED_MEAN;
                }

                // Drop lowest (droplow)?
                if (!empty($item->droplow)) {
                    $droplow = $item->droplow;
                } else {
                    $droplow = 0;
                }

                // In which case it must be a grade category.
                $gradecategory = $this->getDataGenerator()->create_grade_category([
                    'courseid' => $this->mygradescourse->id,
                    'fullname' => $item->name,
                    'parent' => $gradeitemid,
                    'aggregation' => $aggregation,
                    'droplow' => $droplow,
                ]);

                // Set weight (aggregationcoef).
                $gradeitem = $DB->get_record(
                    'grade_items',
                    ['itemtype' => 'category', 'iteminstance' => $gradecategory->id],
                    '*',
                    MUST_EXIST
                );
                $gradeitem->aggregationcoef = $weight;
                $DB->update_record('grade_items', $gradeitem);

                // Create child items (if present).
                if (!empty($item->children)) {
                    $this->build_schema($item->children, $gradecategory->id);
                }
            }
        }

        \local_gugrades\api::reset_bulk_data($this->mygradescourse->id);
    }

    /**
     * Import json grades schema
     * Returns array of gradeitemids (probably need to run import and such)
     * @param string $name
     * @return array
     */
    public function load_schema(string $name) {
        global $CFG, $DB;

        $path = $CFG->dirroot . '/blocks/newgu_spdetails/tests/external/gradedata/' . $name . '.json';
        $filecontents = file_get_contents($path);

        $json = json_decode($filecontents);
        $this->build_schema($json, 0);

        // Get gradeitems.
        $gradeitems = $DB->get_records('grade_items', ['itemtype' => 'manual']);

        return array_column($gradeitems, 'id');
    }

    /**
     * Import json data
     * Data refers to item names already uploaded in the schema,
     * so make sure the data matches the schema!
     * Data is imported for a single user
     * @param string $name
     * @param int $userid
     */
    public function load_data(string $name, int $userid): void {
        global $CFG, $DB;

        $path = $CFG->dirroot . '/blocks/newgu_spdetails/tests/external/gradedata/' . $name . '.json';
        $filecontents = file_get_contents($path);

        $json = json_decode($filecontents);

        foreach ($json as $item) {
            // Look up grade item just using name
            // There's only one course, anyway.
            $gradeitem = $DB->get_record('grade_items', ['itemname' => $item->item], '*', MUST_EXIST);
            $this->write_grade_grades($gradeitem, $userid, $item->grade);
        }
    }

    /**
     * Set the aggregation strategy for a gradecategorid
     * @param int $gradecategoryid
     * @param int $aggregation
     */
    public function set_strategy(int $gradecategoryid, int $aggregation): void {
        global $DB;

        $gcat = $DB->get_record('grade_categories', ['id' => $gradecategoryid], '*', MUST_EXIST);
        $gcat->aggregation = $aggregation;
        $DB->update_record('grade_categories', $gcat);
    }

    /**
     * Write a grade_grade
     * One would think there should be an API for this but I can't find
     * anything that makes sense...
     * @param object $gradeitem
     * @param int $userid
     * @param float|string $rawgrade
     */
    protected function write_grade_grades(object $gradeitem, int $userid, float|string $rawgrade) {
        global $DB;

        // If gradeitem is a scale...
        if ($gradeitem->gradetype == GRADE_TYPE_SCALE) {
            if (!$scale = $DB->get_record('scale', ['id' => $gradeitem->scaleid])) {
                throw new \moodle_exception('Scale not found for id = ' . $gradeitem->scaleid, '', '', '');
            }
            $items = array_map('trim', explode(',', $scale->scale));
            if (($key = array_search($rawgrade, $items)) === false) {
                throw new \exception('Scale item ' . $rawgrade . ' not found in scale');
            }

            // New rawgrade is array key + 1 (scales start at 1, not 0).
            $rawgrade = $key + 1;
        }

        $grade = new \stdClass();
        $grade->itemid = $gradeitem->id;
        $grade->userid = $userid;
        $grade->rawgrade = $rawgrade;
        $grade->finalgrade = $rawgrade;
        $grade->timecreated = time();
        $grade->timemodified = time();
        $grade->information = 'UnitTest grade';
        $grade->informationformat = FORMAT_PLAIN;
        $grade->feedback = 'UnitTest Feedback';
        $grade->feedbackformat = FORMAT_PLAIN;

        $DB->insert_record('grade_grades', $grade);
    }

    /**
     * Import set of grades
     * @param int $courseid
     * @param int $gradeitemid
     * @param array $userlist
     * @param string $fillns
     * @param string $reason = 'FIRST'
     * @param string $importadditional = 'update'
     */
    protected function import_grades(
        int $courseid,
        int $gradeitemid,
        array $userlist,
        string $fillns = '',
        string $reason = 'FIRST',
        string $importadditional = 'update'
    ) {
        $status = import_grades_users::execute(
            courseid:       $courseid,
            gradeitemid:    $gradeitemid,
            additional:     $importadditional,
            fillns:         $fillns,
            reason:         $reason,
            other:          '',
            dryrun:         false,
            userlist:       $userlist
        );
        $status = external_api::clean_returnvalue(
            import_grades_users::execute_returns(),
            $status
        );
    }

    /**
     * Return a grade category object from the given name of category
     * @param string $catname
     * @return object
     *
     */
    public function get_grade_category(string $catname) {
        global $DB;

        $gcat = $DB->get_record('grade_categories', ['fullname' => $catname], '*', MUST_EXIST);

        return $gcat;
    }

    /**
     * Get gradeitemid from grade category name
     * @param string $catname
     * @return object
     */
    public function get_gradeitem_from_grade_category(string $catname) {
        global $DB;

        $catobj = $this->get_grade_category($catname);
        $item = $DB->get_record('grade_items', ['itemtype' => 'category', 'iteminstance' => $catobj->id], '*', MUST_EXIST);
        $item->aggregation = $catobj->aggregation;
        $item->droplow = $catobj->droplow;

        return $item;
    }

    /**
     * Get gradeitem object for given name of item
     * @param string $itemname
     * @return object
     */
    public function get_gradeitem_from_id(string $itemname) {
        global $DB;

        $item = $DB->get_record('grade_items', ['itemname' => $itemname], '*', MUST_EXIST);

        return $item;
    }
}
