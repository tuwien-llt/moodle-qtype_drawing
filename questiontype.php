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
 * Question type class for the drawing question type.
 *
 * @package    qtype
 * @subpackage drawing
 * @copyright  ETHZ LET <amr.hourani@id.ethz.ch>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->dirroot . '/question/type/drawing/question.php');
require_once(dirname(__FILE__) . '/renderer.php');
require_once(dirname(__FILE__) . '/lib.php');

/**
 * The drawing question type.
 *
 * @copyright  ETHZ LET <amr.hourani@id.ethz.chh>
 * @license    http://opensource.org/licenses/BSD-3-Clause
 */
class qtype_drawing extends question_type {
    /**
     * Question is manually graded.
     */
    public function is_manual_graded() {
        return true;
    }

    /**
     * Return the table name and column names of the extra question fields.
     *
     * @return array The options table name followed by its column names.
     */
    public function extra_question_fields() {
        return [
            'qtype_drawing',
            'backgrounduploaded',
            'backgroundwidth',
            'backgroundheight',
            'preservear',
            'drawingoptions',
            'colorsjson',
            'defaultpensize',
            'colorhighlighter',
            'questionembed',
            'hidemenu',
            'toolselect',
            'tooldraw',
            'tooltext',
            'toolhighlighter',
            'toolline',
            'toolrect',
            'toolcircle',
            'tooleraser',
            'toolundoredo',
        ];
    }

    /**
     * Return the name of the column holding the question ID in the options table.
     *
     * @return string The column name.
     */
    public function questionid_column_name() {
        return 'questionid';
    }

    /**
     * Moves all files associated with the given question to a new context.
     *
     * @param int $questionid The question ID.
     * @param int $oldcontextid The old context ID.
     * @param int $newcontextid The new context ID.
     * @return void
     */
    public function move_files($questionid, $oldcontextid, $newcontextid) {
        parent::move_files($questionid, $oldcontextid, $newcontextid);
        $fs = get_file_storage();
        $fs->move_area_files_to_new_context($oldcontextid, $newcontextid, 'qtype_drawing', 'qtype_drawing_image_file', $questionid);
    }

    /**
     * Deletes all files associated with the given question.
     *
     * @param int $questionid The question ID.
     * @param int $contextid The context ID.
     * @return void
     */
    protected function delete_files($questionid, $contextid) {
        parent::delete_files($questionid, $contextid);
        $this->delete_files_in_answers($questionid, $contextid, true);
        $this->delete_files_in_hints($questionid, $contextid);
    }

    /**
     * Custom method for deleting drawing questions.
     *
     * @param int $questionid The question ID.
     * @param int $contextid The context ID.
     */
    public function delete_question($questionid, $contextid) {
        global $DB;
        $originalrecord = $DB->get_record('qtype_drawing', ['questionid' => $questionid]);
        $DB->delete_records('qtype_drawing', ['questionid' => $questionid]);
        $DB->delete_records('qtype_drawing_annotations', ['questionid' => $originalrecord->id]);
        parent::delete_question($questionid, $contextid);
    }

