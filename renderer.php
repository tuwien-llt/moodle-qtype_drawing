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
 * drawing question renderer class.
 *
 * @package    qtype
 * @subpackage drawing
 * @copyright ETHZ LET <amr.hourani@id.ethz.ch>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Generates the output for drawing questions.
 *
 * @copyright  ETHZ LET <amr.hourani@id.ethz.chh>
 * @license    http://opensource.org/licenses/BSD-3-Clause
 */
class qtype_drawing_renderer extends qtype_renderer {
    /**
     * Make all qtype_drawing language strings available to JavaScript.
     *
     * @param moodle_page $page The page requiring the strings.
     * @return void
     */
    public static function translate_to_js($page) {
        foreach (array_keys(get_string_manager()->load_component_strings('qtype_drawing', current_language())) as $string) {
            $page->requires->string_for_js($string, 'qtype_drawing');
        }
    }

    /**
     * Return the part of a string after the first occurrence of a needle.
     *
     * @param string $haystack The string to search in.
     * @param string $needle The string to search for.
     * @param bool $caseinsensitive Whether to search case-insensitively.
     * @return string|false The substring after the needle, or false if not found.
     */
    public static function strstr_after($haystack, $needle, $caseinsensitive = false) {
        $strpos = ($caseinsensitive) ? 'stripos' : 'strpos';
        $pos = $strpos($haystack, $needle);
        if (is_int($pos)) {
            return substr($haystack, $pos + strlen($needle));
        }
        // Most likely false or null.
        return $pos;
    }

    /**
     * Create a GD image from a binary string (currently a stub).
     *
     * @param string $imgstring The raw image data.
     * @return string Always an empty string.
     */
    private static function create_gd_image_from_string($imgstring) {
        return  '';
    }

    /**
     * Check whether an RGB colour array is pure blue.
     *
     * @param array $array Colour components as [red, green, blue].
     * @return bool True if the colour is pure blue.
     */
    private static function isblue($array) {
        if ($array[0] == 0 && $array[1] == 0 && $array[2] == 255) {
            return true;
        }
        return false;
    }

    /**
     * Convert a GD image to a PNG data URI.
     *
     * @param \GdImage $gdimage The GD image to convert.
     * @return string The image as a data URI.
     */
    public static function gdimage_to_datauri($gdimage) {

        ob_start();
        imagepng($gdimage);
        $imgdata = ob_get_contents();
        ob_end_clean();

        stream_wrapper_register("BlobDataAsFileStream", \qtype_drawing\blob_data_as_file_stream::class);
        \qtype_drawing\blob_data_as_file_stream::$blobdatastream = $imgdata;
        $imagesize = getimagesize('BlobDataAsFileStream://');
        stream_wrapper_unregister("BlobDataAsFileStream");
        $imgdatauri = 'data:' . $imagesize['mime'] . ';base64,' . base64_encode($imgdata);
        return $imgdatauri;
    }

    /**
     * Generate the display of the formulation part of the question.
     *
     * @param question_attempt $qa The question attempt to display.
     * @param question_display_options $options Controls what should and should not be displayed.
     * @return string HTML fragment.
     */
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

        // Handle first time attempt ID generation.
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
            $background = [null, null, null]; // Contains type, content and filename.
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
        $showeditor = !$options->readonly || $isannotator;
        // 6. Initialize Data Array
        $data = [
            'questionid' => $question->id,
            'canvasinstanceid' => $canvasinstanceid,
            'uniqueattemptinputname' => $uniqueattemptinputname,
            'uniqueattemptid' => $attemptid,
            'attemptuniqueid' => $attemptuniqueid,
            'readonly' => !$showeditor,
            'width' => $canvasinfo->backgroundwidth,
            'height' => $canvasinfo->backgroundheight,
            'questiontext' => $question->format_questiontext($qa),
            'inputname' => $qa->get_qt_field_name('answer'),
            'original_bg_value' => '', // Stays blank except in the annotator view.
            'original_student_answer' => '', // Stays blank except in the annotator view.
            'original_bg_type' => '',
            'textarea_data_info' => '',
        ];

        // Handle validation errors.
        if ($qa->get_state() == question_state::$invalid) {
            $data['validationerror'] = $question->get_validation_error(['answer' => $currentanswer]);
        }

        self::translate_to_js($this->page);

