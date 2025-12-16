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
 * Frontend logic for drawing area iframe.
 *
 * @module     qtype_drawing/drawingarea
 * @copyright  2025 ETH Zurich LET
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

let baseurl = ""; // Your base URL variable

const co = window.console;

const $ = window.$;
// 1. Helper function to generate config and chain dependencies
const addScriptChain = (scriptArray, dependsOnModule) => {
    if (!scriptArray || scriptArray.length === 0) {
        return dependsOnModule;
    }

    const paths = {};
    const shim = {};
    let previousModuleId = dependsOnModule;

    scriptArray.forEach((scriptPath) => {
        // Create ID: "lib/editor/draw.js" -> "lib/editor/draw"
        const moduleId = scriptPath.replace(/\.js$/, '');
        // 1. Define Path (Prepend your baseurl variable)
        paths[moduleId] = baseurl + moduleId;

        // 2. Define Shim (The "Synchronous" Chain)
        shim[moduleId] = {};

        // If there is a previous module, make this one wait for it
        if (previousModuleId) {
            shim[moduleId].deps = [previousModuleId];
        }

        // Update previous to current for the next iteration
        previousModuleId = moduleId;
    });

    // 3. Extend the existing RequireJS configuration
    window.require.config({
        paths: paths,
        shim: shim,
        enforceDefine: false
    });

    // Return the last module ID so the next group can wait for it
    return previousModuleId;
};

const IDENTIFIERS = {
    QUESTION_DRAWING: '#question_drawing',
    QUESTION_TEXT: '#question_text-holder',
};

const scripts_default = [
    // "lib/jquery.js", // Assuming jQuery is already loaded
    "lib/pathseg.js",
    "lib/touch.js",
    "lib/js-hotkeys/jquery.hotkeys.min.js",
    "lib/jquery-svgicons/jquery.svgicons.js",
    "lib/jgraduate/jquery.jgraduate.js",
    "lib/contextmenu/jquery.contextMenu.js",
  //  "lib/jquery-ui/jquery-ui-1.8.17.custom.min.js",
    "lib/editor/browser.js",
    "lib/editor/svgtransformlist.js",
    "lib/editor/math.js",
    "lib/editor/units.js",
    "lib/editor/svgutils.js",
    "lib/editor/sanitize.js",
    "lib/editor/history.js",
    "lib/editor/select.js",
    "lib/editor/draw.js",
    "lib/editor/path_polyfill.js",
    "lib/editor/path.js",
    "lib/editor/dialog.js",
    "lib/editor/svgcanvas.js",
    "lib/editor/method-draw.js",
    "lib/jquery-draginput.js",
    "lib/contextmenu.js",
    "lib/jgraduate/jpicker.min.js",
    "lib/mousewheel.js",
    "lib/extensions/mtouch-events.js",
    "lib/editor/simplify.js",
    "lib/extensions/ext-grid.js",
    "lib/requestanimationframe.js",
    "lib/taphold.js",
    "lib/filesaver.js",
    "lib/extensions/ext-eyedropper.js",
    "lib/extensions/ext-shapes.js",
    "lib/editor/flat.js",
    "lib/editor/flatten.js",
    "lib/extensions/erase.js",
    "lib/extensions/ext-eraser.js",
];




const loadscripts = (callback) => {

    let lastLoaded = addScriptChain(scripts_default, null);

    require([baseurl + "lib/editor/d3.js"], function(d3) {
        window.d3 = d3;
        require([lastLoaded], function() {
            co.log("All grouped scripts loaded in sequence.");
            callback();
        });
    });
};

const fixDrawingHeight = () => {
    const $questionText = $(IDENTIFIERS.QUESTION_TEXT);
    const $questionDrawing = $(IDENTIFIERS.QUESTION_DRAWING);
    let questionTextHeight = 0;
    if ($questionText.length) {
        questionTextHeight = $questionText.outerHeight();
    }
    const windowHeight = $(window).height();
    const calculatedHeight = windowHeight - questionTextHeight;
    $questionDrawing.css('height', calculatedHeight + 'px');
};
/**
 * Initialize the drawing area iframe.
 *
 * @param {object} config Configuration object.
 */
