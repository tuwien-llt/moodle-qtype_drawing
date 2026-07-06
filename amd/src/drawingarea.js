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

/**
 * Frontend logic for drawing area iframe.
 *
 * @module     qtype_drawing/drawingarea
 * @copyright  2025 ETH Zurich LET
 *
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

let baseurl = ""; // Your base URL variable
let rev = ""; // Moodle JS cache revision, used to cache-bust the bundled lib/ scripts.

const co = window.console;

// Query string that ties a lib/ script URL to Moodle's JS revision so that
// "Purge all caches" (which bumps $CFG->jsrev) forces browsers to refetch it.
const revSuffix = () => (rev ? "?rev=" + rev : "");

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
        // 1. Define Path (Prepend your baseurl variable). Add ".js" + the rev query
        // explicitly: RequireJS skips its own ".js" append when the URL contains "?",
        // and the query busts the browser cache when Moodle's jsrev changes.
        paths[moduleId] = baseurl + moduleId + ".js" + revSuffix();

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

    require([baseurl + "lib/editor/d3.js" + revSuffix()], function(d3) {
        window.d3 = d3;
        require([lastLoaded], function() {
            co.log("All grouped scripts loaded in sequence.");
            callback();
        });
    });
};

const MIN_CANVAS_HEIGHT = 120;
const MIN_TEXT_HEIGHT = 40;

const fixDrawingHeight = () => {
    const drawing = document.getElementById('question_drawing');
    if (!drawing) {
        return;
    }
    const windowHeight = $(window).height();
    // Everything stacked above the canvas (the question-text holder + the drag bar,
    // when the question is embedded) determines where the canvas starts; give it the
    // rest of the viewport. Falls back to full height when nothing is above it.
    const top = drawing.getBoundingClientRect().top;
    let calculatedHeight = windowHeight - top;
    if (calculatedHeight < MIN_CANVAS_HEIGHT) {
        calculatedHeight = MIN_CANVAS_HEIGHT;
    }
    drawing.style.height = calculatedHeight + 'px';
};

/**
 * Wire up the collapse button and the drag-to-resize bar that sit between the
 * embedded question text and the drawing canvas. No-op when the question text is
 * not embedded (the bar/holder are absent). Persists the chosen size and the
 * collapsed state per question in localStorage so they survive page reloads.
 *
 * @param {object} config Configuration object passed to init().
 */
