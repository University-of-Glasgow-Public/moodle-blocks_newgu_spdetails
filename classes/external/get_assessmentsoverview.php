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
 * Moodle Web Service to return assessment statistics for the user.
 *
 * @package    block_newgu_spdetails
 * @author     Greg Pedder <greg.pedder@glasgow.ac.uk>
 * @copyright  2023 University of Glasgow
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_newgu_spdetails\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * This class provides the web service description for returning the assessments overview.
 */
class get_assessmentsoverview extends external_api {

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            // No params needed at this time.
        ]);
    }

    /**
     * Return the assessments overview statistics
     *
     * @return array of assessment statistics
     */
    public static function execute(): array {

        $assessmentsoverview = \block_newgu_spdetails\api::get_assessmentsoverview();
        $totalupcoming = $assessmentsoverview['total_upcoming'];
        $totaloverdue = $assessmentsoverview['total_overdue'];
        $totalsubmissions = $assessmentsoverview['total_submissions'];
        $marked = $assessmentsoverview['marked'];

        $stats[] = [
            'upcoming' => $totalupcoming,
            'overdue' => $totaloverdue,
            'sub_assess' => $totalsubmissions,
            'assess_marked' => $marked,
        ];

        return $stats;
    }

    /**
     * Describes what will be returned to the caller.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'upcoming' => new external_value(PARAM_INT, 'upcoming assignments'),
                'overdue' => new external_value(PARAM_INT, 'assignments overdue'),
                'sub_assess' => new external_value(PARAM_INT, 'total submissions'),
                'assess_marked' => new external_value(PARAM_INT, 'assessments marked'),
            ])
        );
    }
}
