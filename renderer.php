<?php
// phpcs:ignoreFile
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
 * drawing question renderer class.
 *
 * @package    qtype
 * @subpackage drawing
 * @copyright ETHZ LET <amr.hourani@id.ethz.ch>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

/**
 * Generates the output for drawing questions.
 *
 * @copyright  ETHZ LET <amr.hourani@id.ethz.chh>
 * @license    http://opensource.org/licenses/BSD-3-Clause
 */
class qtype_drawing_renderer extends qtype_renderer {
    public static function translate_to_js($page) {
        foreach (array_keys(get_string_manager()->load_component_strings('qtype_drawing', current_language())) as $string) {
            $page->requires->string_for_js($string, 'qtype_drawing');
        }
    }

    public static function strstr_after($haystack, $needle, $caseinsensitive = false) {
        $strpos = ($caseinsensitive) ? 'stripos' : 'strpos';
        $pos = $strpos($haystack, $needle);
        if (is_int($pos)) {
            return substr($haystack, $pos + strlen($needle));
        }
        // Most likely false or null.
        return $pos;
    }

    private static function create_gd_image_from_string($imgstring) {
        return  '';
    }

    private static function isblue($array) {
        if ($array[0] == 0 && $array[1] == 0 && $array[2] == 255) {
            return true;
        }
        return false;
    }