    /**
     * Saves the question options.
     *
     * @param question_definition $question The question definition.
     * @return void
     */
    public function save_question_options($question) {
        global $DB, $USER;
        $context = $question->context;
        $drawingconfig = get_config('qtype_drawing');
        $result = new stdClass();
        // Insert all the new options.
        $options = $DB->get_record('qtype_drawing', ['questionid' => $question->id]);

        if (!$options) {
            $options = new stdClass();
            $options->questionid = $question->id;
            $options->allowstudentimage = 0;
            $options->backgrounduploaded = 0;
            $options->backgroundwidth = $drawingconfig->defaultcanvaswidth;
            $options->backgroundheight = $drawingconfig->defaultcanvasheight;
            $options->preservear = 1;
            $options->drawingoptions = '';
            $options->questionembed = $drawingconfig->questionembed;
            $options->defaultpensize = $drawingconfig->defaultpensize;
            $options->colorsjson = $drawingconfig->colorsjson;
            $options->colorhighlighter = $drawingconfig->colorhighlighter;
            $options->hidemenu = 0;
            foreach (qtype_drawing_tool_names() as $tool) {
                $options->$tool = isset($drawingconfig->$tool) ? $drawingconfig->$tool : 1;
            }
            $options->id = $DB->insert_record('qtype_drawing', $options);
        }
        if (isset($question->allowstudentimage)) {
            $options->allowstudentimage = $question->allowstudentimage;
        }
        $options->backgrounduploaded = $question->backgrounduploaded;
        $options->backgroundwidth = $question->backgroundwidth;
        $options->backgroundheight = $question->backgroundheight;
        if (!isset($question->preservear)) {
            $question->preservear = 0;
        }
        $options->preservear = $question->preservear;
        $options->questionembed = isset($question->questionembed) ? $question->questionembed : $drawingconfig->questionembed;
        $options->defaultpensize = isset($question->defaultpensize) ? $question->defaultpensize : $drawingconfig->defaultpensize;
        $options->colorsjson = isset($question->colorsjson) ? $question->colorsjson : $drawingconfig->colorsjson;
        $options->colorhighlighter = isset($question->colorhighlighter)
            ? $question->colorhighlighter : $drawingconfig->colorhighlighter;
        $options->hidemenu = !empty($question->hidemenu) ? 1 : 0;
        foreach (qtype_drawing_tool_names() as $tool) {
            if (isset($question->$tool)) {
                $options->$tool = $question->$tool ? 1 : 0;
            } else if (!isset($options->$tool)) {
                $options->$tool = isset($drawingconfig->$tool) ? $drawingconfig->$tool : 1;
            }
        }

        $DB->update_record('qtype_drawing', $options);
        $this->save_hints($question);

        // Save the background image.
        if (isset($question->qtype_drawing_image_file)) {
            $fs = get_file_storage();
            $usercontext = context_user::instance($USER->id);
            $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $question->qtype_drawing_image_file, 'id');
            if (count($draftfiles) >= 2) {
                $fs->delete_area_files($question->context->id, 'qtype_drawing', 'qtype_drawing_image_file', $question->id);
                file_save_draft_area_files(
                    $question->qtype_drawing_image_file,
                    $question->context->id,
                    'qtype_drawing',
                    'qtype_drawing_image_file',
                    $question->id,
                    ['subdirs' => 0, 'maxbytes' => 0, 'maxfiles' => 1]
                );
            } else {
                // No files have been indicated to be uploaded.
                // Check if this is an attempt to make a duplicate copy of this question.
                // And that this is not a simple EDIT, in which case we don't have to do anything to the background image file.
                if (
                    property_exists($question, 'pre_existing_question_id') &&
                    $question->pre_existing_question_id != 0 && $question->pre_existing_question_id != $question->id
                ) {
                    // This was an edit form which turned out to be a "Make copy".
                    // We need to copy over the background image of the old question into a new record.
                    // First fetch the old one.
                    $oldfiles   = $fs->get_area_files(
                        $question->context->id,
                        'qtype_drawing',
                        'qtype_drawing_image_file',
                        $question->pre_existing_question_id,
                        'id'
                    );

                    if (count($oldfiles) >= 2) {
                        // Files indeed exist.
                        foreach ($oldfiles as $oldfile) {
                            if ($oldfile->is_directory()) {
                                continue;
                            }
                            $newfile = [
                              'contextid' => $question->context->id,
                              'component' => 'qtype_drawing',
                              'filearea' => 'qtype_drawing_image_file',
                              'itemid' => $question->id,
                              'filepath' => '/',
                              'filename' => $oldfile->get_filename()];
                            $fs->create_file_from_storedfile($newfile, $oldfile);
                            continue;
                        }
                    }
                } else {
                    // Background image has been delibrately removed by teacher.
                    // Question updated but background was not touched?. If not, delete the bg.
                    if (!isset($question->pre_existing_question_id) || $question->pre_existing_question_id == 0) {
                        $fs = get_file_storage();
                        $fs->delete_area_files($question->context->id, 'qtype_drawing', 'qtype_drawing_image_file', $question->id);
                    }
                }
            }
        }
    }


    /**
     * Initialise a question definition instance from question data.
     *
     * @param question_definition $question The question definition to initialise.
     * @param object $questiondata The question data loaded from the database.
     * @return void
     */
    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);
        $this->initialise_question_answers($question, $questiondata);
    }

    /**
     * Return the score a student would get by guessing randomly.
     *
     * @param object $questiondata The question data.
     * @return int Always 0 for drawing questions.
     */
    public function get_random_guess_score($questiondata) {
        return 0;
    }

    /**
     * Return the possible responses for reporting purposes.
     *
     * @param object $questiondata The question data.
     * @return array Possible responses indexed by question ID.
     */
    public function get_possible_responses($questiondata) {
        $responses = [];
        $starfound = false;

        foreach ($questiondata->options->answers as $aid => $answer) {
            $responses[$aid] = new question_possible_response($answer->answer, $answer->fraction);

            if ($answer->answer === '*') {
                $starfound = true;
            }
        }
        if (!$starfound) {
            $responses[0] = new question_possible_response(
                get_string('didnotmatchanyanswer', 'question'),
                0
            );
        }

        $responses[null] = question_possible_response::no_response();

        return [$questiondata->id => $responses];
    }

    /**
     * Export a drawing question to Moodle XML format.
     *
     * @param object $question The question to export.
     * @param qformat_xml $format The XML format instance.
     * @param mixed $extra Extra data (unused).
     * @return string|false The question XML, or false if the question cannot be exported.
     */
    public function export_to_xml($question, qformat_xml $format, $extra = null) {
        $extraquestionfields = $this->extra_question_fields();
        if (!is_array($extraquestionfields)) {
            return false;
        }
        // Omit table name (question).
        array_shift($extraquestionfields);
        $expout = '';

        foreach ($extraquestionfields as $field) {
            $exportedvalue = $question->options->$field;
            if (!empty($exportedvalue) && htmlspecialchars($exportedvalue) != $exportedvalue) {
                $exportedvalue = "<![CDATA[" . $exportedvalue . "]]>";
            }
            $expout .= "    <$field>{$exportedvalue}</$field>\n";
        }

        $expout .= "    <bgimage>\n";
        $bgimagearray = qtype_drawing_renderer::get_image_for_question($question);
        if ($bgimagearray === null || !isset($bgimagearray)) {
            $bgimagearray = [null, null, null];
        }

        $expout .= "        <filename>" . $bgimagearray[2] .  "</filename>\n";
        $expout .= "        <dataURL><![CDATA[" . $bgimagearray[1] . "]]></dataURL>\n";
        $expout .= "        <imagetype>" . $bgimagearray[0] .  "</imagetype>\n";
        $expout .= "    </bgimage>\n";

        foreach ($question->options->answers as $answer) {
            $percent = 100 * $answer->fraction;
            $expout .= "    <answer fraction=\"$percent\">\n";
            $expout .= $format->writetext($answer->answer, 3, false);
            $expout .= "      <feedback format=\"html\">\n";
            $expout .= $format->writetext($answer->feedback, 4, false);
            $expout .= "      </feedback>\n";
            $expout .= "    </answer>\n";
        }
        return $expout;
    }
    /**
     * Import a drawing question from Moodle XML format.
     *
     * @param array $data The parsed XML data.
     * @param object $question The question object to fill (unused, a new one is created).
     * @param qformat_xml $format The XML format instance.
     * @param mixed $extra Extra data (unused).
     * @return object|false The imported question, or false if the data is not a drawing question.
     */
    public function import_from_xml($data, $question, qformat_xml $format, $extra = null) {
        if (!isset($data['@']['type']) || $data['@']['type'] != 'drawing') {
            return false;
        }

        $question = $format->import_headers($data);
        $question->qtype = 'drawing';

        $question->shuffleanswers = array_key_exists('shuffleanswers', $format->getpath($data, ['#'], []));

        $format->import_combined_feedback($question, $data, true);
        $format->import_hints($question, $data, true, false, $format->get_format($question->questiontextformat));

        // Save Extra Fields.
        $extraquestionfields = $this->extra_question_fields();
        if (!is_array($extraquestionfields)) {
            return false;
        }
        // Remove table name from array of extra fields (question name).
        array_shift($extraquestionfields);
        $question->options = new stdClass();
        foreach ($extraquestionfields as $field) {
            $question->$field = $format->getpath($data, ['#', $field, '0', '#'], 'does_not_exist');
            if ($question->$field === 'does_not_exist') {
                return false;
            }
        }
        // Save canvas background image file.
        $bgimagearray[0] = $format->getpath($data, ['#', 'bgimage', '0', '#', 'imagetype', '0', '#'], 'does_not_exist');
        $bgimagearray[1] = $format->getpath($data, ['#', 'bgimage', '0', '#', 'dataURL', '0', '#'], 'does_not_exist');
        $bgimagearray[2] = $format->getpath($data, ['#', 'bgimage', '0', '#', 'filename', '0', '#'], 'does_not_exist');

        if ($bgimagearray[1] === 'does_not_exist' || $bgimagearray[2] === 'does_not_exist') {
            return false;
        }
        if (trim($bgimagearray[1]) != '') {
            if ($bgimagearray[0] != 'svg') {
                  // Convert dataURL to binary.
                $imgbinarydata = base64_decode(qtype_drawing_renderer::strstr_after($bgimagearray[1], 'base64,'));
                // Make sure this is a valid image file we could read.
                if (($gdimg = imagecreatefromstring($imgbinarydata)) === false) {
                    return false;
                }
                // Clean up GD resource.
                imagedestroy($gdimg);
            } else {
                $imgbinarydata = $bgimagearray[1];
            }
               // Prepare draft file area which would later be really saved in.
               // ::save_question_options() when the question object already exists.
               global $USER;
               $fs = get_file_storage();
               $usercontext = context_user::instance($USER->id);
               $question->qtype_drawing_image_file = file_get_unused_draft_itemid();
               $record = new stdClass();
               $record->contextid = $usercontext->id;
               $record->component = 'user';
               $record->filearea  = 'draft';
               $record->itemid    = $question->qtype_drawing_image_file;
               $record->filename  = $bgimagearray[2];
               $record->filepath  = '/';
               $fs->create_file_from_string($record, $imgbinarydata);
        }
        return $question;
    }

    /**
     * Returns a list of colors for the template, filtered by role availability
     * and sorted with the default color first.
     *
     * @param bool $teacherview True for Trainer/Teacher view, False for Student view.
     * @param string $colorsjson The JSON string from the configuration field.
     * @return array List of color objects (e.g., [['hex' => '#ff0000', 'selected' => true], ...])
     */
    public static function get_colors_for_template($teacherview, $colorsjson) {
        // 1. Decode the JSON configuration. Old questions (created before the 2025120100
        // upgrade, which did not backfill the new column) have an empty colorsjson; fall back
        // to the admin default palette then, and also when the stored value is invalid JSON.
        $config = null;
        foreach ([$colorsjson, get_config('qtype_drawing', 'colorsjson')] as $json) {
            if (empty($json)) {
                continue;
            }
            $decoded = json_decode($json);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded->colors)) {
                $config = $decoded;
                break;
            }
        }
        if ($config === null) {
            // Even the admin default is unusable: degrade to an empty palette, keeping the
            // tuple shape the caller destructures.
            return [[], null, false];
        }

        $showallcolorschooser = false;

        // 2. Define role-specific keys based on $teacherview
        if ($teacherview) {
            $globalavailkey = 'trainerAvailable';
            $itemavailkey = 'avail_trainer';
            $defaultkey = 'def_trainer';
        } else {
            $globalavailkey = 'studentAvailable';
            $itemavailkey = 'avail_student';
            $defaultkey = 'def_student';
        }

        // 3. Check Global Availability - now it is a palette chooser
        // If the "Color selection available for X" checkbox is unchecked, return empty list.
        if (!empty($config->globalSettings->$globalavailkey)) {
            $showallcolorschooser = true;
        }

        $filteredcolors = [];
        $defaultcolor = null;

        // 4. Iterate and Filter
        $colorid = 1;
        foreach ($config->colors as $color) {
            // Ensure data integrity.
            if (empty($color->hex)) {
                continue;
            }

            // Check if this specific color is available for the current role.
            // We cast to bool to ensure correct comparison.
            $isavailable = !empty($color->$itemavailkey);
            $isdefault = !empty($color->$defaultkey);

            // Logic: Include the color if it is explicitly available OR if it is marked as the default.
            // (A default color should strictly be available, but this prevents errors if a user misconfigures it.)
            if ($isavailable || $isdefault) {
                $colorobj = [
                    'hex' => $color->hex,
                    'selected' => $isdefault,
                    'colorid' => $colorid++,
                ];

                if ($isdefault) {
                    $defaultcolor = $colorobj;
                } else {
                    $filteredcolors[] = $colorobj;
                }
            }
        }

        // 5. Sort: Place the default color at the very beginning of the array
        if ($defaultcolor) {
            array_unshift($filteredcolors, $defaultcolor);
        }

        return [$filteredcolors, $defaultcolor, $showallcolorschooser];
    }
}
