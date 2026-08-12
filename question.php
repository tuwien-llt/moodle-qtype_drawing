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
 * drawing question type definition class.
 *
 * @package    qtype
 * @subpackage drawing
 * @copyright  ETHZ LET <amr.hourani@id.ethz.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once(dirname(__FILE__) . '/renderer.php');


/**
 * Represents a drawing question.
 *
 * @copyright  ETHZ LET <amr.hourani@let.ethz.ch>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_drawing_question extends question_graded_by_strategy implements question_response_answer_comparer {
    /** @var string response format */
    public string $responseformat;
    /** @var int backgrounduploaded */
    public $backgrounduploaded;
    /** @var float canvas width in pixels */
    public $backgroundwidth;
    /** @var float canvas height in pixels */
    public $backgroundheight;
    /** @var int keep the background aspect ratio */
    public $preservear;
    /** @var string serialized drawing options */
    public $drawingoptions;
    /** @var string JSON encoded colour palette configuration */
    public $colorsjson;
    /** @var int default pen size */
    public $defaultpensize;
    /** @var string highlighter colour as a hex value */
    public $colorhighlighter;
    /** @var int render the question stem inside the canvas */
    public $questionembed;
    /** @var int hide the editor menu */
    public $hidemenu;
    /** @var int show the select tool */
    public $toolselect;
    /** @var int show the drawing (pencil) tool */
    public $tooldraw;
    /** @var int show the text tool */
    public $tooltext;
    /** @var int show the highlighter tool */
    public $toolhighlighter;
    /** @var int show the line tool */
    public $toolline;
    /** @var int show the rectangle tool */
    public $toolrect;
    /** @var int show the circle tool */
    public $toolcircle;
    /** @var int show the eraser tool */
    public $tooleraser;
    /** @var int show the back/forward (undo/redo) tools */
    public $toolundoredo;

    /** @var array of question_answer. */
    public $answers = [];

    /**
     * Create a new drawing question with the first-matching-answer grading strategy.
     */
    public function __construct() {
        parent::__construct(new question_first_matching_answer_grading_strategy($this));
    }

    /**
     * Return the data expected in the response.
     *
     * @return array Expected response fields and their PARAM types.
     */
    public function get_expected_data() {
        return ['answer' => PARAM_RAW_TRIMMED, 'uniqueuattemptid' => PARAM_RAW_TRIMMED];
    }

    /**
     * Produce a plain-text summary of a response.
     *
     * @param array $response The response data.
     * @return string A textual summary of the response.
     */
    public function summarise_response(array $response) {
        return get_string('no_response_summary', 'qtype_drawing');
    }
    /**
     * Force the manually graded behaviour for this question type.
     *
     * Initially added for LMDL-294. Remove at later stage.
     *
     * @param question_attempt $qa The question attempt.
     * @param string $preferredbehaviour The requested behaviour.
     * @return question_behaviour The manualgraded behaviour.
     */
    public function make_behaviour(question_attempt $qa, $preferredbehaviour) {
        return question_engine::make_behaviour('manualgraded', $qa, $preferredbehaviour);
    }
    /**
     * Check whether the response contains a non-empty answer.
     *
     * @param array $response The response data.
     * @return bool True if the response is complete.
     */
    public function is_complete_response(array $response) {
        if (array_key_exists('answer', $response)) {
            if ($response['answer'] != '') {
                return true;
            }
        }
        return false;
    }
    /**
     * Check whether the response can be graded.
     *
     * @param array $response The response data.
     * @return bool True if the response is gradable.
     */
    public function is_gradable_response(array $response) {
        return self::is_complete_response($response);
    }
    /**
     * Return a validation error message for an incomplete response.
     *
     * @param array $response The response data.
     * @return string The error message, or an empty string if the response is gradable.
     */
    public function get_validation_error(array $response) {
        if ($this->is_gradable_response($response)) {
            return '';
        }
        return get_string('pleaseenterananswer', 'qtype_drawing');
    }

    /**
     * Check whether two responses are the same.
     *
     * @param array $prevresponse The previous response data.
     * @param array $newresponse The new response data.
     * @return bool True if both responses are equivalent.
     */
    public function is_same_response(array $prevresponse, array $newresponse) {
        return question_utils::arrays_same_at_key_missing_is_blank(
            $prevresponse,
            $newresponse,
            'answer'
        );
    }

    /**
     * Return the answers defined for this question.
     *
     * @return array Array of question_answer objects.
     */
    public function get_answers() {
        return $this->answers;
    }

    /**
     * Return the correct response, which does not exist for drawing questions.
     *
     * @return null Always null, as there is no automatic correct response.
     */
    public function get_correct_response() {
        return null;
    }
    /**
     * Produce a plain-text summary of the correct answer.
     *
     * @return string A textual summary explaining there is no correct answer.
     */
    public function get_right_answer_summary() {
        return get_string('no_correct_answer_summary', 'qtype_drawing');
    }
    /**
     * Compare a response with an answer; drawing questions are never auto-graded.
     *
     * @param array $response The response data.
     * @param question_answer $answer The answer to compare against.
     * @return bool Always false, grading is done manually.
     */
    public function compare_response_with_answer(array $response, question_answer $answer) {

        if ($answer->answer === '' || array_key_exists('answer', $response) === false) {
            return false;
        }

        $matchpercentage = qtype_drawing_renderer::compare_drawings($answer->answer, $response['answer']);
        $answer->fraction = 0;
        return false;
    }

    /**
     * Check whether a user may access a file belonging to this question.
     *
     * @param question_attempt $qa The question attempt being displayed.
     * @param question_display_options $options The display options.
     * @param string $component The component of the requested file.
     * @param string $filearea The file area of the requested file.
     * @param array $args Remaining file path arguments; the first entry is the item ID.
     * @param bool $forcedownload Whether the file is being downloaded.
     * @return bool True if access is allowed.
     */
    public function check_file_access(
        $qa,
        $options,
        $component,
        $filearea,
        $args,
        $forcedownload
    ) {
        if ($component == 'qtype_drawing' && $filearea == 'qtype_drawing_image_file') {
            $question = $qa->get_question();
            $itemid = reset($args);
            return ($itemid == $question->id);
        } else {
            return parent::check_file_access(
                $qa,
                $options,
                $component,
                $filearea,
                $args,
                $forcedownload
            );
        }
    }
}