    public static function gdimage_to_datauri($gdimage) {

        ob_start();
        imagepng($gdimage);
        $imgdata = ob_get_contents();
        ob_end_clean();

        stream_wrapper_register("BlobDataAsFileStream", "drawing_blob_data_as_file_stream");
        drawing_blob_data_as_file_stream::$blobdatastream = $imgdata;
        $imagesize = getimagesize('BlobDataAsFileStream://');
        stream_wrapper_unregister("BlobDataAsFileStream");
        $imgdatauri = 'data:' . $imagesize['mime'] . ';base64,' . base64_encode($imgdata);
        return $imgdatauri;
    }
    public function formulation_and_controls(question_attempt $qa, question_display_options $options) {
        global $CFG, $DB, $USER;

        $question = $qa->get_question();
        $canvasinstanceid = uniqid();

        // 1. Load Canvas Info
        $canvasinfo = $DB->get_record('qtype_drawing', ['questionid' => $question->id]);
        if (!$canvasinfo) {
            $canvasinfo = new stdClass();
            $canvasinfo->backgroundwidth = 800;
            $canvasinfo->backgroundheight = 600;
        }

        // 2. Prepare Attempt Identifiers
        $currentanswer = $qa->get_last_qt_var('answer');
        $attemptid = $qa->get_last_qt_var('uniqueuattemptid');

        if ($currentanswer === null) {
            $currentanswer = '';
        }

        // Handle first time attempt ID generation
        if ($options->readonly && !$attemptid && $currentanswer != '') {
            $attemptid = substr(md5($currentanswer), 0, 14) . 'XX';
        }
        if (!$attemptid) {
            $attemptid = random_string(16);
        }

        $uniqueattemptinputname = $qa->get_qt_field_name('uniqueuattemptid');
        $uniquefieldnameattemptid = '_' . str_replace(':', '_', $uniqueattemptinputname);
        $attemptuniqueid = $attemptid . $uniquefieldnameattemptid;

        // 3. Get Background Image
        $background = self::get_image_for_question($question);
        if ($background === null || !isset($background)) {
            $background = [null, null, null]; // [type, content, filename]
        }

        // 4. Check Permissions (Annotator vs Student)
        $isannotator = 0;
        if (empty($question->contextid)) {
            $question->contextid = 1;
        }
        if (has_capability('mod/quiz:grade', context::instance_by_id($question->contextid, IGNORE_MISSING))) {
            $isannotator = 1;
        }

        // 5. Determine View Mode
        // We show the editor if the student is taking the quiz (not readonly)
        // OR if the user is an annotator (even if it's readonly for the student).
        $show_editor = !$options->readonly || $isannotator;
        // 6. Initialize Data Array
        $data = [
            'questionid' => $question->id,
            'canvasinstanceid' => $canvasinstanceid,
            'uniqueattemptinputname' => $uniqueattemptinputname,
            'uniqueattemptid' => $attemptid,
            'attemptuniqueid' => $attemptuniqueid,
            'readonly' => !$show_editor,
            'width' => $canvasinfo->backgroundwidth,
            'height' => $canvasinfo->backgroundheight,
            'questiontext' => $question->format_questiontext($qa),
            'inputname' => $qa->get_qt_field_name('answer'),
            'original_bg_value' => '', // Default empty for student
            'original_student_answer' => '', // Default empty for student
            'original_bg_type' => '',
            'textarea_data_info' => '',
        ];

        // Handle Validation Errors
        if ($qa->get_state() == question_state::$invalid) {
            $data['validationerror'] = $question->get_validation_error(['answer' => $currentanswer]);
        }

        self::translate_to_js($this->page);

        // -------------------------------------------------------------------------
        // CASE A: EDITOR VIEW (Student Attempting OR Annotator Grading)
        // -------------------------------------------------------------------------
        if ($show_editor) {
            $data['str_fullscreen'] = get_string('enterfullscreen', 'qtype_drawing');

            // Default values (Student Attempting)
            $editor_content = $currentanswer;
            $editor_bg_type = $background[0];
            $editor_bg_value = $background[1];

            // SPECIAL LOGIC FOR ANNOTATOR
            if ($options->readonly && $isannotator) {
                // Store original values for the hidden textareas that drawingarea.php checks
                $data['original_bg_value'] = $background[1];
                $data['original_student_answer'] = $currentanswer;
                $data['original_bg_type'] = $background[0];

                // Get original user ID (the student who took the attempt)
                $step = $qa->get_last_step_with_qt_var('answer');
                $originaluserid = $step->get_user_id();

                // Get Attempt Count
                $moodleattempt = optional_param('attempt', null, PARAM_INT);
                if (!$moodleattempt) {
                    $raw = (array)$options->questionreviewlink;
                    foreach ($raw as $val) {
                        if (is_array($val)) {
                            $moodleattempt = $val['attempt'];
                        }
                    }
                }
                $attemptcount = 1;
                if ($moodleattempt && $attemptrec = $DB->get_record('quiz_attempts', ['id' => $moodleattempt])) {
                    $attemptcount = $attemptrec->attempt;
                }

                // 1. Construct the "Background" for the Annotator.
                // It must include the Original Background + Student Answer + Other Annotations.
                $annotationstr = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" id="baseSVGannotation" width="' . $canvasinfo->backgroundwidth . '" height="' . $canvasinfo->backgroundheight . '">';

                // Add Original Background
                if ($background[0] == 'svg') {
                    $bg_clean = preg_replace("/<\\?xml.*\\?>/", '', $background[1]);
                    $bg_clean = preg_replace("/<\!DOCTYPE.*\>/", '', $bg_clean);
                    $annotationstr .= trim($bg_clean);
                } else {
                    $annotationstr .= '<image xlink:href="' . $background[1] . '" height="' . $canvasinfo->backgroundheight . '" width="' . $canvasinfo->backgroundwidth . '" preserveAspectRatio="none"></image>';
                }
                // Add Student Answer
                $std_clean = preg_replace("/<\\?xml.*\\?>/", '', $currentanswer);
                $std_clean = preg_replace("/<\!DOCTYPE.*\>/", '', $std_clean);
                $annotationstr .= $std_clean;

                // Add Previous Annotations (Except current user's draft)
                $annotations = $DB->get_records('qtype_drawing_annotations', [
                    'questionid' => $question->id,
                    'attemptid' => $attemptid,
                    'annotatedfor' => $originaluserid,
                    'attemptcount' => $attemptcount,
                ]);

                $editor_content = ''; // Reset content. We only load OUR annotation here.
                $data['textarea_data_info'] = 'original_student_answer'; // Default info flag

                if ($annotations) {
                    foreach ($annotations as $ann) {
                        if ($ann->annotatedby == $USER->id) {
                            // This is the annotator's previous save/draft. Load it for editing.
                            $editor_content = $ann->annotation;
                            $data['textarea_data_info'] = 'last_annotation_by_user';
                        } else {
                            // Burn other people's annotations into the background
                            $annotationstr .= $ann->annotation;
                        }
                    }
                }

                $annotationstr .= '</svg>';

                // Override parameters passed to template
                $editor_bg_type = 'svg';
                $editor_bg_value = $annotationstr;
            }

            // Fill template variables
            $data['currentanswer'] = $editor_content;
            $data['backgroundimagevalue'] = $editor_bg_value;
            $data['backgroundimagetype'] = $editor_bg_type;

            // Iframe URL
            $step = $qa->get_last_step_with_qt_var('answer');
            $originaluserid = ($step) ? $step->get_user_id() : $USER->id;

            if (!isset($attemptcount)) {
                $moodleattempt = optional_param('attempt', null, PARAM_INT);
                if ($moodleattempt && $rec = $DB->get_record('quiz_attempts', ['id' => $moodleattempt])) {
                    $attemptcount = $rec->attempt;
                } else {
                    $attemptcount = 1;
                }
            }

            // CRITICAL FIX: Pass the actual readonly state to the iframe parameter.
            // If it's a teacher grading ($options->readonly is true), we MUST pass 1.
            // This tells drawingarea.php to show the teacher controls (Save Annotation).
            // If it's a student attempting ($options->readonly is false), we pass 0.
            $iframe_readonly_param = $options->readonly ? 1 : 0;

            $iframe_params = [
                'id' => $question->id,
                'attemptid' => $attemptid,
                'stid' => $originaluserid,
                'uniquefieldnameattemptid' => $uniquefieldnameattemptid,
                'attemptcount' => $attemptcount,
                'readonly' => $iframe_readonly_param,
                'sesskey' => sesskey(),
            ];
            $data['iframeurl'] = new moodle_url('/question/type/drawing/drawingarea.php', $iframe_params);

            $data['questionembed'] = $canvasinfo->questionembed == 1;

            // Initialize Editor JS
            $this->page->requires->js_call_amd('qtype_drawing/embedapi', 'encode', []);
            $this->page->requires->js_call_amd('qtype_drawing/view', 'init', [
                $attemptuniqueid,
                $question->id,
                'qtype_drawing_editor_' . $attemptuniqueid,
                'qtype_drawing_drawingwrapper_' . $attemptuniqueid,
                'qtype_drawing_togglebutton_id_' . $attemptuniqueid,
                'quiz_timer_drawing_' . $attemptuniqueid,
            ]);
        }
        // -------------------------------------------------------------------------
        // CASE B: READ-ONLY VIEW (Student Reviewing)
        // -------------------------------------------------------------------------
        else {
            $data['str_showanswer'] = get_string('showanswer', 'qtype_drawing');
            $data['str_showannotation'] = get_string('showannotation', 'qtype_drawing');

            // 1. Prepare Background Style
            if ($background[0] == 'svg') {
                $bg_content = preg_replace("/<\\?xml.*\\?>/", '', $background[1]);
                $bg_content = preg_replace("/<\!DOCTYPE.*\>/", '', $bg_content);
                $final_bg = trim($bg_content);
                $bg_url_css = 'data:image/svg+xml;utf8,' . rawurlencode($background[1]);
            } else {
                $final_bg = $background[1];
                $bg_url_css = $background[1];
            }

            $bg_style = (!$bg_url_css || trim($bg_url_css) == '') ? "background: #fff" : "background-image: url($bg_url_css)";

            // 2. Student Answer View
            $studentmergedanswer = str_replace(
                '<svg',
                "<svg style='$bg_style; background-repeat: no-repeat; background-size: {$canvasinfo->backgroundwidth}px {$canvasinfo->backgroundheight}px;' ",
                $currentanswer
            );
            $data['student_answer_svg_content'] = $studentmergedanswer;

            // 3. Annotated View
            $annotation_content = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" id="StudentAnnotatedAnswer" width="' . $canvasinfo->backgroundwidth . '" height="' . $canvasinfo->backgroundheight . '">';

            if ($background[0] == 'svg') {
                $annotation_content .= $final_bg;
                $annotation_content .= $currentanswer;
            } else {
                $annotation_content .= '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="' . $canvasinfo->backgroundwidth . '" height="' . $canvasinfo->backgroundheight . '">';
                if ($final_bg) {
                    $annotation_content .= '<image xlink:href="' . $final_bg . '" height="' . $canvasinfo->backgroundheight . '" width="' . $canvasinfo->backgroundwidth . '" preserveAspectRatio="none"></image>';
                }
                $annotation_content .= '</svg>';
                $annotation_content .= $currentanswer;
            }

            // Fetch Annotations
            $moodleattempt = optional_param('attempt', null, PARAM_INT);
            if (!$moodleattempt) {
                $raw = (array)$options->questionreviewlink;
                foreach ($raw as $val) {
                    if (is_array($val)) {
                        $moodleattempt = $val['attempt'];
                    }
                }
            }
            $attemptcount = 1;
            if ($moodleattempt && $rec = $DB->get_record('quiz_attempts', ['id' => $moodleattempt])) {
                $attemptcount = $rec->attempt;
            }

            $annotations = $DB->get_records('qtype_drawing_annotations', [
                'questionid' => $question->id,
                'attemptid' => $attemptid,
                'annotatedfor' => $USER->id, // Student views their own
                'attemptcount' => $attemptcount,
            ]);

            $has_annotations = false;
            if ($annotations) {
                foreach ($annotations as $ann) {
                    $annotation_content .= $ann->annotation;
                }
                $has_annotations = true;
            }
            $annotation_content .= '</svg>';
            $data['annotation_svg_content'] = $annotation_content;

            // Toggle Button Logic
            $data['showannotationtoggle'] = $has_annotations && !empty($studentmergedanswer);

            $this->page->requires->js_call_amd('qtype_drawing/view', 'init_annotation_toggle', [
                'id_qtype_drawing_toggle_annotation_' . $attemptuniqueid,
                'qtype_drawing_final_student_toggle_annotation_' . $attemptuniqueid,
                'qtype_drawing_final_student_toggle_answer_' . $attemptuniqueid,
                $data['str_showanswer'],
                $data['str_showannotation'],
            ]);
        }

        return $this->render_from_template('qtype_drawing/formulation', $data);
    }
    // TODO - delete
    public function formulation_and_controls0(question_attempt $qa, question_display_options $options) {

        global $CFG, $DB;
        // A unique instance id for this particular canvas presentation. Will help refer back to it afterwards.
        $canvasinstanceid = uniqid();
        $question = $qa->get_question();
        $canvasinfo = $DB->get_record('qtype_drawing', ['questionid' => $question->id]);
        if (!$canvasinfo) {
            $canvasinfo = new stdClass();
            $canvasinfo->backgroundwidth = 0;
            $canvasinfo->backgroundheight = 0;
        }
        $currentanswer = $qa->get_last_qt_var('answer');
        $attemptid = $qa->get_last_qt_var('uniqueuattemptid');
        $moodleattempt = optional_param('attempt', null, PARAM_INT);
        if (!$moodleattempt) {
            $raw = (array)$options->questionreviewlink;
            $attributes = [];
            foreach ($raw as $attr => $val) {
                if (is_array($val)) {
                    $moodleattempt = $val['attempt'];
                }
            }
        }
        if ($attemptfullrecord = $DB->get_record('quiz_attempts', ['id' => $moodleattempt], 'id, attempt')) {
            $attemptcount = $attemptfullrecord->attempt;
        }
        if (!$attemptfullrecord || !isset($attemptcount)) {
            $attemptcount = 1;
        }
        // Special and dirty case for the old version of the plugin when annotation was not added yet.
        if ($options->readonly && !$attemptid) {
            $attemptid = substr(md5($currentanswer), 0, 14) . 'XX';
        }
        if (!$attemptid) { // First time attempt.
            $attemptid = random_string(16);
        }

        $uniqueattemptinputname = $qa->get_qt_field_name('uniqueuattemptid');
        $uniquefieldnameattemptid = '_' . str_replace(':', '_', $uniqueattemptinputname);

        $step = $qa->get_last_step_with_qt_var('answer');
        $originaluserid = $step->get_user_id();

        $inputname = $qa->get_qt_field_name('answer');
        $background = self::get_image_for_question($question);
        if ($background === null || !isset($background)) {
            $background = [null, null, null];
        }
        $studentanswer = $qa->get_last_qt_var('answer');
        self::translate_to_js($this->page);
        $isannotator = 0;
        if (empty($question->contextid)) {
            $question->contextid = 1;
        }
        if (has_capability('mod/quiz:grade', context::instance_by_id($question->contextid, IGNORE_MISSING))) {
            $isannotator = 1;
        }
        // TODO SN - delete this
        /*if (!empty($background) && !$options->readonly) {
            $this->page->requires->yui_module('moodle-qtype_drawing-form', 'Y.Moodle.qtype_drawing.form.attemptquestion',
                            array($question->id, $background[1], $canvasinfo->backgroundwidth, $canvasinfo->backgroundheight, $background[0]));
        }*/
        // TODO SN - to here
        $canvas = "<input type=\"hidden\"
        name=\"$uniqueattemptinputname\" value = \"$attemptid\">";
        $canvas .= "<input type=\"hidden\" class=\"qtype_drawing_input\" name=\"qtype_drawingsaving_status_" . $question->id . "\"
        value=\"0\" id=\"id_qtype_drawingsaving_status_" . $question->id . "\">";

        $canvas .= "<div class=\"qtype_drawing_id_" . $question->id . "\"
        data-canvas-instance-id=\"$canvasinstanceid\" id=\"qtype_drawing_attr_id_" . $question->id . "\">";
        if ($options->readonly) {
            $readonlycanvas = ' readonly-canvas';
        } else {
            $readonlycanvas = '';
            $inputnamelastsaved = $inputname . '_lastsaved';
            $inputnamewifidata = $inputname . '_wifidata';
            $canvas .= "<textarea class=\"qtype_drawing_textarea\" name=\"$inputname\"
            id=\"qtype_drawing_textarea_id_" . $attemptid . $uniquefieldnameattemptid . "\"
            style=\"display:none\">$currentanswer</textarea>

            <input type=\"hidden\" name=\"qtype_drawing_drawingevent_" . $attemptid . $uniquefieldnameattemptid . "\"
            id=\"qtype_drawing_drawingevent_" . $attemptid . $uniquefieldnameattemptid . "\" value=\"\">
                <input type=\"hidden\" name=\"qtype_drawing_shouldreload_" . $attemptid . $uniquefieldnameattemptid . "\"
                id=\"qtype_drawing_shouldreload_" . $attemptid . $uniquefieldnameattemptid . "\" value=\"\">";
        }
        if ($readonlycanvas && $readonlycanvas != '') {
            $originalbgtype = $background[0];

            if ($background[0] == 'svg') {
                $background[1] = preg_replace("/<\\?xml.*\\?>/", '', $background[1]);
                $background[1] = preg_replace("/<\!DOCTYPE.*\>/", '', $background[1]);
                $background[1] = trim(preg_replace('/\s+/', ' ', $background[1]));
                $finalbackground = 'data:image/svg+xml;utf8,' . rawurlencode($background[1]);
            } else {
                $finalbackground = $background[1];
            }
            if (!$finalbackground || trim($finalbackground) == '') {
                $backgroundstyle = "background: #fff";
            } else {
                $backgroundstyle = "background-image: url($finalbackground)";
            }
            $annotatorhideshow = '';

            $studentmergedanswer = str_replace(
                '<svg',
                "<svg style='$backgroundstyle;background-repeat: no-repeat;
                            background-size: $canvasinfo->backgroundwidth" . "px
                            $canvasinfo->backgroundheight" . "px;' ",
                $studentanswer
            );
            $disabletoggleannotationbtn = 0;
            if (!$studentmergedanswer) {
                $disabletoggleannotationbtn = 1;
            }
            if ($isannotator == 0) {
                $canvas .= '<div class="qtype_drawing_drawingwrapper"
                            id="qtype_drawing_drawingwrapper_' . $attemptid . $uniquefieldnameattemptid . '"
                            style="height:' . $canvasinfo->backgroundheight . 'px;
                                   width:' . $canvasinfo->backgroundwidth . 'px;' . $annotatorhideshow . '">' .
                                   $studentmergedanswer .
                                   '</div>';
                                   $questiontext = $question->format_questiontext($qa);
                                   $annotationstr = '<div id="qtype_drawing_final_student_toggle_annotation_' . $attemptid . $uniquefieldnameattemptid . '">
                                       <svg xmlns="http://www.w3.org/2000/svg"
                                       xmlns:xlink="http://www.w3.org/1999/xlink" id="StudentAnnotatedAnswer"
                                       width="' . $canvasinfo->backgroundwidth . '" height="' . $canvasinfo->backgroundheight . '">';

                if ($background[0] == 'svg') {
                    $annotationstr .= $background[1];
                    $annotationstr .= $studentanswer;
                } else {
                    $annotationstr .= '<svg xmlns="http://www.w3.org/2000/svg"
                                            xmlns:xlink="http://www.w3.org/1999/xlink" width="' . $canvasinfo->backgroundwidth . '"
                                            height="' . $canvasinfo->backgroundheight . '">';
                    $annotationstr .= '<image xlink:href="' . $background[1] . '" height="' . $canvasinfo->backgroundheight . '"
                                              width="' . $canvasinfo->backgroundwidth . '" preserveAspectRatio="none"></image>';
                    $annotationstr .= '</svg>';
                    $annotationstr .= $studentanswer;
                }

                                   // Display annotations to the student, if any.
                                   global $USER;
                                   $fields = ['questionid' => $question->id, 'attemptid' => $attemptid, 'annotatedfor' => $USER->id, 'attemptcount' => $attemptcount];
                if ($annotations = $DB->get_records('qtype_drawing_annotations', $fields)) {
                    foreach ($annotations as $annotation) {
                        $annotationstr .= $annotation->annotation;
                    }
                } else {
                    $disabletoggleannotationbtn = 1;
                }

                                   $annotationstr .= '</svg></div>';

                                   // If toggle to show only the answer for the student without annotation.
                                   $annotationstr .= '<div id="qtype_drawing_final_student_toggle_answer_' . $attemptid . $uniquefieldnameattemptid . '" style="display:none">' . $studentmergedanswer . '</div>';
                                   $annotationtogglescript = '
                    <script type="text/javascript">
                        function qtype_drawing_toggle_annotation_' . $attemptid . $uniquefieldnameattemptid . '(){
                               var annotationdrawing = document.getElementById("qtype_drawing_final_student_toggle_annotation_' . $attemptid . $uniquefieldnameattemptid . '");
                               var studentdrawing = document.getElementById("qtype_drawing_final_student_toggle_answer_' . $attemptid . $uniquefieldnameattemptid . '");
                               var togglebtnanswers = document.getElementById("id_qtype_drawing_toggle_annotation_' . $attemptid . $uniquefieldnameattemptid . '");
                               if (studentdrawing.style.display === "none") {
                                    studentdrawing.style.display = "block";
                                    annotationdrawing.style.display = "none";
                                    togglebtnanswers.value = "' . get_string('showannotation', 'qtype_drawing') . '";
                                } else {
                                    studentdrawing.style.display = "none";
                                    annotationdrawing.style.display = "block";
                                    togglebtnanswers.value = "' . get_string('showanswer', 'qtype_drawing') . '";
                                }
                        }
                    </script>
                 ';
                                   $tglbtnspan = '';
                if ($disabletoggleannotationbtn != 1) {
                    $tglbtnspan = '<span style="float:right"><input type="button" value="' . get_string('showanswer', 'qtype_drawing') . '" id="id_qtype_drawing_toggle_annotation_' . $attemptid . $uniquefieldnameattemptid . '" onclick="qtype_drawing_toggle_annotation_' . $attemptid . $uniquefieldnameattemptid . '()"></span>';
                }

                                   $result = html_writer::tag('div', $annotationtogglescript . $tglbtnspan . $questiontext . $annotationstr, ['class' => 'qtext']);

                if ($qa->get_state() == question_state::$invalid) {
                    $result .= html_writer::nonempty_tag(
                        'div',
                        $question->get_validation_error(['answer' => $currentanswer]),
                        ['class' => 'validationerror']
                    );
                }
                                   return $result;
            } else {
                $canvas .= "<textarea id=\"qtype_drawing_original_bg_id_" . $attemptid . $uniquefieldnameattemptid . "\"
                style=\"display:none\">$background[1]</textarea>";
                $canvas .= "<textarea id=\"qtype_drawing_original_stdanswer_id_" . $attemptid . $uniquefieldnameattemptid . "\"
                style=\"display:none\">$studentanswer</textarea>";

                $annotationstr = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
                                       id="baseSVGannotation" width="' . $canvasinfo->backgroundwidth . '"
                                       height="' . $canvasinfo->backgroundheight . '">';

                $studentmergedanswer = preg_replace("/<\\?xml.*\\?>/", '', $studentmergedanswer);
                $studentmergedanswer = preg_replace("/<\!DOCTYPE.*\>/", '', $studentmergedanswer);

                $canvas .= "<input type=\"hidden\" id=\"qtype_drawing_real_org_bg_" . $attemptid . $uniquefieldnameattemptid . "\"
                style=\"display:none\" value=\"$background[0]\">";

                if ($background[0] == 'svg') {
                    $annotationstr .= $background[1];
                    $annotationstr .= $studentanswer;
                } else {
                    $annotationstr .= '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
                                            width="' . $canvasinfo->backgroundwidth . '" height="' . $canvasinfo->backgroundheight . '">';
                    $annotationstr .= '<image xlink:href="' . $background[1] . '" height="' . $canvasinfo->backgroundheight . '"
                                              width="' . $canvasinfo->backgroundwidth . '" preserveAspectRatio="none"></image>';
                    $annotationstr .= '</svg>';
                    $annotationstr .= $studentanswer;
                }

                // Get all annotations, if any, plus student answer and background.
                global $USER;
                $fields = ['questionid' => $question->id, 'attemptid' => $attemptid, 'annotatedfor' => $originaluserid, 'attemptcount' => $attemptcount];
                if ($annotations = $DB->get_records('qtype_drawing_annotations', $fields)) {
                    foreach ($annotations as $annotationdrawing) {
                        if ($annotationdrawing->annotatedby == $USER->id) {
                            $canvas .= "<textarea class=\"qtype_drawing_textarea\" name=\"$inputname\"
                            id=\"qtype_drawing_textarea_id_" . $attemptid . $uniquefieldnameattemptid . "\"
                            style=\"display:none\"
                            data-info=\"last_annotation_by_user\">$annotationdrawing->annotation</textarea>";
                            continue;
                        }

                        $annotationstr .= $annotationdrawing->annotation;
                    }

                    $annotationstr .= '</svg>';
                    $background[1] = $annotationstr;
                    $background[0] = 'svg';
                } else {
                    $background[0] = 'svg';
                    $background[1] = $annotationstr . '</svg>';
                    $canvas .= "<textarea class=\"qtype_drawing_textarea\" name=\"$inputname\"
                    id=\"qtype_drawing_textarea_id_" . $attemptid . $uniquefieldnameattemptid . "\"
                                          style=\"display:none\"
                                          data-info=\"original_student_answer\"></textarea>";
                }
            }
        }
        if (!is_array($background) || !array_key_exists(1, $background)) {
            $background[0] = '';
            $background[1] = '';
        }
        $canvas .= '
					<script type="module" src="' . $CFG->wwwroot . '/question/type/drawing/amd/src/embedapi.js"></script>
					<script type="text/javascript">
					svgCanvas = null;
					function init_qtype_drawing_embed(qid) {
							var frame = document.getElementById("qtype_drawing_editor_"+qid);
							svgCanvas = new embedded_svg_edit(frame);
					  	    svgCanvas.setHDQuestionID(qid);
						    var drawingwrapper = document.getElementById("qtype_drawing_drawingwrapper_"+qid);
							drawingwrapper.style = "height:"+frame.contentWindow.document.body.offsetHeight + "px";


					}
					</script>

					<script type="text/javascript">
							//<![CDATA[
									YUI().use("node", "event", function(Y) {
											var doc = Y.one("body");
											var drawing_iframeid = "#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '";
											var drawing_toggle_btn = "#qtype_drawing_togglebutton_id_' . $attemptid . $uniquefieldnameattemptid . '";

											var frame = Y.one("#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '");
											var padding = 150;
											var lastHeight;
											var resize = function(e) {

    											var viewportHeight =  window.innerHeight;
                          var quiz_timer_div = document.getElementById("quiz-time-left");
                          var drawing_fullsc_' . $attemptid . $uniquefieldnameattemptid . ' =
                          document.getElementById("quiz_timer_drawing_' . $attemptid . $uniquefieldnameattemptid . '");
                          if (quiz_timer_div && quiz_timer_div.innerHTML !== "") {
                               Y.one("#quiz_timer_drawing_' . $attemptid . $uniquefieldnameattemptid . '").
                                setStyle("display", "block");
                               var calculatedheight =  viewportHeight -
                                    Y.one("#quiz_timer_drawing_' . $attemptid . $uniquefieldnameattemptid . '").get("clientHeight");
                               Y.one("#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '").
                                setStyle("height", calculatedheight+ "px");
                          } else {
                               var calculatedheight = viewportHeight;
                               Y.one("#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '").
                               setStyle("height", calculatedheight+ "px");
                          }

                          if (!Y.one("#qtype_drawing_drawingwrapper_' . $attemptid . $uniquefieldnameattemptid . '").
                            hasClass("qtype_drawing_maximized") &&
                              calculatedheight > 650) {
                              Y.one("#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '").
                                setStyle("height", "650px");
                              Y.one("#qtype_drawing_drawingwrapper_' . $attemptid . $uniquefieldnameattemptid . '").
                                setStyle("height", "650px");
                          } else {
                              Y.one("#qtype_drawing_drawingwrapper_' . $attemptid . $uniquefieldnameattemptid . '").
                              setStyle("height", calculatedheight +"px");
                              Y.one("#qtype_drawing_drawingwrapper_' . $attemptid . $uniquefieldnameattemptid . '").
                              set("height", calculatedheight +"px");
                          }

                  	  };

                      Y.on("domready", function() {
                        resize();
                      });

                  	  Y.on("windowresize", resize);

                  	  });
                  setTimeout(function(){ Y.one("#id_qtype_drawingsaving_status_' . $question->id . '").set("value",Math.random()); }, 3000);
                  function qtype_drawing_fullscreen_' . $attemptid . $uniquefieldnameattemptid . '() {
                      setTimeout(function(){ Y.one("#id_qtype_drawingsaving_status_' . $question->id . '").set("value",Math.random()); }, 1000);
                      var doc = Y.one("body");
                      var drawing_iframeid = "#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '";
                      var drawing_toggle_btn = "#qtype_drawing_togglebutton_id_' . $attemptid . $uniquefieldnameattemptid . '";

                      var frame = Y.one("#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '");
                      var padding = 150;
                      var lastHeight;
                      var quiz_is_timed = 0;
                      Y.one("#qtype_drawing_drawingwrapper_' . $attemptid . $uniquefieldnameattemptid . '").
                        toggleClass("qtype_drawing_maximized");
                      Y.one("#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '").
                        set("height","100%");

                      if (Y.one("#qtype_drawing_drawingwrapper_' . $attemptid . $uniquefieldnameattemptid . '").
                          hasClass("qtype_drawing_maximized")) {
                          Y.one("#qtype_drawing_drawingwrapper_' . $attemptid . $uniquefieldnameattemptid . '").
                            set("height","100%");
                          Y.one("#qtype_drawing_drawingwrapper_' . $attemptid . $uniquefieldnameattemptid . '").
                            setStyle("height","100%");

                          Y.one("#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '").
                            set("height","100%");
                          Y.one("#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '").
                            setStyle("height","100%");

                          var quiz_timer_div = document.getElementById("quiz-time-left");
                          var drawing_fullsc_' . $attemptid . $uniquefieldnameattemptid . ' =
                              document.getElementById("quiz_timer_drawing_' . $attemptid . $uniquefieldnameattemptid . '");

                          if (quiz_timer_div && quiz_timer_div.innerHTML !== "") {

                               drawing_fullsc_' . $attemptid . $uniquefieldnameattemptid . '.appendChild(document.getElementById("quiz-timer"));

                               Y.one("#quiz_timer_drawing_' . $attemptid . $uniquefieldnameattemptid . '").
                                setStyle("display", "block");
                               var calculatedheight = doc.get("winHeight") -
                                   Y.one("#quiz_timer_drawing_' . $attemptid . $uniquefieldnameattemptid . '").
                                    get("clientHeight");
                               Y.one("#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '").
                                setStyle("height", calculatedheight+ "px");
                          } else {
                              if (drawing_fullsc_' . $attemptid . $uniquefieldnameattemptid . '.hasChildNodes()) {
                                  drawing_fullsc_' . $attemptid . $uniquefieldnameattemptid . '.
                                    removeChild(document.getElementById("quiz-timer").cloneNode(true));
                              }
                              var  calculatedheight = doc.get("winHeight");
                              Y.one("#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '").
                                setStyle("height", calculatedheight+ "px");

                          }
                      } else {

                      	var viewportHeight = doc.get("winHeight");
                          if (viewportHeight > 650 || viewportHeight <= 500) viewportHeight = 650;
                	       Y.one("#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '").
                          setStyle("height", viewportHeight + "px");
                          Y.one("#qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '").
                          set("height", viewportHeight + "px");

                          Y.one("#qtype_drawing_drawingwrapper_' . $attemptid . $uniquefieldnameattemptid . '").
                          setStyle("height", viewportHeight +"px");
                          Y.one("#qtype_drawing_drawingwrapper_' . $attemptid . $uniquefieldnameattemptid . '").
                          set("height", viewportHeight +"px");

                          if (document.getElementById("quiz-timer")) {
                               document.getElementById("quiz-timer-wrapper").appendChild(document.getElementById("quiz-timer"));
                               Y.one("#quiz_timer_drawing_' . $attemptid . $uniquefieldnameattemptid . '").setStyle("margin-top", "0em");
                               Y.one("#quiz_timer_drawing_' . $attemptid . $uniquefieldnameattemptid . '").setStyle("display", "none");
                          }

                      }

                  }
              //]]
          </script>


				<textarea style="display:none"
                  id="qtype_drawing_background_image_value_' . $attemptid . $uniquefieldnameattemptid . '">' .
                  $background[1] . '</textarea>
				<input type="hidden" style="display:none"
               id="qtype_drawing_background_image_type_' . $attemptid . $uniquefieldnameattemptid . '"
               value="' . $background[0] . '">
				<input type="hidden" style="display:none"
               id="qtype_drawing_background_image_width_' . $attemptid . $uniquefieldnameattemptid . '"
               value="' . $canvasinfo->backgroundwidth . '">
				<input type="hidden" style="display:none"
               id="qtype_drawing_background_image_height_' . $attemptid . $uniquefieldnameattemptid . '"
               value="' . $canvasinfo->backgroundheight . '">
				<div class="qtype_drawing_drawingwrapper"
             id="qtype_drawing_drawingwrapper_' . $attemptid . $uniquefieldnameattemptid . '">
        <span id="quiz_timer_drawing_' . $attemptid . $uniquefieldnameattemptid . '"
              style="display:none; background-color:#fff"></span>
				<span class="qtype_drawing_togglebutton"
              id="qtype_drawing_togglebutton_id_' . $attemptid . $uniquefieldnameattemptid . '"
              onclick="qtype_drawing_fullscreen_' . $attemptid . $uniquefieldnameattemptid . '()">&nbsp;</span>
				<iframe
          src="' . $CFG->wwwroot . '/question/type/drawing/drawingarea.php?id=' . $question->id .
          '&attemptid=' . $attemptid . '&stid=' . $originaluserid .
          '&uniquefieldnameattemptid=' . $uniquefieldnameattemptid .
          '&attemptcount=' . $attemptcount .
          '&readonly=' . $options->readonly . '&sesskey=' . sesskey() . '"
          id="qtype_drawing_editor_' . $attemptid . $uniquefieldnameattemptid . '"
          onload="init_qtype_drawing_embed(\'' . $attemptid . $uniquefieldnameattemptid . '\')" >
        </iframe>
				</div>
				';

                  $canvas .= '</div>';

                  $questiontext = $question->format_questiontext($qa);

                  $result = html_writer::tag('div', $questiontext . $canvas, ['class' => 'qtext']);

        if ($qa->get_state() == question_state::$invalid) {
            $result .= html_writer::nonempty_tag(
                'div',
                $question->get_validation_error(['answer' => $currentanswer]),
                ['class' => 'validationerror']
            );
        }
                  return $result;
    }

