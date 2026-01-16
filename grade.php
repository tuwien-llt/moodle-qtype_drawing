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
 * Drawing question grader - custom grading interface.
 *
 * @package    qtype_drawing
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Only include config.php if not already loaded (for standalone access).
// When included from quiz report, config.php is already loaded.
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/mod/quiz/report/reportlib.php');

use mod_quiz\quiz_attempt;
use qtype_drawing\grading\attempts_loader;
use qtype_drawing\grading\grade_processor;

// Parameters.
$id = required_param('id', PARAM_INT);
$slot = optional_param('slot', null, PARAM_INT);
$attemptid = optional_param('attemptid', null, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// Load course module and quiz.
$context = context_module::instance($cm->id);

// Security checks.
// require_login($course, false, $cm);
require_capability('mod/quiz:grade', $context);

// Process grade submission.
if ($action === 'savegrade' && confirm_sesskey()) {
    $qubaid = required_param('qubaid', PARAM_INT);
    $gradeslot = required_param('slot', PARAM_INT);
    $mark = required_param('mark', PARAM_FLOAT);
    $comment = optional_param('comment', '', PARAM_RAW);
    $commentformat = optional_param('commentformat', FORMAT_HTML, PARAM_INT);
    $annotation = optional_param('annotation', '', PARAM_RAW);
    $gradeattemptid = required_param('attemptid', PARAM_INT);

    // Save the grade.
    grade_processor::process_grade($qubaid, $gradeslot, $mark, $comment, $commentformat);

    // Save annotation if provided.
    if (!empty($annotation)) {
        grade_processor::save_annotation($qubaid, $gradeslot, $gradeattemptid, $annotation);
    }

    // Redirect based on whether there's a next attempt.
    $savenext = optional_param('savenext', 0, PARAM_INT);
    $next = optional_param('next', null, PARAM_INT);
    if ($savenext && $next) {
        redirect(new moodle_url('/mod/quiz/report.php', [
            'id' => $id,
            'mode' => 'drawing',
            'slot' => $gradeslot,
            'attemptid' => $next,
        ]));
    } else {
        redirect(new moodle_url('/mod/quiz/report.php', [
            'id' => $id,
            'mode' => 'drawing',
        ]), get_string('gradesaved', 'qtype_drawing'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Setup page.
$baseurl = new moodle_url('/mod/quiz/report.php', [
    'id' => $id,
    'mode' => 'drawing',
]);

if ($attemptid === null) {
    // OVERVIEW MODE: Show list of all drawing question attempts.
    $PAGE->set_url($baseurl);
    $PAGE->set_title(get_string('gradequestion', 'qtype_drawing') . ': ' . format_string($quiz->name));

    // Load all drawing question attempts for this quiz.
    $attempts = attempts_loader::get_all_drawing_attempts($quiz->id, $cm->id);
    $stats = attempts_loader::get_grading_stats($quiz->id, $cm->id);

    // Prepare template context.
    $templatecontext = [
        'quizname' => format_string($quiz->name),
        'attempts' => array_values($attempts),
        'hasattempts' => !empty($attempts),
        'totalcount' => $stats->total,
        'needsgradingcount' => $stats->needsgrading,
        'gradedcount' => $stats->graded,
        'backurl' => (new moodle_url('/mod/quiz/report.php', ['id' => $id, 'mode' => 'grading']))->out(false),
    ];

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('qtype_drawing/grader_overview', $templatecontext);
} else {
    // FULLSCREEN GRADER MODE: Grade a specific attempt.
    // Slot is required for grading a specific attempt.
    if ($slot === null) {
        throw new moodle_exception('invalidslot', 'qtype_drawing');
    }

    $PAGE->set_url(new moodle_url($baseurl, ['slot' => $slot, 'attemptid' => $attemptid]));
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('embedded');
    $PAGE->activityheader->disable();
    $PAGE->set_title(get_string('gradequestion', 'qtype_drawing'));

    // Load the quiz attempt.
    $attemptobj = quiz_attempt::create($attemptid);
    if ($attemptobj->get_quizid() != $quiz->id) {
        throw new moodle_exception('invalidattempt', 'qtype_drawing');
    }

    // Get the question usage ID and load it directly via question_engine.
    // We use the quiz_attempt's qubaid and load the QUBA ourselves rather than
    // calling get_question_usage() which is restricted to unit tests.
    $qubaid = $attemptobj->get_uniqueid();
    $quba = question_engine::load_questions_usage_by_activity($qubaid);
    $qa = $quba->get_question_attempt($slot);
    // Get student info.
    $student = $DB->get_record('user', ['id' => $attemptobj->get_userid()], '*', MUST_EXIST);

    // Preload step users for response history display.
    $attemptobj->preload_all_attempt_step_users();

    // Render the question drawing area using quiz_attempt API.
    // render_question($slot, $reviewing, $renderer) - we pass true for reviewing mode.
    $quizrenderer = $PAGE->get_renderer('mod_quiz');
    $drawingareahtml = $attemptobj->render_question($slot, true, $quizrenderer);

    // Get current mark and comment.
    $currentmark = $qa->get_mark();
    $maxmark = $qa->get_max_mark();

    // Get the current manual comment (returns array [comment, format] or [null, null]).
    [$currentcomment, $currentcommentformat] = $qa->get_current_manual_comment();
    if ($currentcomment === null) {
        $currentcomment = '';
        $currentcommentformat = FORMAT_HTML;
    }

    // Navigation - get prev/next attempts.
    $navigation = attempts_loader::get_attempt_navigation($quiz->id, $slot, $attemptid, $cm->id);

    // Prepare template context.
    $templatecontext = [
        'drawing_area_html' => $drawingareahtml,
        'studentname' => fullname($student),
        'userpicture' => $OUTPUT->user_picture($student, ['size' => 50]),
        'slot' => $slot,
        'attemptid' => $attemptid,
        'qubaid' => $qubaid,
        'currentmark' => $currentmark !== null ? format_float($currentmark, 2) : '',
        'maxmark' => format_float($maxmark, 2),
        'comment' => $currentcomment,
        'commentformat' => $currentcommentformat,
        'sesskey' => sesskey(),
        'id' => $id,
        'hasnext' => $navigation->next !== null,
        'hasprev' => $navigation->prev !== null,
        'nextid' => $navigation->next,
        'previd' => $navigation->prev,
        'currentindex' => $navigation->current_index,
        'totalcount' => $navigation->total,
        'prev_url' => $navigation->prev !== null
            ? (new moodle_url($baseurl, ['slot' => $slot, 'attemptid' => $navigation->prev]))->out(false)
            : '',
        'next_url' => $navigation->next !== null
            ? (new moodle_url($baseurl, ['slot' => $slot, 'attemptid' => $navigation->next]))->out(false)
            : '',
        'overview_url' => $baseurl->out(false),
        'saveurl' => (new moodle_url($baseurl, ['action' => 'savegrade']))->out(false),
    ];

    // Load JS module with minimal config (maxmark only - rest is in DOM).
    $PAGE->requires->js_call_amd('qtype_drawing/grader_ui', 'init', [['maxmark' => $maxmark]]);

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('qtype_drawing/grader_fullscreen', $templatecontext);
}