const initQuestionTextControls = (config) => {
    const holder = document.getElementById('question_text-holder');
    const bar = document.getElementById('qtype_drawing_qtext_bar');
    const toggle = document.getElementById('qtype_drawing_qtext_toggle');
    if (!holder || !bar || !toggle) {
        return;
    }

    const chevron = document.getElementById('qtype_drawing_qtext_chevron');
    const label = document.getElementById('qtype_drawing_qtext_label');
    const body = document.body;
    const storeKey = 'qtype_drawing_qtext_' + (config.questionid || 'x');
    const CHEVRON_UP = '▲';   // ▲ (collapse the text upwards)
    const CHEVRON_DOWN = '▼'; // ▼ (expand the text downwards)

    let collapsed = false;

    const readState = () => {
        try {
            const raw = window.localStorage.getItem(storeKey);
            if (raw) {
                return JSON.parse(raw);
            }
        } catch (e) {
            // localStorage blocked or corrupt value — ignore.
        }
        return null;
    };

    const persist = () => {
        try {
            const h = holder.style.height ? parseInt(holder.style.height, 10) : null;
            window.localStorage.setItem(storeKey, JSON.stringify({collapsed: collapsed, height: h}));
        } catch (e) {
            // localStorage blocked — ignore.
        }
    };

    const applyCollapsed = (isCollapsed) => {
        collapsed = isCollapsed;
        body.classList.toggle('qtype-drawing-qtext-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        const text = collapsed ? config.str.showquestiontext : config.str.hidequestiontext;
        if (label) {
            label.textContent = text;
        }
        if (chevron) {
            chevron.textContent = collapsed ? CHEVRON_DOWN : CHEVRON_UP;
        }
        toggle.setAttribute('title', text);
        fixDrawingHeight();
    };

    // Apply an explicit holder height, clamped so neither area collapses past its
    // minimum. Setting an explicit height means the user is in control, so drop the
    // default max-height cap. Reflows the canvas afterwards.
    const setHolderHeight = (px) => {
        const barHeight = bar.offsetHeight || 0;
        const maxHeight = $(window).height() - barHeight - MIN_CANVAS_HEIGHT;
        const clamped = Math.max(MIN_TEXT_HEIGHT, Math.min(px, maxHeight));
        holder.style.height = clamped + 'px';
        holder.style.maxHeight = 'none';
        fixDrawingHeight();
    };

    // Restore any persisted layout.
    const saved = readState();
    if (saved) {
        if (typeof saved.height === 'number' && saved.height > 0) {
            setHolderHeight(saved.height);
        }
        if (saved.collapsed) {
            applyCollapsed(true);
        }
    }

    // Collapse/expand. Stop the pointer from also starting a drag on the bar.
    toggle.addEventListener('pointerdown', (e) => {
        e.stopPropagation();
    });
    toggle.addEventListener('click', (e) => {
        e.stopPropagation();
        applyCollapsed(!collapsed);
        persist();
    });

    // Drag the bar to resize the two areas (disabled while collapsed). Uses
    // pointer events + capture so it works with mouse, touch and pen, and keeps
    // receiving moves even when the pointer travels over the canvas below.
    bar.addEventListener('pointerdown', (e) => {
        if (collapsed || (typeof e.button === 'number' && e.button !== 0)) {
            return;
        }
        e.preventDefault();
        const startY = e.clientY;
        const startHeight = holder.getBoundingClientRect().height;
        body.classList.add('qtype-drawing-qtext-dragging');
        try {
            bar.setPointerCapture(e.pointerId);
        } catch (ex) {
            // Pointer capture unsupported — the listeners below still work.
        }

        const onMove = (moveEvent) => {
            setHolderHeight(startHeight + (moveEvent.clientY - startY));
        };
        const onEnd = () => {
            bar.removeEventListener('pointermove', onMove);
            bar.removeEventListener('pointerup', onEnd);
            bar.removeEventListener('pointercancel', onEnd);
            body.classList.remove('qtype-drawing-qtext-dragging');
            persist();
        };
        bar.addEventListener('pointermove', onMove);
        bar.addEventListener('pointerup', onEnd);
        bar.addEventListener('pointercancel', onEnd);
    });

    // Keep an explicit (dragged) height within bounds when the viewport changes.
    $(window).resize(() => {
        if (!collapsed && holder.style.height) {
            setHolderHeight(parseInt(holder.style.height, 10));
        }
    });
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
    rev = config.jsrev;
    window.jQuery = window.$;
    window.colorhighlighter = config.colorhighlighter;
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
    initQuestionTextControls(config);

    // Make the existing `.touch` CSS rules in method-draw.css active so
    // menu titles render with bigger tap targets on touch devices (iPad).
    if ('ontouchstart' in window || navigator.maxTouchPoints > 0) {
        document.body.classList.add('touch');
    }

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
                const editorOverlay = document.getElementById('qtype-drawing-editor-loading');
                if (editorOverlay) {
                    editorOverlay.classList.add('is-hidden');
                    editorOverlay.setAttribute('aria-busy', 'false');
                    const removeOverlay = function() {
                        editorOverlay.style.display = 'none';
                        editorOverlay.removeEventListener('transitionend', removeOverlay);
                    };
                    editorOverlay.addEventListener('transitionend', removeOverlay);
                    setTimeout(removeOverlay, 600);
                }

                const postReadyToParent = function() {
                    try {
                        if (window.parent && window.parent !== window) {
                            window.parent.postMessage(
                                {type: 'qtype_drawing_ready'},
                                window.location.origin
                            );
                        }
                    } catch (e) {
                        // Cross-origin or detached parent — ignore.
                    }
                };
                postReadyToParent();
                setTimeout(postReadyToParent, 100);
                setTimeout(postReadyToParent, 500);
                setTimeout(postReadyToParent, 1500);

                if (config.useupdateannotationjs) {
                    window.setInterval(window.methodDraw.updateAnnotationDetails, 30000);
                }


                $('#stroke_width').val(defaultPensize);
                window.svgCanvas.setStrokeWidth(defaultPensize);

                window.svgCanvas.setColor('stroke', defaultColor);
                window.svgCanvas.setColor('fill', defaultColor);

                // If the teacher hid the drawing (pencil) tool, the canvas would otherwise start in the
                // now-invisible freehand mode. Switch to the first tool that is still visible so students
                // get a sensible, usable default instead of an active-but-hidden tool. methodDraw
                // force-selects the pencil at the very end of its init (after this ready callback), so
                // defer with a timeout to make our switch run afterwards and stick.
                if (config.enabledtools && !config.enabledtools.tooldraw) {
                    const fallbacktools = [
                        ['toolselect', '#tool_select'],
                        ['tooltext', '#tool_text'],
                        ['toolhighlighter', '#tool_highlighter'],
                        ['toolline', '#tool_line'],
                        ['toolrect', '#tool_rect'],
                        ['toolcircle', '#tool_ellipse'],
                    ];
                    setTimeout(function() {
                        for (let i = 0; i < fallbacktools.length; i++) {
                            if (config.enabledtools[fallbacktools[i][0]]) {
                                const btn = document.querySelector(fallbacktools[i][1]);
                                if (btn) {
                                    btn.click();
                                }
                                break;
                            }
                        }
                    }, 150);
                }

            });
            window.methodDraw.init();
        } else {
            co.log("methodDraw not yet initialized");
        }
    });

// Initialize methodDraw when ready

};
