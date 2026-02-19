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
 * Web Service to return the assessments due soon to the PoC UofG Life app for a given student.
 *
 * MOOD-415 requests external API end points be made available to the app.
 *
 * @package    block_newgu_spdetails
 * @author     Greg Pedder <greg.pedder@glasgow.ac.uk>
 * @copyright  2026 University of Glasgow
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_newgu_spdetails\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * This class provides the web service description for returning assessments that are due in the near future.
 */
class assessmentsduesoon extends external_api {

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'guid' => new external_value(PARAM_TEXT, 'GUID'),
        ]);
    }

    /**
     * Return the assessments due in the next 24 hours, 1 week and 1 month.
     *
     * We probably want to cache this on something like a 5 minute basis,
     * given that the service gets called each time the user visits the
     * dashboard.
     *
     * @param string $guid
     * @return array of assessments, grouped by return time.
     * @throws \invalid_parameter_exception
     */
    public static function execute(string $guid): array {

        $params = self::validate_parameters(self::execute_parameters(),[
            'guid' => $guid,
        ]);

        $assessmentsduesoon  = \block_newgu_spdetails\course::assessmentsduesoon($params['guid']);

        $data['result'] = json_encode($assessmentsduesoon);

        return $data;
    }

    /**
     * Describes what will be returned to the caller.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'result' => new external_value(PARAM_TEXT, 'The assessments due soon in JSON format'),
        ]);
    }
}
