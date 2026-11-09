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
 * Provides generic activity related methods.
 *
 * @package    block_newgu_spdetails
 * @author     Greg Pedder <greg.pedder@glasgow.ac.uk>
 * @copyright  2024 University of Glasgow
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_newgu_spdetails;

use local_gugrades\api;
use block_newgu_spdetails\activities\default_activity;
use block_newgu_spdetails\activityitems;
use block_newgu_spdetails\course;
use grade_item;
use mod_questionnaire\responsetype\boolean;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/grade/constants.php');
require_once($CFG->libdir . '/grade/grade_category.php');
require_once($CFG->libdir . '/grade/grade_item.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/querylib.php');

define('ITEM_URL', $CFG->wwwroot . '/');
define('ITEM_SCRIPT', '/view.php?id=');

/**
 * This class processes activities for MyGrades, and Gradebook course types.
 *
 * It provides a factory method for instantiating the relevant activity which can
 * then be used to provide further functionality.
 */
class activity {
    /**
     * @var array $excludedactivities
     * @see MGU-975 - we're now restricting a number of activity types that no longer
     * need to appear on Student MyGrades.
     *
     */
    public static $excludedactivities = [
        'attendance',
        'board',
        'book',
        'chat',
        'checklist',
        'choice',
        'choicegroup',
        'customcert',
        'feedback',
        'file',
        'folder',
        'game',
        'glossary',
        'hsuforum',
        'imscp',
        'kalvidres',
        'label',
        'oublog',
        'page',
        'pdfannotator',
        'reengagement',
        'scheduler',
        'survey',
        'url',
        'wiki',
        'zoom',
    ];

    /**
     * Main method called from the API.
     *
     * @param int $categoryid
     * @param int $userid
     * @param string $activetab
     * @return array
     */
    public static function get_activityitems(int $categoryid, int $userid, string $activetab): array {
        $activitydata = [];
        $coursedata = [];
        $coursedata['haserror'] = false;

        // What's my parent?
        // I need the parent of the parent in order to be able to always
        // step 'up' a level. \local_gugrades\grades::get_activitytree only
        // gives me the parent id, which breaks our mechanism.
        [$gradecategory, $parentid] = course::get_grade_category_parent($categoryid);
        $activitydata['parent'] = $parentid;

        $course = get_course($gradecategory->courseid);
        $coursedata['coursename'] = $course->shortname;
        $coursedata['subcatfullname'] = ($gradecategory->fullname != '?' ? $gradecategory->fullname : '');

        // The assessment type is derived from the parent - which works only
        // as long as the parent name contains 'Formative' or 'Summative'.
        [$assessmenttype, $item] = self::get_activitytype($course->id, $categoryid, $gradecategory->fullname);

        // We don't need the status column for past courses.
        $coursedata['hidestatuscol'] = (($activetab == 'past') ? true : false);

        // MGU-1372 - We need to check if the category grade item is a resit grade item.
        $coursedata['reassessment'] = \local_gugrades\grades::is_resit_gradeitem($item->id);
        $coursedata['reassessment_text'] = $coursedata['reassessment'] ? get_string('reassessment', 'block_newgu_spdetails') : '';

        $getactivities = api::get_activities($course->id, $categoryid);
        if ($getactivities[1] !== '') {
            // MGU-1386 - The return value contains an error message.
            $coursedata['hasdata'] = false;
            $coursedata['haserror'] = true;
            $errormsg = $getactivities[1];
            $coursedata['errormsg'] = strip_tags($errormsg);
            $activitydata['coursedata'] = $coursedata;
        } else {
            $activities = $getactivities[0];
            $activitiesdata = self::process_get_activities(
                $activities,
                $course->id,
                $categoryid,
                $userid,
                $activetab,
                $assessmenttype,
                $item
            );
            $coursedata['courseitems'] = $activitiesdata['courseitems'];
            $coursedata['hasdata'] = $activitiesdata['hasdata'];
            $coursedata['mygradesenabled'] = $activitiesdata['mygradesenabled'];
            $coursedata['hascategorygrade'] = $activitiesdata['hascategorygrade'];
            $coursedata['categorygrade'] = $activitiesdata['categorygrade'];
            $coursedata['hasgradecategory'] = $activitiesdata['hasgradecategory'];
            $coursedata['hasdualgrades'] = $activitiesdata['hasdualgrades'];
            $coursedata['parentisdualgrades'] = $activitiesdata['parentisdualgrades'];
            $coursedata['firstattemptgrade'] = $activitiesdata['firstattemptgrade'];
            $coursedata['awardgrade'] = $activitiesdata['awardgrade'];
            $coursedata['hascourseitems'] = $activitiesdata['hascourseitems'];
            $coursedata['weighttowardscourse'] = $activitiesdata['weighttowardscourse'];
            $activitydata['coursedata'] = $coursedata;
        }

        return $activitydata;
    }

