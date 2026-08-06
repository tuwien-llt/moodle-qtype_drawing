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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Defines the editing form for the drawing question type.
 *
 * @package qtype
 * @subpackage drawing
 * @copyright ETH Zurich LET <amr.hourani@id.ethz.ch>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once(dirname(__FILE__) . '/renderer.php');
require_once(dirname(__FILE__) . '/lib.php');

/**
 * Editing form for the drawing question type.
 *
 * @copyright  ETHZ LET <amr.hourani@id.ethz.ch>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_drawing_edit_form extends question_edit_form {
    /**
     * (non-PHPdoc).
     *
     * @see myquestion_edit_form::qtype()
     */
    public function qtype() {
        return 'drawing';
    }

    /**
     * Build the form definition.
     * This adds all the form fields that the default question type supports.
     * If your question type does not support all these fields, then you can
     * override this method and remove the ones you don't want with $mform->removeElement().
     */
    protected function definition() {
        global $DB, $PAGE;

        $mform = $this->_form;

        // Standard fields at the start of the form.
        $mform->addElement('header', 'generalheader', get_string("general", 'form'));

        if (!isset($this->question->id)) {
            if (!empty($this->question->formoptions->mustbeusable)) {
                $contexts = $this->contexts->having_add_and_use();
            } else {
                $contexts = $this->contexts->having_cap('moodle/question:add');
            }

            // Adding question.
            $mform->addElement('questioncategory', 'category', get_string('category', 'question'), ['contexts' => $contexts]);
        } else if (!($this->question->formoptions->canmove || $this->question->formoptions->cansaveasnew)) {
            // Editing question with no permission to move from category.
            $mform->addElement(
                'questioncategory',
                'category',
                get_string('category', 'question'),
                ['contexts' => [$this->categorycontext]]
            );
            $mform->addElement('hidden', 'usecurrentcat', 1);
            $mform->setType('usecurrentcat', PARAM_BOOL);
            $mform->setConstant('usecurrentcat', 1);
        } else {
            // Editing question with permission to move from category or save as new q.
            $currentgrp = [];
            $currentgrp[0] = $mform->createElement(
                'questioncategory',
                'category',
                get_string('categorycurrent', 'question'),
                ['contexts' => [$this->categorycontext]]
            );
            // Validate if the question is being duplicated.
            $beingcopied = false;
            if (isset($this->question->beingcopied)) {
                $beingcopied = $this->question->beingcopied;
            }
            if (($this->question->formoptions->canedit || $this->question->formoptions->cansaveasnew) && ($beingcopied)) {
                // Not move only form.
                $currentgrp[1] = $mform->createElement(
                    'checkbox',
                    'usecurrentcat',
                    '',
                    get_string('categorycurrentuse', 'question')
                );
                $mform->setDefault('usecurrentcat', 1);
            }
            $currentgrp[0]->freeze();
            $currentgrp[0]->setPersistantFreeze(false);
            $mform->addGroup($currentgrp, 'currentgrp', get_string('categorycurrent', 'question'), null, false);

            if (($beingcopied)) {
                $mform->addElement(
                    'questioncategory',
                    'categorymoveto',
                    get_string('categorymoveto', 'question'),
                    ['contexts' => [$this->categorycontext]]
                );
                if ($this->question->formoptions->canedit || $this->question->formoptions->cansaveasnew) {
                    // Not move only form.
                    $mform->disabledIf('categorymoveto', 'usecurrentcat', 'checked');
                }
            }
        }

        if (
            class_exists('qbank_editquestion\\editquestion_helper')
                && !empty($this->question->id) && !$this->question->beingcopied
        ) {
            // Add extra information from plugins when editing a question (e.g.: Authors, version control and usage).
            $functionname = 'edit_form_display';
            $questiondata = [];
            $plugins = get_plugin_list_with_function('qbank', $functionname);
            foreach ($plugins as $componentname => $plugin) {
                $element = new StdClass();
                $element->pluginhtml = component_callback($componentname, $functionname, [$this->question]);
                $questiondata['editelements'][] = $element;
            }
            $mform->addElement(
                'static',
                'versioninfo',
                get_string('versioninfo', 'qbank_editquestion'),
                $PAGE->get_renderer('qbank_editquestion')->render_question_info($questiondata)
            );
        }

        $mform->addElement('text', 'name', get_string('tasktitle', 'qtype_drawing'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('float', 'defaultmark', get_string('maxpoints', 'qtype_drawing'), ['size' => 7]);
        $mform->setDefault('defaultmark', $this->get_default_value('defaultmark', 1));
        $mform->addRule('defaultmark', null, 'required', null, 'client');

        $mform->addElement('editor', 'questiontext', get_string('stem', 'qtype_drawing'), ['rows' => 15], $this->editoroptions);
        $mform->setType('questiontext', PARAM_RAW);
        $mform->addRule('questiontext', null, 'required', null, 'client');
        $mform->setDefault('questiontext', ['text' => get_string('enterstemhere', 'qtype_drawing')]);

        if (class_exists('qbank_editquestion\\editquestion_helper')) {
            $mform->addElement(
                'select',
                'status',
                get_string('status', 'qbank_editquestion'),
                \qbank_editquestion\editquestion_helper::get_question_status_list()
            );
        }
        $mform->addElement(
            'editor',
            'generalfeedback',
            get_string('generalfeedback', 'question'),
            ['rows' => 10],
            $this->editoroptions
        );
        $mform->setType('generalfeedback', PARAM_RAW);
        $mform->addHelpButton('generalfeedback', 'generalfeedback', 'question');

        $mform->addElement('text', 'idnumber', get_string('idnumber', 'question'), 'maxlength="100"  size="10"');
        $mform->addHelpButton('idnumber', 'idnumber', 'question');
        $mform->setType('idnumber', PARAM_RAW);
        // Any questiontype specific fields.
        $this->definition_inner($mform);
        $mform->addElement('hidden', 'backgrounduploaded');
        $mform->setType('backgrounduploaded', PARAM_INT);
        $mform->setDefault('backgrounduploaded', 0);
        if (
            core_tag_tag::is_enabled('core_question', 'question') &&
             \core\plugininfo\qbank::is_plugin_enabled('qbank_tagquestion')
        ) {
            $this->add_tag_fields($mform);
        }

        if (!empty($this->customfieldpluginenabled) && $this->customfieldpluginenabled) {
            // Add custom fields to the form.
            $this->customfieldhandler = qbank_customfields\customfield\question_handler::create();
            $this->customfieldhandler->set_parent_context($this->categorycontext); // For question handler only.
            $this->customfieldhandler->instance_form_definition($mform, empty($this->question->id) ? 0 : $this->question->id);
        }

        $this->add_hidden_fields();

        $mform->addElement('hidden', 'qtype');
        $mform->setType('qtype', PARAM_ALPHA);

        $mform->addElement('hidden', 'makecopy');
        $mform->setType('makecopy', PARAM_INT);

        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'updatebutton', get_string('savechangesandcontinueediting', 'question'));
        if ($this->can_preview()) {
            if (class_exists('qbank_editquestion\\editquestion_helper')) {
                if (\core\plugininfo\qbank::is_plugin_enabled('qbank_previewquestion')) {
                    $previewlink = $PAGE->get_renderer('qbank_previewquestion')->question_preview_link(
                        $this->question->id,
                        $this->context,
                        true
                    );
                    $buttonarray[] = $mform->createElement('static', 'previewlink', '', $previewlink);
                }
            } else {
                $previewlink = $PAGE->get_renderer('core_question')
                    ->question_preview_link($this->question->id, $this->context, true);
                $buttonarray[] = $mform->createElement('static', 'previewlink', '', $previewlink);
            }
        }

        $mform->addGroup($buttonarray, 'updatebuttonar', '', [' '], false);
        $mform->closeHeaderBefore('updatebuttonar');

        $this->add_action_buttons(true, get_string('savechanges'));

        if (
            (!empty($this->question->id))
                && (!($this->question->formoptions->canedit || $this->question->formoptions->cansaveasnew))
        ) {
            $mform->hardFreezeAllVisibleExcept(['categorymoveto', 'buttonar', 'currentgrp']);
        }
    }

    /**
     * Add the drawing-specific fields to the question editing form.
     *
     * @param MoodleQuickForm $mform The form being built.
     * @return void
     */
    protected function definition_inner($mform) {
        global $PAGE, $CFG, $USER, $COURSE;

        $usercontext = context_user::instance($USER->id);
        $bgimagearray = null;
        $canvastextareapreexistingpnswer = '';
        $nobackgroundimageselectedyetstyle = '';
        $eraserhtmlparams = '';
        $this->editoroptions['changeformat'] = 1;

        $mform->addElement('header', 'drawsetting', get_string('drawing_settings', 'qtype_drawing'));
        $mform->setExpanded('drawsetting');

        $drawingconfig = get_config('qtype_drawing');

        $mform->addElement('selectyesno', 'questionembed', get_string('questionembed', 'qtype_drawing'));
        $mform->setDefault('questionembed', $drawingconfig->questionembed);
        $mform->addHelpButton('questionembed', 'questionembed', 'qtype_drawing');

        $mform->addElement('selectyesno', 'hidemenu', get_string('hidemenu', 'qtype_drawing'));
        $mform->setDefault('hidemenu', 0);

        // Drawing tool configuration: per-question switches for which canvas tools students see.
        // One "parameter" with a checkbox per tool; appendName=false keeps flat element names so
        // they map straight onto the qtype_drawing columns / extra_question_fields().
        $toolcheckboxes = [];
        foreach (qtype_drawing_tool_names() as $tool) {
            $toolcheckboxes[] = & $mform->createElement(
                'advcheckbox',
                $tool,
                '',
                get_string('showtool_' . $tool, 'qtype_drawing'),
                [],
                [0, 1]
            );
        }
        $mform->addGroup(
            $toolcheckboxes,
            'drawingtoolconfig',
            get_string('drawingtoolconfig', 'qtype_drawing'),
            '<br>',
            false
        );
        $mform->addHelpButton('drawingtoolconfig', 'drawingtoolconfig', 'qtype_drawing');
        foreach (qtype_drawing_tool_names() as $tool) {
            $mform->setType($tool, PARAM_INT);
            $mform->setDefault($tool, isset($drawingconfig->$tool) ? $drawingconfig->$tool : 1);
        }

        $canvassizearray = [];
        $canvassizearray[] = & $mform->createElement(
            'text',
            'backgroundwidth',
            get_string('backgroundwidth', 'qtype_drawing'),
            ['size' => 4, 'maxlength' => 5, 'id' => 'qtype_drawing_backgroundwidth']
        );
        $canvassizearray[] = & $mform->createElement('static', '', '', 'px  &nbsp;  &nbsp;', 'px &nbsp; &nbsp;');
        $canvassizearray[] = & $mform->createElement(
            'text',
            'backgroundheight',
            get_string('backgroundheight', 'qtype_drawing'),
            ['size' => 4, 'maxlength' => 5, 'id' => 'qtype_drawing_backgroundheight']
        );
        $canvassizearray[] = & $mform->createElement('static', '', '', 'px  &nbsp;  &nbsp;', 'px &nbsp; &nbsp;');
        $canvassizearray[] = & $mform->createElement('checkbox', 'preservear', get_string('preserveaspectratio', 'qtype_drawing'));

        $mform->addGroup($canvassizearray, 'buttonarx', get_string('canvassize', 'qtype_drawing'), [' '], false);
        $mform->closeHeaderBefore('drawsetting');
        $mform->setType('backgroundwidth', PARAM_INT);
        $mform->setDefault('backgroundwidth', $drawingconfig->defaultcanvaswidth);
        $mform->setType('backgroundheight', PARAM_INT);
        $mform->setDefault('backgroundheight', $drawingconfig->defaultcanvasheight);
        $mform->setType('preservear', PARAM_INT);
        $mform->setDefault('preservear', 1);

        $pensizearray = [];
        for ($i = 1; $i < 100; $i++) {
            $pensizearray[$i] = strval($i) . 'px';
        }
        $mform->addElement('select', 'defaultpensize', get_string('defaultpensize', 'qtype_drawing'), $pensizearray);
        $mform->setDefault('defaultpensize', $drawingconfig->defaultpensize);
        $mform->addHelpButton('defaultpensize', 'defaultpensize', 'qtype_drawing');

        $mform->addElement('textarea', 'colorsjson', get_string('colors:config', 'qtype_drawing'));
        $mform->setType('colorsjson', PARAM_RAW);
        $mform->setDefault('colorsjson', $drawingconfig->colorsjson);
        $PAGE->requires->js_call_amd('qtype_drawing/color_config', 'init', ['id_colorsjson']);

        // Add colorhighlighter text field.
        $mform->addElement('text', 'colorhighlighter', get_string('color:highlighter', 'qtype_drawing'));
        $mform->setType('colorhighlighter', PARAM_RAW);
        $mform->setDefault('colorhighlighter', $drawingconfig->colorhighlighter);
        $mform->addHelpButton('colorhighlighter', 'color:highlighter', 'qtype_drawing');

        $mform->addElement('html', '<div style="display:none">'); // Hide until version 2.
        $mform->addElement('checkbox', 'allowstudentimage', get_string('allowstudentimage', 'qtype_drawing'), '&nbsp;');
        $mform->addHelpButton('allowstudentimage', 'allowstudentimage', 'qtype_drawing');
        $mform->setType('allowstudentimage', PARAM_INT);
        $mform->setDefault('allowstudentimage', 0);
        $mform->addElement('html', '</div>'); // Hide until version 2.

        $bgimagearray = qtype_drawing_renderer::get_image_for_files(
            $usercontext->id,
            'user',
            'draft',
            file_get_submitted_draft_itemid('qtype_drawing_image_file')
        );
        if ($bgimagearray !== null) {
            $nobackgroundimageselectedyetstyle = 'style="display: none;"';
            $mform->addElement(
                'hidden',
                'pre_existing_background_data',
                $bgimagearray[1],
                ['id' => 'pre_existing_background_data']
            );
            $mform->setType('pre_existing_background_data', PARAM_RAW);
        } else {
            if (property_exists($this->question, 'id') === true) {
                $question = $this->question;
                if (property_exists($question, 'contextid') === false || property_exists($question, 'answers') === false) {
                    $question = question_bank::load_question($question->id, false);
                }
                // Question already exists! We are in edit mode.
                // --------------------------------------------------------
                // This is in case duplicates are requested to be made.
                // So that the saving code in question.php would know there was a pre-existing question.
                $mform->addElement('hidden', 'pre_existing_question_id', $question->id);
                $mform->setType('pre_existing_question_id', PARAM_INT);
                // --------------------------------------------------------

                $bgimagearray = qtype_drawing_renderer::get_image_for_question($question);
                if ($bgimagearray === null || !isset($bgimagearray)) {
                    $bgimagearray = [null, null, null];
                }
                // This is the structure of the array:
                // 0 image dataURL string.
                // 1 width.
                // 2 height.
                // 3 filename string.
                $mform->addElement(
                    'hidden',
                    'pre_existing_background_data',
                    $bgimagearray[1],
                    ['id' => 'pre_existing_background_data']
                );
                $mform->setType('pre_existing_background_data', PARAM_RAW);

                if ($bgimagearray[0] == 'svg') {
                    $finalbackground = 'data:image/svg+xml,' . rawurlencode($bgimagearray[1]);
                } else {
                    $finalbackground = $bgimagearray[1];
                }
                $nobackgroundimageselectedyetstyle = 'style="display: none;"';

                // This will be a UI aid to make sure the user knows a file has been chosen rather than just displaying the empty
                // file picker widget
                // which doesn't indicate that there is already a background image file associated with the question.
                $mform->addElement(
                    'header',
                    'qtype_drawing_drawing_background_image_selected',
                    get_string('drawing_background_image', 'qtype_drawing')
                );
                $mform->addElement(
                    'html',
                    "<div class=\"fitem\"><div class=\"fitemtitle\">" .
                        get_string("selected_background_image_filename", "qtype_drawing") .
                        "</div><div class=\"felement\">
                                  <input type=\"button\" class=\"fp-btn-choose\" value=\"" .
                        get_string("choosebackgroundimage", "qtype_drawing") . "\"
                                   name=\"qtype_drawing_image_filechoose_another\">
                                  <br /><br /><img src='$finalbackground' class=\"img-thumbnail\"></div></div>"
                );
            }
        }

        // File picker.
        $mform->addElement(
            'header',
            'qtype_drawing_drawing_background_image',
            get_string('drawing_background_image', 'qtype_drawing')
        );

        $mform->addElement(
            'filepicker',
            'qtype_drawing_image_file',
            get_string('file'),
            null,
            ['maxbytes' => $COURSE->maxbytes, 'maxfiles' => 1,
            'accepted_types' => ['.png',
            '.jpg',
            '.jpeg',
            '.gif',
            '.svg']]
        );

        $mform->setExpanded('qtype_drawing_drawing_background_image');
    }

    /**
     * Wire up the JavaScript used by the drawing edit form.
     *
     * @return void
     */
    public function js_call() {
        global $PAGE;
        $drawingconfig = get_config('qtype_drawing');

        // Load strings for JS usage.
        qtype_drawing_renderer::translate_to_js($PAGE);

        // Call the AMD module.
        $PAGE->requires->js_call_amd(
            'qtype_drawing/form',
            'qtype_drawing_size_listener',
            [$drawingconfig->defaultcanvaswidth, $drawingconfig->defaultcanvasheight]
        );

        if (isset($this->question->id)) {
            $qid = $this->question->id;
        } else {
            $qid = 0;
        }

        if ($qid == 0) {
            $PAGE->requires->js_call_amd('qtype_drawing/form', 'newquestion', []);
        } else {
            // Ensure options exist to prevent warnings.
            $bgheight = isset($this->question->options->backgroundheight) ? $this->question->options->backgroundheight : 0;
            $bgwidth = isset($this->question->options->backgroundwidth) ? $this->question->options->backgroundwidth : 0;

            $PAGE->requires->js_call_amd(
                'qtype_drawing/form',
                'editquestion',
                [$qid, $bgheight, $bgwidth]
            );
        }
    }

    /**
     * Perform preprocessing of the question data before the form is displayed.
     *
     * @param object $question The question data being edited.
     * @return object The processed question data.
     */
    protected function data_preprocessing($question) {
        global $PAGE;
        $question = parent::data_preprocessing($question);
        $question = $this->data_preprocessing_answers($question);
        $question = $this->data_preprocessing_hints($question);
        $this->js_call();
        return $question;
    }

    /**
     * Validate the submitted form data.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return array Array of error messages, empty if there are none.
     */
    public function validation($data, $files) {
        global $USER;
        $errors = parent::validation($data, $files);
        return $errors;
    }
}
