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
 * Moodle 5.1+ Form handling for qtype_drawing
 *
 * @module     qtype_drawing/form
 * @copyright  2025 ETH Zurich LET, 2025 Simeon Naydenov <moniNaydenov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    CANVASWIDTH: '#qtype_drawing_backgroundwidth',
    CANVASHEIGHT: '#qtype_drawing_backgroundheight',
    FILEPICKER: '#id_qtype_drawing_image_file',
    FILEPICKERFIELDSET: 'fieldset[id$=qtype_drawing_drawing_background_image]',
    FILEPICKERFIELDSETANOTHER: 'fieldset[id$=qtype_drawing_drawing_background_image_selected]',
    PRESERVERATIO: '#id_preservear',
    BACKGROUNDUPLOADED: 'input[name="backgrounduploaded"]',
    CHOOSEFILEBUTTON: 'input[name="qtype_drawing_image_filechoose"]',
    CHOOSEANOTHERFILEBUTTON: 'input[name="qtype_drawing_image_filechoose_another"]',
    DRAWINGCANVAS: 'div.literally', // Generic selector for context menu prevention
    FILEPICKER_ANCHOR: 'div.filepicker-filelist a',
    FILEPICKER_FILENAME: '.filepicker-filename'
};

// Module-level state variables (Singleton pattern)
let originalHeight = null;
let originalWidth = null;
let naturalHeight = null;
let naturalWidth = null;
let editMode = false;
const emptyCanvasDataURL = [];

/**
 * Calculates and sets the height based on aspect ratio.
 * @param {number} width
 */
const calculateHeight = (width) => {
    if (!naturalWidth) {
        naturalWidth = originalWidth;
    }
    if (!naturalHeight) {
        naturalHeight = originalHeight;
    }
    if (!width) {
        width = originalWidth;
    }
    const aspectRatio = naturalHeight / naturalWidth;
    const height = Math.round(width * aspectRatio);
    $(SELECTORS.CANVASHEIGHT).val(height);
};

/**
 * Calculates and sets the width based on aspect ratio.
 * @param {number} height
 */
const calculateWidth = (height) => {
    if (!naturalWidth) {
        naturalWidth = originalWidth;
    }
    if (!naturalHeight) {
        naturalHeight = originalHeight;
    }
    if (!height) {
        height = originalHeight;
    }
    const aspectRatio = naturalWidth / naturalHeight;
    const width = Math.round(height * aspectRatio);
    $(SELECTORS.CANVASWIDTH).val(width);
};

/**
 * Handle keyup on width input.
 * @param {Event} e
 */
const resizeCanvasW = (e) => {
    if (e.which < 48 || e.which > 57) {
        if (e.which !== 8 && e.which !== 46) { // Allow backspace/delete.
            e.preventDefault();
        }
    }
    if ($(SELECTORS.PRESERVERATIO).is(':checked')) {
        calculateHeight($(SELECTORS.CANVASWIDTH).val());
    }
};

/**
 * Handle keyup on height input.
 * @param {Event} e
 */
const resizeCanvasH = (e) => {
    if (e.which < 48 || e.which > 57) {
        if (e.which !== 8 && e.which !== 46) {
            e.preventDefault();
        }
    }
    if ($(SELECTORS.PRESERVERATIO).is(':checked')) {
        calculateWidth($(SELECTORS.CANVASHEIGHT).val());
    }
};

/**
 * Disable context menu on canvas.
 * @param {Event} e
 */
const disableContextMenu = (e) => {
    e.preventDefault();
    e.stopPropagation();
    return false;
};

/**
 * Handle file picker selection change.
 */
