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

namespace qtype_drawing\grading;

defined('MOODLE_INTERNAL') || die();

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
/**
 * Handles grade submission for drawing questions using question_engine.
 *
 * @package    qtype_drawing
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_processor {

    /**
     * Process and save a manual grade for a drawing question.
     *
     * @param int $qubaid The question usage ID.
     * @param int $slot The question slot number.
     * @param float $mark The mark to assign.
     * @param string $comment The feedback comment.
     * @param int $commentformat The format of the comment (FORMAT_HTML, etc.).
     * @return bool True on success.
     * @throws \moodle_exception If validation fails.
     */
    public static function process_grade(int $qubaid, int $slot, float $mark, string $comment, int $commentformat): bool {
        global $DB;

        // Load the question usage and attempt.
        $quba = \question_engine::load_questions_usage_by_activity($qubaid);
        $qa = $quba->get_question_attempt($slot);

        // Validate mark is in range.
        $maxmark = $qa->get_max_mark();
        $minfraction = $qa->get_min_fraction();
        $maxfraction = $qa->get_max_fraction();

        $minmark = $minfraction * $maxmark;
        $maxmarkallowed = $maxfraction * $maxmark;

        if ($mark < $minmark || $mark > $maxmarkallowed) {
            throw new \moodle_exception('invalidmark', 'qtype_drawing', '', null,
                "Mark must be between $minmark and $maxmarkallowed");
        }

        // Use the QUBA's manual_grade method - this is the proper API for manual grading.
        $quba->manual_grade($slot, $comment, $mark, $commentformat);

        // Save the updated question usage.
        \question_engine::save_questions_usage_by_activity($quba);

        // Update the quiz attempt grades.
        self::update_quiz_grades($qubaid);

        return true;
    }

    /**
     * Save annotation for a drawing question.
     *
     * @param int $qubaid The question usage ID.
     * @param int $slot The question slot number.
     * @param int $quizattemptid The quiz attempt ID.
     * @param string $annotation The SVG annotation content.
     * @return bool True on success.
     */
    public static function save_annotation(int $qubaid, int $slot, int $quizattemptid, string $annotation): bool {
        global $DB, $USER;

        // Clean the annotation - remove any script tags.
        $annotation = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $annotation);
        if (trim($annotation) === '') {
            return false;
        }

        // Get the quiz attempt to find student ID and attempt count.
        $quizattempt = $DB->get_record('quiz_attempts', ['id' => $quizattemptid], '*', MUST_EXIST);

        // Load the question attempt to get the question ID and the unique attempt ID.
        $quba = \question_engine::load_questions_usage_by_activity($qubaid);
        $qa = $quba->get_question_attempt($slot);
        $questionid = $qa->get_question_id();

        // Get the unique attempt ID that the drawing plugin uses.
        // This is stored as 'uniqueuattemptid' in the question attempt data.
        $uniqueattemptid = $qa->get_last_qt_var('uniqueuattemptid');
        if (empty($uniqueattemptid)) {
            // Fallback: generate from the current answer hash (matches renderer.php logic).
            $currentanswer = $qa->get_last_qt_var('answer');
            if (!empty($currentanswer)) {
                $uniqueattemptid = substr(md5($currentanswer), 0, 14) . 'XX';
            } else {
                // Cannot determine unique attempt ID.
                return false;
            }
        }

        // Build the annotation record.
        // The 'attemptid' field stores the unique string identifier, not the quiz attempt ID.
        $fields = [
            'questionid' => $questionid,
            'annotatedby' => $USER->id,
            'annotatedfor' => $quizattempt->userid,
            'attemptid' => $uniqueattemptid,
            'attemptcount' => $quizattempt->attempt,
        ];

        $now = time();
        if ($existing = $DB->get_record('qtype_drawing_annotations', $fields)) {
            // Update existing annotation.
            $existing->annotation = $annotation;
            $existing->timemodified = $now;
            $DB->update_record('qtype_drawing_annotations', $existing);
        } else {
            // Create new annotation.
            $record = (object) $fields;
            $record->annotation = $annotation;
            $record->timecreated = $now;
            $record->timemodified = $now;
            $record->notes = '';
            $DB->insert_record('qtype_drawing_annotations', $record);
        }

        return true;
    }

    /**
     * Save annotation directly with explicit parameters.
     * This is used by the AJAX endpoint (saveannotation.php).
     *
     * @param int $questionid The question ID.
     * @param int $studentid The student user ID (annotatedfor).
     * @param string $attemptid The unique attempt identifier string.
     * @param int $attemptcount The attempt number.
     * @param string $annotation The SVG annotation content.
     * @return bool True on success.
     */
    public static function save_annotation_direct(
        int $questionid,
        int $studentid,
        string $attemptid,
        int $attemptcount,
        string $annotation
    ): bool {
        global $DB, $USER;

        // Clean the annotation - remove any script tags.
        $annotation = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $annotation);
        if (trim($annotation) === '') {
            return false;
        }

        // Build the annotation record.
        $fields = [
            'questionid' => $questionid,
            'annotatedby' => $USER->id,
            'annotatedfor' => $studentid,
            'attemptid' => $attemptid,
            'attemptcount' => $attemptcount,
        ];

        $now = time();
        if ($existing = $DB->get_record('qtype_drawing_annotations', $fields)) {
            // Update existing annotation.
            $existing->annotation = $annotation;
            $existing->timemodified = $now;
            $DB->update_record('qtype_drawing_annotations', $existing);
        } else {
            // Create new annotation.
            $record = (object) $fields;
            $record->annotation = $annotation;
            $record->timecreated = $now;
            $record->timemodified = $now;
            $record->notes = '';
            $DB->insert_record('qtype_drawing_annotations', $record);
        }

        return true;
    }

    /**
     * Update the quiz attempt grades after a question has been manually graded.
     *
     * @param int $qubaid The question usage ID.
     */
    private static function update_quiz_grades(int $qubaid): void {
        global $DB;

        // Find the quiz attempt that uses this question usage.
        $attempt = $DB->get_record('quiz_attempts', ['uniqueid' => $qubaid]);
        if (!$attempt) {
            return;
        }

        // Update the sumgrades on the attempt record.
        $quba = \question_engine::load_questions_usage_by_activity($qubaid);
        $attempt->sumgrades = $quba->get_total_mark();
        $attempt->timemodified = time();
        $DB->update_record('quiz_attempts', $attempt);

        // Recalculate the overall quiz grade for this user using the new API.
        $quizobj = quiz_settings::create($attempt->quiz);
        $quizobj->get_grade_calculator()->recompute_final_grade($attempt->userid);
    }
}