    /**
     * Method to determine which course type API needs to be used in
     * order to process the returned grade category and course items.
     *
     * @param object $activityitems
     * @param int $courseid
     * @param int $categoryid
     * @param int $userid
     * @param string $activetab
     * @param string $assessmenttype
     * @return array
     */
    public static function process_get_activities(
        object $activityitems,
        int $courseid,
        int $categoryid,
        int $userid,
        string $activetab,
        string $assessmenttype,
        ?\grade_item $gradeitem = null
    ): array {
        $data = [];
        $data['mygradesenabled'] = false;
        $data['weighttowardscourse'] = '-';
        $data['hascategorygrade'] = false;
        $data['categorygrade'] = '';
        $data['courseitems'] = [];
        $data['hasgradecategory'] = false;
        $data['hasdualgrades'] = false;
        $data['parentisdualgrades'] = false;
        $data['firstattemptgrade'] = '';
        $data['awardgrade'] = '';
        $data['hascourseitems'] = false;
        $data['hasdata'] = false;
        // We've lost all knowledge at this point of the course type - fetch it again.
        $mygradesenabled = course::is_type_mygrades($courseid);
        // MGU-1368 The call to api::get_aggregation_dashboard_user() might not return data.
        $hasgradedata = false;

        if ($mygradesenabled) {
            // This call should return grade data that has been processed through the MyGrades tool.
            // This includes grade category data as well as individual grade item data.
            if ($gradedata = api::get_aggregation_dashboard_user($courseid, $categoryid, $userid)) {
                $data['mygradesenabled'] = true;

                // The weight value for this category can be derived from different places in the process.
                $weighttowardscourse = course::get_weight_towards_course(
                    $courseid,
                    $gradedata,
                    $activityitems
                );

                // Get the category grade, update $weighttowardscourse if necessary.
                [$hascategorygrade, $categorygrade, $weighttowardscourse] = course::get_category_grade(
                    $gradedata,
                    $weighttowardscourse,
                    $userid
                );

                $data['weighttowardscourse'] = $weighttowardscourse;
                $data['hascategorygrade'] = $hascategorygrade;
                $data['categorygrade'] = $categorygrade;

                $gradecategories = [];
                $gradeitems = [];
                $hasdualgrades = false;
                foreach ($gradedata['fields'] ?? [] as $tmpitem) {
                    if ($tmpitem['iscategory']) {
                        $gradecategories[] = $tmpitem;
                        if (!empty($tmpitem['dualgrades'])) {
                            $hasdualgrades = true;
                        }
                    } else {
                        $gradeitems[] = $tmpitem;
                    }
                }

                // We derive $hasdualgrades from $gradedata['fields'] - this can get lost. Check if the parent has this value.
                $parentisdualgrades = false;
                $parent = $gradedata['parent'] ?? null;
                if (is_object($parent) && $parent->dualgrades) {
                    $parentisdualgrades = true;
                }

                if ($activityitems->categories && count($activityitems->categories) > 0) {
                    $categorydata = course::process_mygrades_subcategories(
                        $courseid,
                        $gradecategories,
                        $activityitems->categories,
                        $assessmenttype,
                        $userid
                    );
                    $data['courseitems'] = $categorydata;
                    $data['hasgradecategory'] = true;
                    $data['hasdualgrades'] = $hasdualgrades;
                    $data['parentisdualgrades'] = $parentisdualgrades;
                    $data['firstattemptgrade'] = $gradedata['releasegrade1st'] ?? '';
                    $data['awardgrade'] = $gradedata['releasegrade'] ?? '';
                }

                if ($activityitems->items && count($activityitems->items) > 0) {
                    $activitydata = activityitems::process_mygrades_items(
                        $gradeitems,
                        $activityitems->items,
                        $activetab,
                        $assessmenttype,
                        $userid
                    );
                    $data['courseitems'] = self::merge_course_items($data['courseitems'], $activitydata);
                    $data['hascourseitems'] = true;
                }

                $hasgradedata = true;
            }
        }

        if (!$mygradesenabled || !$hasgradedata) {
            // The weight for this grade category can be derived from the aggregation coefficient
            // value of the grade item, this needs to have been set in Gradebook Setup however.
            $weighttowardscourse = new stdClass();
            $weighttowardscourse->grade_category_weight = 0;
            if ($gradeitem && $activityitems->category && count($activityitems->categories) > 0) {
                $tmpwtc = course::get_grade_category_weight($gradeitem, $activityitems->category);
                $weighttowardscourse->grade_category_weight = $tmpwtc->grade_category_weight;
            }
            $data['weighttowardscourse'] = $weighttowardscourse->grade_category_weight;

            if ($activityitems->categories && count($activityitems->categories) > 0) {
                $categorydata = course::process_default_subcategories($courseid, $activityitems->categories, $assessmenttype);
                $data['courseitems'] = $categorydata;
                $data['hasgradecategory'] = true;
            }

            if ($activityitems->items && count($activityitems->items) > 0) {
                $displayweights = self::display_activity_item_weights($weighttowardscourse, $activityitems->category);
                $activitydata = activityitems::process_default_items(
                    $activityitems->items,
                    $activetab,
                    $assessmenttype,
                    $displayweights,
                    $userid
                );
                $data['courseitems'] = self::merge_course_items($data['courseitems'], $activitydata);
                $data['hascourseitems'] = true;
            }
        }

        if (!empty($data['courseitems'])) {
            $data['hasdata'] = true;
            // Finalise the course items.
            $data['courseitems'] = self::finalise_course_items($data['courseitems']);
        }

        return $data;
    }