    public function specific_feedback(question_attempt $qa) {
        $question = $qa->get_question();

        $answer = $question->get_matching_answer(['answer' => $qa->get_last_qt_var('answer')]);
        if (!$answer || !$answer->feedback) {
            return '';
        }

        return $question->format_text(
            $answer->feedback,
            $answer->feedbackformat,
            $qa,
            'question',
            'answerfeedback',
            $answer->id
        );
    }

    public function correct_response(question_attempt $qa) {
        return ''; /* still not sure what kind of text should be given back for this....*/
        $question = $qa->get_question();

        $answer = $question->get_matching_answer($question->get_correct_response());
        if (!$answer) {
            return '';
        }

        return get_string('correctansweris', 'qtype_drawing', s($answer->answer));
    }




    public static function get_image_for_question($question) {
        return self::get_image_for_files($question->contextid, 'qtype_drawing', 'qtype_drawing_image_file', $question->id);
    }

    public static function get_image_for_files($context, $component, $filearea, $itemid) {
        $fs = get_file_storage();
        $files = $fs->get_area_files($context, $component, $filearea, $itemid, 'id');
        if ($files) {
            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                if ($file->get_content() == null) {
                    return null;
                }
                if ($file->get_mimetype() == 'image/svg+xml') { // SVG.
                    return ['svg', $file->get_content(), $file->get_filename()];
                }
                $image = imagecreatefromstring($file->get_content());
                if ($image === false) {
                    return null;
                }
                $imgdatauri = self::gdimage_to_datauri($image);
                imagedestroy($image);
                return ['datauri', $imgdatauri, $file->get_filename()];
            }
        }
        return null;
    }
    public static function isdataurlavaliddrawing($dataurl, $bgwidth, $bgheight) {
        return true;
    }


    private static function isimagetransparent($gdimage, $width, $height) {
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                // Check the alpha channel (4th byte from the right) if it's completely transparent.
                if (((imagecolorat($gdimage, $x, $y) >> 24) & 0xFF) !== 127) {
                    // Something is painted, great!.
                    return false;
                }
            }
        }
        return true;
    }
}