export const init = (config) => {
// Set globals expected by legacy scripts
    window.qtype_drawing_str_comment = config.str.comment;
    window.qtype_drawing_str_newconfirmationmsg = config.str.newconfirmationmsg;
    window.qtype_drawing_str_eraseconfirmationmsg = config.str.eraseconfirmationmsg;
    window.qtype_drawing_str_parsingerror = config.str.parsingerror;
    window.qtype_drawing_str_ignorechanges = config.str.ignorechanges;
    window.qtype_drawing_str_ok = config.str.ok;
    window.qtype_drawing_str_cancel = config.str.cancel;
    window.qtype_drawing_str_eyedroppertool = config.str.eyedroppertool;
    window.qtype_drawing_str_shapelibrary = config.str.shapelibrary;
    window.qtype_drawing_str_drag_markers = config.str.drawmarkers;
    window.qtype_drawing_str_solidcolor = config.str.solidcolor;
    window.qtype_drawing_str_lingrad = config.str.lingrad;
    window.qtype_drawing_str_radgrad = config.str.radgrad;
    window.qtype_drawing_str_new = config.str.new;
    window.qtype_drawing_str_current = config.str.current;
    window.qtype_drawing_str_viewgrid = config.str.viewgrid;
 //   window.fhd_display_mode = config.fhd_display_mode;
    window.qtype_drawing_str_annotationsaved = config.str.annotationsaved;
    window.qtype_drawing_str_saving = config.str.saving;
    window.qtype_drawing_str_saveannotation = config.str.saveannotation;
    window.questionid = config.questionid;
    window.sesskey = config.sesskey;
    window.stid = config.stid;
    window.attemptid = config.attemptid;
    window.attemptcount = config.attemptcount;
    window.uniquefieldnameattemptid = config.uniquefieldnameattemptid;
    baseurl = config.baseurl;
    window.jQuery = window.$;
    const defaultPensize = config.defaultpensize;
    const defaultColor = config.defaultColor;

// Logic for save button state based on parent window input
    const answertxtarea = $(
        '#qtype_drawing_original_stdanswer_id_' + window.attemptid + window.uniquefieldnameattemptid,
        window.parent.document
    ).val();

    if (answertxtarea && answertxtarea.length === 0) {
        $("#tool_saveannotation").attr('disabled', 'disabled');
        $("#tool_saveannotation").css('background', '#ddd');
    }
    fixDrawingHeight();
    $(window).resize(fixDrawingHeight);

    loadscripts(function() {
        window.parent.init_qtype_drawing_embed(window.attemptid + window.uniquefieldnameattemptid);
        if (window.methodDraw) {
            window.methodDraw.ready(function() {
                var svg = window.d3.select("#svgcontent");
                svg.append('g').attr('id', 'erase');

// Get current student answer - if any!
                if (window.methodDraw.lastanswer && 0 !== window.methodDraw.lastanswer.length) {
                    window.methodDraw.loadFromString(window.methodDraw.lastanswer);
// eslint-disable-next-line no-console
                    console.log("loading answer from lastansswer");
                }
                const loadingimageid = "#qtype_drawing_loading_image_" + window.attemptid + window.uniquefieldnameattemptid;

                if ($(loadingimageid, window.parent.document).length) {
                    $(loadingimageid, window.parent.document).hide();
                }

                if (config.useupdateannotationjs) {
                    window.setInterval(window.methodDraw.updateAnnotationDetails, 30000);
                }


                $('#stroke_width').val(defaultPensize);
                window.svgCanvas.setStrokeWidth(defaultPensize);

                window.svgCanvas.setColor('stroke', defaultColor);
                window.svgCanvas.setColor('fill', defaultColor);

            });
            window.methodDraw.init();
        } else {
            co.log("methodDraw not yet initialized");
        }
    });

// Initialize methodDraw when ready

};