    /**
     * Merge course items with activity data.
     *
     * @param array $courseitems
     * @param array $activitydata
     * @return array
     */
    public static function merge_course_items(array $courseitems, array $activitydata): array {
        return array_merge($courseitems, $activitydata);
    }
    
    /**
     * Finalise the course items by sorting and reindexing them.
     *
     * @param array $courseitems
     * @return array
     */
    public static function finalise_course_items(array $courseitems): array {
        $tmpcourseitems = self::sort_course_items($courseitems);
        // An array of objects is returned, keyed by the sort order.
        // We need to reindex the array w/o losing the order of the items, this is needed by Mustache when iterating items.
        $finaliseditems = array_values($tmpcourseitems);

        return $finaliseditems;
    }

    /**
     * Generate an icon image path.
     *
     * @param object $cm
     * @param string $itemmodule
     * @return object|boolean
     */
    public static function get_activity_icon($cm, $itemmodule): mixed {
        if ($iconurl = $cm->get_icon_url()->out(false)) {
            $a = new \stdClass();
            $a->modulename = get_string('modulename', $itemmodule);
            $a->activityname = $cm->name;
            $iconalt = get_string('icon_alt_text', 'block_newgu_spdetails', $a);
            $a->iconurl = $iconurl;
            $a->iconalt = $iconalt;

            return $a;
        }

        return false;
    }

    /**
     * MGU-1065/MGU-1066 - Only display activity item weights if a weighted strategy is being used.
     * However, if using a weighted strategy with 'drop the lowest' and the value is greater
     * than 0, then don't display any weights.
     * @param object $gradecategoryweight
     * @param object|null $gradecategory
     * @return bool
     */
    public static function display_activity_item_weights(
        object $gradecategoryweight,
        object|null $gradecategory = null
    ): bool {
        $displayweights = false;

        if (
            ($gradecategory->aggregation == GRADE_AGGREGATE_WEIGHTED_MEAN ||
            $gradecategory->aggregation == GRADE_AGGREGATE_WEIGHTED_MEAN2)
        ) {
            if ((int) $gradecategoryweight->grade_category_weight > 0) {
                $displayweights = true;
            }
            if ($gradecategory->droplow > 0) {
                $displayweights = false;
            }
        }

        return $displayweights;
    }

    /**
     * This method replaces the previous sorting attempt. Now that we have access
     * to the sortorder property, this makes sorting somewhat easier to do.
     * @param array $courseitems
     * @return array
     */
    public static function sort_course_items($courseitems) {
        uasort($courseitems, function ($a, $b) {
            if ((isset($a->raw_due_date)) && isset(($b->raw_due_date))) {
                if (is_int($a->raw_due_date) && is_int($b->raw_due_date)) {
                    if ($a->raw_due_date == $b->raw_due_date) {
                        return 0;
                    }
                    return ($a->raw_due_date < $b->raw_due_date) ? -1 : 1;
                }
            }

            return strnatcmp($a->sortorder, $b->sortorder);
        });

        return $courseitems;
    }

    /**
     * Get the activity type and corresponding grade item for a given category.
     *
     * @param int $courseid
     * @param int $categoryid
     * @param string $gradecategoryfullname
     * @return array [$assessmenttype, $item]
     */
    public static function get_activitytype(int $courseid, int $categoryid, string $gradecategoryfullname): array {
        if (!$item = grade_item::fetch(['courseid' => $courseid, 'iteminstance' => $categoryid, 'itemtype' => 'category'])) {
            $item = grade_item::fetch(['courseid' => $courseid, 'iteminstance' => $categoryid, 'itemtype' => 'course']);
        }
        $assessmenttype = course::return_assessmenttype($gradecategoryfullname, $item->aggregationcoef);

        return [$assessmenttype, $item]; 
    }

    /**
     * Factory method to get the correct class based on the assignment type.
     *
     * @param int $gradeitemid
     * @param int $courseid
     * @param int $groupid
     * @return object
     */
    public static function activity_factory(int $gradeitemid, int $courseid, int $groupid = 0): object {
        global $DB;

        $item = $DB->get_record('grade_items', ['id' => $gradeitemid], '*', MUST_EXIST);
        $module = $item->itemmodule;
        $classname = '\\block_newgu_spdetails\\activities\\' . $module . '_activity';
        if (class_exists($classname)) {
            return new $classname($gradeitemid, $courseid, $groupid);
        } else {
            return new default_activity($gradeitemid, $courseid, $groupid);
        }
    }
}