        // CASE A: Editor view (student attempting or annotator grading).
        if ($showeditor) {
            $data['str_fullscreen'] = get_string('enterfullscreen', 'qtype_drawing');

            // Values for the default case (student attempting).
            $editorcontent = $currentanswer;
            $editorbgtype = $background[0];
            $editorbgvalue = $background[1];

            // Special logic for the annotator.
            if ($options->readonly && $isannotator) {
                // Store original values for the hidden textareas that drawingarea.php checks.
                $data['original_bg_value'] = $background[1];
                $data['original_student_answer'] = $currentanswer;
                $data['original_bg_type'] = $background[0];

                // Get original user ID (the student who took the attempt).
                $step = $qa->get_last_step_with_qt_var('answer');
                $originaluserid = $step->get_user_id();

                // Get the attempt count.
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
                $annotationstr = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"' .
                    ' id="baseSVGannotation" width="' . $canvasinfo->backgroundwidth .
                    '" height="' . $canvasinfo->backgroundheight . '">';

                // Add the original background.
                if ($background[0] == 'svg') {
                    $bgclean = preg_replace("/<\\?xml.*\\?>/", '', $background[1]);
                    $bgclean = preg_replace("/<\!DOCTYPE.*\>/", '', $bgclean);
                    $annotationstr .= trim($bgclean);
                } else {
                    $annotationstr .= '<image xlink:href="' . $background[1] . '" height="' . $canvasinfo->backgroundheight .
                        '" width="' . $canvasinfo->backgroundwidth . '" preserveAspectRatio="none"></image>';
                }
                // Add the student answer.
                $stdclean = preg_replace("/<\\?xml.*\\?>/", '', $currentanswer);
                $stdclean = preg_replace("/<\!DOCTYPE.*\>/", '', $stdclean);
                $annotationstr .= $stdclean;

                // Add previous annotations (except the current user's draft).
                $annotations = $DB->get_records('qtype_drawing_annotations', [
                    'questionid' => $question->id,
                    'attemptid' => $attemptid,
                    'annotatedfor' => $originaluserid,
                    'attemptcount' => $attemptcount,
                ]);

                $editorcontent = ''; // Reset content. We only load OUR annotation here.
                $data['textarea_data_info'] = 'original_student_answer'; // Info flag used by the template.

                if ($annotations) {
                    foreach ($annotations as $ann) {
                        if ($ann->annotatedby == $USER->id) {
                            // This is the annotator's previous save/draft. Load it for editing.
                            $editorcontent = $ann->annotation;
                            $data['textarea_data_info'] = 'last_annotation_by_user';
                        } else {
                            // Burn other people's annotations into the background.
                            $annotationstr .= $ann->annotation;
                        }
                    }
                }

                $annotationstr .= '</svg>';

                // Override parameters passed to the template.
                $editorbgtype = 'svg';
                $editorbgvalue = $annotationstr;
            }

            // Fill template variables.
            $data['currentanswer'] = $editorcontent;
            $data['backgroundimagevalue'] = $editorbgvalue;
            $data['backgroundimagetype'] = $editorbgtype;

            // Build the iframe URL.
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
            $iframereadonlyparam = $options->readonly ? 1 : 0;

            $iframeparams = [
                'id' => $question->id,
                'attemptid' => $attemptid,
                'stid' => $originaluserid,
                'uniquefieldnameattemptid' => $uniquefieldnameattemptid,
                'attemptcount' => $attemptcount,
                'readonly' => $iframereadonlyparam,
                'sesskey' => sesskey(),
                // Usage id + slot let drawingarea.php rewrite @@PLUGINFILE@@ URLs in the
                // question text to the same attempt-scoped URLs used on the parent page.
                'qubaid' => $qa->get_usage_id(),
                'slot' => $qa->get_slot(),
            ];
            $data['iframeurl'] = new moodle_url('/question/type/drawing/drawingarea.php', $iframeparams);

            $data['questionembed'] = $canvasinfo->questionembed == 1;

            // Initialize the editor JavaScript.
            $this->page->requires->js_call_amd('qtype_drawing/embedapi', 'encode', []);
            $this->page->requires->js_call_amd('qtype_drawing/view', 'init', [
                $attemptuniqueid,
                $question->id,
                'qtype_drawing_editor_' . $attemptuniqueid,
                'qtype_drawing_drawingwrapper_' . $attemptuniqueid,
                'qtype_drawing_togglebutton_id_' . $attemptuniqueid,
                'quiz_timer_drawing_' . $attemptuniqueid,
            ]);
        } else {
            // CASE B: Read-only view (student reviewing).
            $data['str_showanswer'] = get_string('showanswer', 'qtype_drawing');
            $data['str_showannotation'] = get_string('showannotation', 'qtype_drawing');

            // 1. Prepare Background Style
            if ($background[0] == 'svg') {
                $bgcontent = preg_replace("/<\\?xml.*\\?>/", '', $background[1]);
                $bgcontent = preg_replace("/<\!DOCTYPE.*\>/", '', $bgcontent);
                $finalbg = trim($bgcontent);
                $bgurlcss = 'data:image/svg+xml;utf8,' . rawurlencode($background[1]);
            } else {
                $finalbg = $background[1];
                $bgurlcss = $background[1];
            }

            $bgstyle = (!$bgurlcss || trim($bgurlcss) == '') ? "background: #fff" : "background-image: url($bgurlcss)";

            // 2. Student Answer View
            $studentmergedanswer = str_replace(
                '<svg',
                "<svg style='$bgstyle; background-repeat: no-repeat; background-size: " .
                    "{$canvasinfo->backgroundwidth}px {$canvasinfo->backgroundheight}px;' ",
                $currentanswer
            );
            $data['student_answer_svg_content'] = $studentmergedanswer;

            // 3. Annotated View
            $annotationcontent = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"' .
                ' id="StudentAnnotatedAnswer" width="' . $canvasinfo->backgroundwidth .
                '" height="' . $canvasinfo->backgroundheight . '">';

            if ($background[0] == 'svg') {
                $annotationcontent .= $finalbg;
                $annotationcontent .= $currentanswer;
            } else {
                $annotationcontent .= '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"' .
                    ' width="' . $canvasinfo->backgroundwidth . '" height="' . $canvasinfo->backgroundheight . '">';
                if ($finalbg) {
                    $annotationcontent .= '<image xlink:href="' . $finalbg . '" height="' . $canvasinfo->backgroundheight .
                        '" width="' . $canvasinfo->backgroundwidth . '" preserveAspectRatio="none"></image>';
                }
                $annotationcontent .= '</svg>';
                $annotationcontent .= $currentanswer;
            }

            // Fetch annotations.
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
                'annotatedfor' => $USER->id, // Student views their own.
                'attemptcount' => $attemptcount,
            ]);

            $hasannotations = false;
            if ($annotations) {
                foreach ($annotations as $ann) {
                    $annotationcontent .= $ann->annotation;
                }
                $hasannotations = true;
            }
            $annotationcontent .= '</svg>';
            $data['annotation_svg_content'] = $annotationcontent;

            // Toggle button logic.
            $data['showannotationtoggle'] = $hasannotations && !empty($studentmergedanswer);

            $this->page->requires->js_call_amd('qtype_drawing/view', 'initAnnotationToggle', [
                'id_qtype_drawing_toggle_annotation_' . $attemptuniqueid,
                'qtype_drawing_final_student_toggle_annotation_' . $attemptuniqueid,
                'qtype_drawing_final_student_toggle_answer_' . $attemptuniqueid,
                $data['str_showanswer'],
                $data['str_showannotation'],
            ]);
        }

        return $this->render_from_template('qtype_drawing/formulation', $data);
    }

    /**
     * Generate the specific feedback for this question attempt.
     *
     * @param question_attempt $qa The question attempt to display feedback for.
     * @return string HTML fragment.
     */
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

    /**
     * Generate an automatic description of the correct response.
     *
     * @param question_attempt $qa The question attempt to display.
     * @return string HTML fragment, currently always empty.
     */
    public function correct_response(question_attempt $qa) {
        return ''; /* still not sure what kind of text should be given back for this....*/
        $question = $qa->get_question();

        $answer = $question->get_matching_answer($question->get_correct_response());
        if (!$answer) {
            return '';
        }

        return get_string('correctansweris', 'qtype_drawing', s($answer->answer));
    }




    /**
     * Get the background image for a question.
     *
     * @param object $question The question object.
     * @return array|null Array of [type, content, filename], or null if there is no usable image.
     */
    public static function get_image_for_question($question) {
        return self::get_image_for_files($question->contextid, 'qtype_drawing', 'qtype_drawing_image_file', $question->id);
    }

    /**
     * Get the first usable image from a file area.
     *
     * @param int $context The context ID.
     * @param string $component The component name.
     * @param string $filearea The file area name.
     * @param int $itemid The item ID within the file area.
     * @return array|null Array of [type, content, filename], or null if there is no usable image.
     */
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

    /**
     * Check whether a data URL contains a valid drawing.
     *
     * @param string $dataurl The data URL to check.
     * @param int $bgwidth The background width.
     * @param int $bgheight The background height.
     * @return bool Currently always true.
     */
    public static function isdataurlavaliddrawing($dataurl, $bgwidth, $bgheight) {
        return true;
    }


    /**
     * Check whether a GD image is fully transparent.
     *
     * @param \GdImage $gdimage The GD image to check.
     * @param int $width The image width in pixels.
     * @param int $height The image height in pixels.
     * @return bool True if every pixel is fully transparent.
     */
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
