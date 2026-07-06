/* eslint-disable */
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

import $ from 'jquery';

/**
 * Frontend view logic for qtype_drawing
 *
 * @module     qtype_drawing/view
 * @copyright  2025 ETH Zurich LET
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// eslint-disable-next-line no-unused-vars
const co = window.console;
export const init = (
    attemptUniqueId,
    questionId,
    iframeId,
    wrapperId,
    toggleButtonId,
    timerId
) => {
    // --------------------------------------------------------
    // 1. Iframe Resizing Logic
    // --------------------------------------------------------
    const resizeIframe = () => {
        const $iframe = $(document.getElementById(iframeId));
        const $wrapper = $(document.getElementById(wrapperId));

        // In the fullscreen grader the drawing area is sized by CSS (flex fill).
        // Skip the student-attempt resize/650px cap that would otherwise clamp it
        // with an inline height and leave empty space below the canvas. The grader
        // container class exists only in grade.php's grader_fullscreen template, so
        // the student attempt view and the review.php page keep their behaviour.
        if ($wrapper.closest('.qtype-drawing-grader-fullscreen').length) {
            return;
        }

        const viewportHeight = $(window).height();
        const $timerDiv = $(document.getElementById(timerId));
        const $quizTimerLeft = $('#quiz-time-left');

        if ($quizTimerLeft.length && $quizTimerLeft.html() !== "") {
            $timerDiv.css('display', 'block');
            const calculatedHeight = viewportHeight - $timerDiv.outerHeight();
            $iframe.css('height', calculatedHeight + 'px');
        } else {
            const calculatedHeight = viewportHeight;
            $iframe.css('height', calculatedHeight + 'px');
        }

        if (!$wrapper.hasClass("qtype_drawing_maximized") && $iframe.height() > 650) {
            $iframe.css('height', '650px');
            $wrapper.css('height', '650px');
        } else {
            $wrapper.css('height', $iframe.height() + 'px');
        }

    };

    // Initialize resizing
    $(document).ready(resizeIframe);
    $(window).on('resize', resizeIframe);

    // --------------------------------------------------------
    // 2. Fullscreen Toggle Logic
    // --------------------------------------------------------
    const toggleFullscreen = () => {
        // Update status for saving safety.
        const statusInput = document.getElementById(`id_qtype_drawingsaving_status_${questionId}`);
        if (statusInput) {
            setTimeout(() => { statusInput.value = Math.random(); }, 1000);
        }

        const $wrapper = $(document.getElementById(wrapperId));
        const $iframe = $(document.getElementById(iframeId));
        const $timerDiv = $(document.getElementById(timerId));
        const $quizTimer = $('#quiz-timer'); // The actual Moodle timer element.
        const $quizTimerWrapper = $('#quiz-timer-wrapper');

        $wrapper.toggleClass("qtype_drawing_maximized");
        $iframe.css('height', '100%');

        if ($wrapper.hasClass("qtype_drawing_maximized")) {
            // Enter Fullscreen.
            $wrapper.css('height', '100%');
            $('body').addClass('qtype-drawing-fullscreen-active'); // Helper class if needed.

            if ($quizTimer.length && $quizTimerWrapper.css('display') !== 'none') {
                // Move Moodle timer into our fullscreen view.
                $timerDiv.append($quizTimer);
                $timerDiv.css('display', 'block');
                const calculatedHeight = $(window).height() - $timerDiv.outerHeight();
                $iframe.css('height', calculatedHeight + "px");
            } else {
                $timerDiv.empty(); // Clean up if no timer.
                $iframe.css('height', $(window).height() + "px");
            }
        } else {
            // Exit Fullscreen.
            $('body').removeClass('qtype-drawing-fullscreen-active');
            let viewportHeight = $(window).height();
            if (viewportHeight > 650 || viewportHeight <= 500) {
                viewportHeight = 650;
            }

            $iframe.css('height', viewportHeight + "px");
            $wrapper.css('height', viewportHeight + "px");

            // Move timer back to original spot!
            if ($quizTimer.length && $quizTimerWrapper.length  && $quizTimerWrapper.css('display') !== 'none') {
                $quizTimerWrapper.prepend($quizTimer);
                $timerDiv.css('margin-top', '0em').css('display', 'none');
            }
        }
    };

    // Bind click event to the toggle button!
    $('#' + toggleButtonId).on('click', toggleFullscreen);

    const frame = document.getElementById(`qtype_drawing_editor_${attemptUniqueId}`);
    // --------------------------------------------------------
    // 3. Embed API Init (Legacy Support)
    // --------------------------------------------------------
    // Expose the init function globally for the iframe onload callback if strictly necessary,
    // though postMessage is preferred in modern Moodle.
    window.init_qtype_drawing_embed = (id) => {
        // This function is called by the iframe onload in the template
        // We can keep the logic here or just rely on the iframe loading itself.
        if (frame && window.embedded_svg_edit) {
            window.svgCanvas = new window.embedded_svg_edit(frame);
            window.svgCanvas.setHDQuestionID(id);
            const dw = document.getElementById(`qtype_drawing_drawingwrapper_${id}`);
            // In the fullscreen grader the wrapper is sized by CSS (flex fill), so
            // don't clamp it to the iframe body's initial offsetHeight (~150px).
            const inGrader = dw && $(dw).closest('.qtype-drawing-grader-fullscreen').length;
            if (dw && frame.contentWindow.document.body && !inGrader) {
                dw.style.height = frame.contentWindow.document.body.offsetHeight + "px";
            }
        }
    };

};

/**
 * Logic for toggling annotations in Read-Only view
 *
 * @param {string} btnId
 * @param {string} annotationDivId
 * @param {string} studentDivId
 * @param {string} strShowAnswer
 * @param {string} strShowAnnotation
 */
export const init_annotation_toggle = (btnId, annotationDivId, studentDivId, strShowAnswer, strShowAnnotation) => {
    const btn = document.getElementById(btnId);
    const annotationDiv = document.getElementById(annotationDivId);
    const studentDiv = document.getElementById(studentDivId);

    if (btn) {
        btn.addEventListener('click', () => {
            if (studentDiv.style.display === "none") {
                studentDiv.style.display = "block";
                annotationDiv.style.display = "none";
                btn.value = strShowAnnotation;
            } else {
                studentDiv.style.display = "none";
                annotationDiv.style.display = "block";
                btn.value = strShowAnswer;
            }
        });
    }
};