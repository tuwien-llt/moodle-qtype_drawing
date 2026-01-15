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
 * Grader UI logic for qtype_drawing fullscreen grader.
 *
 * @module     qtype_drawing/grader_ui
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

/**
 * Initialize the grader UI.
 *
 * @param {Object} config Configuration object from PHP.
 */
export const init = (config) => {
    const container = document.querySelector('.qtype-drawing-grader-fullscreen');
    if (!container) {
        return;
    }

    const maxMark = parseFloat(config.maxmark);

    // Quick grade buttons.
    initQuickGradeButtons(maxMark);

    // Form validation.
    initFormValidation(maxMark);

    // Save and next button handler.
    initSaveNextButton();

    // Keyboard shortcuts.
    initKeyboardShortcuts();
};

/**
 * Initialize quick grade percentage buttons.
 *
 * @param {number} maxMark Maximum mark for this question.
 */
const initQuickGradeButtons = (maxMark) => {
    const buttons = document.querySelectorAll('.quick-grade-btn');
    const markInput = document.getElementById('grade-mark');

    buttons.forEach(button => {
        button.addEventListener('click', () => {
            const percentage = parseInt(button.dataset.grade, 10);
            const grade = (percentage / 100) * maxMark;
            markInput.value = grade.toFixed(2);

            // Visual feedback.
            buttons.forEach(b => b.classList.remove('active'));
            button.classList.add('active');
        });
    });
};

/**
 * Extract annotation SVG from the drawing iframe.
 *
 * @returns {string} The SVG annotation string, or empty string if not available.
 */
const extractAnnotationFromIframe = () => {
    // Find the drawing iframe - it's inside the qtype_drawing_drawingwrapper
    const iframe = document.querySelector('.qtype_drawing_drawingwrapper iframe');
    if (!iframe) {
        return '';
    }

    try {
        const iframeWindow = iframe.contentWindow;
        if (iframeWindow && iframeWindow.methodDraw && iframeWindow.svgCanvas) {
            return iframeWindow.svgCanvas.getSvgString();
        }
    } catch (e) {
        // Cross-origin or other access error
        // eslint-disable-next-line no-console
        console.warn('Could not access iframe content for annotation:', e);
    }

    return '';
};

/**
 * Initialize form validation.
 *
 * @param {number} maxMark Maximum mark for this question.
 */
const initFormValidation = (maxMark) => {
    const form = document.getElementById('qtype-drawing-grade-form');
    const markInput = document.getElementById('grade-mark');

    if (!form || !markInput) {
        return;
    }

    form.addEventListener('submit', async(e) => {
        const mark = parseFloat(markInput.value);

        if (isNaN(mark) || mark < 0 || mark > maxMark) {
            e.preventDefault();
            const errorMsg = await getString('invalidmark', 'qtype_drawing');
            Notification.alert(
                await getString('error'),
                errorMsg + ` (0 - ${maxMark})`
            );
            markInput.focus();
            return false;
        }

        // Extract annotation from iframe and put in hidden field.
        const annotationField = document.getElementById('annotation-field');
        if (annotationField) {
            const annotation = extractAnnotationFromIframe();
            annotationField.value = annotation;
        }

        // Show loading state on submit buttons.
        const submitButtons = form.querySelectorAll('button[type="submit"]');
        submitButtons.forEach(btn => {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> ' +
                btn.textContent.trim();
        });

        return true;
    });

    // Real-time validation on input.
    markInput.addEventListener('input', () => {
        const mark = parseFloat(markInput.value);
        if (isNaN(mark) || mark < 0 || mark > maxMark) {
            markInput.classList.add('is-invalid');
        } else {
            markInput.classList.remove('is-invalid');
        }
    });
};

/**
 * Initialize save and next button to set hidden field before form submit.
 */
const initSaveNextButton = () => {
    const saveNextBtn = document.querySelector('.qtype-drawing-grader-savenext');
    const savenextField = document.getElementById('savenext-field');

    if (!saveNextBtn || !savenextField) {
        return;
    }

    saveNextBtn.addEventListener('click', () => {
        savenextField.value = '1';
    });
};

/**
 * Initialize keyboard shortcuts for efficient grading.
 */
const initKeyboardShortcuts = () => {
    document.addEventListener('keydown', (e) => {
        // Only process shortcuts when not in a text field.
        if (e.target.tagName === 'TEXTAREA' || (e.target.tagName === 'INPUT' && e.target.type !== 'submit')) {
            return;
        }

        // Ctrl/Cmd + S: Save.
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            const saveBtn = document.querySelector('.qtype-drawing-grader-save');
            if (saveBtn) {
                saveBtn.click();
            }
        }

        // Ctrl/Cmd + Enter: Save and next.
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            const savenextField = document.getElementById('savenext-field');
            const saveNextBtn = document.querySelector('.qtype-drawing-grader-savenext');
            if (saveNextBtn && savenextField) {
                savenextField.value = '1';
                saveNextBtn.click();
            }
        }

        // Arrow keys for navigation (when not in input).
        if (e.key === 'ArrowLeft' && !e.target.closest('input, textarea')) {
            const prevLink = document.querySelector('.qtype-drawing-grader-nav a[href*="attemptid"]:first-child');
            if (prevLink && !prevLink.closest('button[disabled]')) {
                prevLink.click();
            }
        }

        if (e.key === 'ArrowRight' && !e.target.closest('input, textarea')) {
            const nextLink = document.querySelector('.qtype-drawing-grader-nav a[href*="attemptid"]:last-child');
            if (nextLink && !nextLink.closest('button[disabled]')) {
                nextLink.click();
            }
        }
    });
};