const filePickerChange = () => {
    if (editMode === true) {
        $(SELECTORS.FILEPICKERFIELDSET).css('display', 'block');
        $(SELECTORS.FILEPICKERFIELDSETANOTHER).css('display', 'none');
    }

    // Find the URL of the selected file in the filemanager/filepicker preview
    const anchor = $(SELECTORS.FILEPICKER).closest('.fitem').find(SELECTORS.FILEPICKER_ANCHOR);
    const imgURL = anchor.attr('href');

    if (!imgURL) {
        return;
    }

    const image = new Image();
    image.src = imgURL;
    image.id = 'id_qtype_drawing_uploaded_image';

    image.onload = function() {
        if (document.getElementById('pre_existing_background_data')) {
            document.getElementById('pre_existing_background_data').value = '';
        }

        $(SELECTORS.BACKGROUNDUPLOADED).val(1);

        if (image.width && image.width !== null) {
            naturalHeight = image.height;
            naturalWidth = image.width;
            $(SELECTORS.CANVASWIDTH).val(image.width);
            $(SELECTORS.CANVASHEIGHT).val(image.height);
        } else {
            // SVG file or load error, reset to default.
            $(SELECTORS.CANVASWIDTH).val(originalWidth);
            $(SELECTORS.CANVASHEIGHT).val(originalHeight);
        }

        // Append preview to the filename area!
        $(SELECTORS.FILEPICKER_FILENAME).append(
            "<br /><br />" +
            '<div style="background-color: #000; background-blend-mode: multiply; display:inline-block">' +
            '<img src="' + imgURL + '" style="width: 100%; height: auto;"></div>'
        );
    };
};

/**
 * Handle "Choose new file" click in edit mode.
 */
const chooseNewImageFileClick = () => {
    if (editMode === true) {
        // A synchronous confirm() is needed here so the filepicker dialog can be
        // closed again right away when the user cancels.
        // eslint-disable-next-line no-alert
        if (confirm(M.util.get_string('are_you_sure_you_want_to_pick_a_new_bgimage', 'qtype_drawing')) === false) {
            // Close the filepicker dialog if open, or cancel the action
            $('.file-picker.fp-generallayout .yui3-button-close').trigger('click'); // Legacy selector support
            $('.moodle-dialogue-base .closebutton').trigger('click'); // Standard Moodle modal
        }
    }
};

// -----------------------------------------------------------------------------
// EXPORTED FUNCTIONS (Called by PHP via js_call_amd)
// -----------------------------------------------------------------------------

/**
 * Initialize size listeners for edit form.
 * @param {number} width
 * @param {number} height
 */
export const initSizeListener = (width, height) => {
    originalHeight = height;
    originalWidth = width;

    $(document).on('keyup', SELECTORS.CANVASWIDTH, resizeCanvasW);
    $(document).on('keyup', SELECTORS.CANVASHEIGHT, resizeCanvasH);
};

/**
 * Initialize for a NEW question.
 */
export const newquestion = () => {
    emptyCanvasDataURL[0] = 1;

    // Special case: "Save as new" or similar flows might render the "Choose another" button!
    if ($(SELECTORS.CHOOSEANOTHERFILEBUTTON).length > 0) {
        editMode = true;
        $(SELECTORS.FILEPICKERFIELDSET).css('display', 'none');

        $(document).on('click', SELECTORS.CHOOSEANOTHERFILEBUTTON, function() {
            $(SELECTORS.CHOOSEFILEBUTTON).trigger('click');
        });
    }

    $(document).on('change', SELECTORS.FILEPICKER, filePickerChange);
    $(document).on('click', SELECTORS.CHOOSEFILEBUTTON, chooseNewImageFileClick);
    $(document).on('contextmenu', SELECTORS.DRAWINGCANVAS, disableContextMenu);
};

/**
 * Initialize for EDITING an existing question.
 * @param {number} id Question ID
 * @param {number} height
 * @param {number} width
 */
export const editquestion = (id, height, width) => {
    if ($(SELECTORS.CHOOSEANOTHERFILEBUTTON).length > 0) {
        emptyCanvasDataURL[id] = id;
        $(SELECTORS.CHOOSEANOTHERFILEBUTTON).addClass('btn btn-secondary fp-btn-choose');

        naturalHeight = height;
        naturalWidth = width;
        originalWidth = naturalWidth;
        originalHeight = naturalHeight;

        editMode = true;
        $(SELECTORS.FILEPICKERFIELDSET).css('display', 'none');

        $(document).on('click', SELECTORS.CHOOSEANOTHERFILEBUTTON, function() {
            $(SELECTORS.CHOOSEFILEBUTTON).trigger('click');
        });
    }

    $(document).on('change', SELECTORS.FILEPICKER, filePickerChange);
    $(document).on('click', SELECTORS.CHOOSEFILEBUTTON, chooseNewImageFileClick);
    $(document).on('contextmenu', SELECTORS.DRAWINGCANVAS, disableContextMenu);
};