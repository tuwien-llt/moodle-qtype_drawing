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
 * AJAX endpoint for saving drawing question annotations.
 *
 * @package    qtype_drawing
 * @copyright  ETHZ LET <amr.hourani@id.ethz.ch>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use qtype_drawing\grading\grade_processor;

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/question/type/questiontypebase.php');
require_login();

$id = required_param('id', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_RAW);
$stid = required_param('stid', PARAM_INT);
$attemptid = required_param('attemptid', PARAM_RAW_TRIMMED);
$annotation = required_param('annotation', PARAM_RAW);
$attemptcount = optional_param('attemptcount', 1, PARAM_INT);

if (!confirm_sesskey()) {
    echo json_encode(['result' => 'Session lost.']);
    die();
}

// Load and validate the question.
if (!$question = question_bank::load_question_data($id)) {
    echo json_encode(['result' => 'Question attempt not found']);
    die();
}

if (!has_capability('mod/quiz:grade', context::instance_by_id($question->contextid))) {
    echo json_encode(['result' => 'No permission']);
    die();
}

// Verify the question exists in qtype_drawing table.
if (!$DB->record_exists('qtype_drawing', ['questionid' => $id])) {
    echo json_encode(['result' => 'Question not found']);
    die();
}

// Validate annotation is not empty after cleaning.
$cleanedannotation = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $annotation);
if (trim($cleanedannotation) === '') {
    echo json_encode(['result' => 'No annotation submitted']);
    die();
}

// Save the annotation using grade_processor.
$success = grade_processor::save_annotation_direct($id, $stid, $attemptid, $attemptcount, $annotation);

if ($success) {
    echo json_encode('OK');
} else {
    echo json_encode(['result' => 'Failed to save annotation']);
}