class drawing_blob_data_as_file_stream {
    private static $blobdataposition = 0;
    public static $blobdatastream = '';
    public $context;

    public static function stream_open($path, $mode, $options, &$openedpath) {
        static::$blobdataposition = 0;
        return true;
    }

    public static function stream_seek($seekoffset, $seekwhence) {
        $blobdatalength = strlen(static::$blobdatastream);
        switch ($seekwhence) {
            case SEEK_SET:
                $newblobdataposition = $seekoffset;
                break;
            case SEEK_CUR:
                $newblobdataposition = static::$blobdataposition + $seekoffset;
                break;
            case SEEK_END:
                $newblobdataposition = $blobdatalength + $seekoffset;
                break;
            default:
                return false;
        }
        if (($newblobdataposition >= 0) and ($newblobdataposition <= $blobdatalength)) {
            static::$blobdataposition = $newblobdataposition;
            return true;
        } else {
            return false;
        }
    }

    public static function stream_tell() {
        return static::$blobdataposition;
    }

    public static function stream_read($readbuffersize) {
        $readdata = substr(static::$blobdatastream, static::$blobdataposition, $readbuffersize);
        static::$blobdataposition += strlen($readdata);
        return $readdata;
    }

    public static function stream_write($writedata) {
        $writedatalength = strlen($writedata);
        static::$blobdatastream = substr(static::$blobdatastream, 0, static::$blobdataposition) .
        $writedata . substr(static::$blobdatastream, static::$blobdataposition += $writedatalength);
        return $writedatalength;
    }

    public static function stream_eof() {
        return static::$blobdataposition >= strlen(static::$blobdatastream);
    }
}
