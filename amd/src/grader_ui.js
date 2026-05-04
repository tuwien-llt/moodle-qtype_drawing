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
import Modal from 'core/modal';

let dirty = false;
let bypassGuard = false;
let baselineUndoSize = null;

const VIEWMODE_KEY = 'qtype_drawing_grader_viewmode';
const VIEWMODES = ['sidebar', 'both', 'canvas'];

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

    // Loading overlay — must be wired first so the postMessage listener
    // is armed before the iframe has a chance to fire its ready signal.
    initLoadingOverlay();

    // Quick grade buttons.
    initQuickGradeButtons(maxMark);

    // Form validation.
    initFormValidation(maxMark);

    // Save and next button handler.
    initSaveNextButton();

    // Response history modal trigger.
    initResponseHistoryModal();

    // Track form changes for the unsaved-changes guard.
    initDirtyTracking();

    // Intercept nav-link clicks when there are unsaved changes.
    initNavigationGuard();

    // Keyboard shortcuts.
    initKeyboardShortcuts();

    // Layout view mode toggles.
    initViewModeToggle();

    // Per-student attempt selector.
    initAttemptDropdown();

    // Fullscreen toggle for the feedback editor.
    initFeedbackFullscreen();
};

/**
 * Hide the grader loading overlay once the iframe signals readiness, with a
 * fallback timer so a broken script load can never trap the teacher.
 */
const initLoadingOverlay = () => {
    const overlay = document.getElementById('qtype-drawing-grader-loading');
    if (!overlay) {
        return;
    }

    let hidden = false;
    const hide = () => {
        if (hidden) {
            return;
        }
        hidden = true;
        overlay.classList.add('is-hidden');
        overlay.setAttribute('aria-busy', 'false');
        const removeOverlay = () => {
            overlay.style.display = 'none';
            overlay.removeEventListener('transitionend', removeOverlay);
        };
        overlay.addEventListener('transitionend', removeOverlay);
        // Belt-and-braces in case transitionend never fires.
        setTimeout(removeOverlay, 600);
    };

    window.addEventListener('message', (event) => {
        if (event.origin !== window.location.origin) {
            return;
        }
        if (event.data && event.data.type === 'qtype_drawing_ready') {
            hide();
        }
    });

    // Backup: poll for the iframe's svgCanvas in case the postMessage races us.
    // Same-origin iframe so contentWindow access is safe.
    const pollIntervalMs = 200;
    const pollDeadline = performance.now() + 14000;
    const pollIframeReady = () => {
        if (hidden) {
            return;
        }
        const iframe = document.querySelector('.qtype-drawing-grader-canvas iframe');
        try {
            if (iframe && iframe.contentWindow && iframe.contentWindow.svgCanvas) {
                hide();
                return;
            }
        } catch (e) {
            // Cross-origin (shouldn't happen) — fall through to fallback timeout.
        }
        if (performance.now() < pollDeadline) {
            setTimeout(pollIframeReady, pollIntervalMs);
        }
    };
    setTimeout(pollIframeReady, pollIntervalMs);

    // Fallback so a broken iframe never traps the teacher.
    setTimeout(hide, 15000);
};

/**
 * Toggle a fullscreen mode for the feedback editor wrapper, similar to
 * the assignment grader. Esc exits fullscreen.
 */
const initFeedbackFullscreen = () => {
    const btn = document.querySelector('.qtype-drawing-feedback-fullscreen-btn');
    const wrap = document.querySelector('.qtype-drawing-feedback-wrap');
    if (!btn || !wrap) {
        return;
    }
    const icon = btn.querySelector('i');

    const setExpanded = (expanded) => {
        wrap.classList.toggle('editor-fullscreen', expanded);
        btn.setAttribute('aria-pressed', expanded ? 'true' : 'false');
        if (icon) {
            icon.classList.toggle('fa-expand', !expanded);
            icon.classList.toggle('fa-compress', expanded);
        }
    };

    btn.addEventListener('click', () => {
        setExpanded(!wrap.classList.contains('editor-fullscreen'));
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && wrap.classList.contains('editor-fullscreen')) {
            setExpanded(false);
        }
    });
};

/**
 * Wire up the per-student attempt dropdown. On change, reuse the existing
 * dirty-tracking + unsaved-changes modal so teachers don't lose work
 * when switching to a different attempt of the same student.
 */
const initAttemptDropdown = () => {
    const select = document.getElementById('grader-attempt-select');
    if (!select) {
        return;
    }
    let lastValue = select.value;

    const goTo = (option) => {
        const url = option && option.dataset.url;
        if (url) {
            window.location.href = url;
        }
    };

    select.addEventListener('change', async() => {
        const newOption = select.options[select.selectedIndex];
        if (bypassGuard || !isDirty()) {
            lastValue = select.value;
            goTo(newOption);
            return;
        }
        const action = await showUnsavedChangesModal();
        if (action === 'cancel') {
            select.value = lastValue;
            return;
        }
        if (action === 'discard') {
            bypassGuard = true;
            goTo(newOption);
            return;
        }
        if (action === 'save') {
            const form = document.getElementById('qtype-drawing-grade-form');
            const returnurlField = document.getElementById('returnurl-field');
            if (form && returnurlField && newOption) {
                returnurlField.value = newOption.dataset.url;
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        }
    });
};

/**
 * Apply a view mode by toggling classes on the grader container and the
 * three toggle buttons.
 *
 * @param {string} mode One of 'sidebar', 'both', 'canvas'.
 */
const applyViewMode = (mode) => {
    const container = document.querySelector('.qtype-drawing-grader-fullscreen');
    if (!container) {
        return;
    }
    VIEWMODES.forEach(m => container.classList.remove('viewmode-' + m));
    if (mode !== 'both') {
        container.classList.add('viewmode-' + mode);
    }
    document.querySelectorAll('.qtype-drawing-grader-viewmode-btn').forEach(btn => {
        const isActive = btn.dataset.viewmode === mode;
        btn.classList.toggle('active', isActive);
        btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
};

/**
 * Wire up the three view-mode toggle buttons. Persists the selected mode
 * in localStorage so it survives navigation between attempts.
 */
const initViewModeToggle = () => {
    const buttons = document.querySelectorAll('.qtype-drawing-grader-viewmode-btn');
    if (!buttons.length) {
        return;
    }
    let saved = 'both';
    try {
        const v = window.localStorage.getItem(VIEWMODE_KEY);
        if (VIEWMODES.includes(v)) {
            saved = v;
        }
    } catch (e) {
        // localStorage unavailable (private mode etc.) — fall back to default.
    }
    applyViewMode(saved);
    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            const mode = btn.dataset.viewmode;
            if (!VIEWMODES.includes(mode)) {
                return;
            }
            applyViewMode(mode);
            try {
                window.localStorage.setItem(VIEWMODE_KEY, mode);
            } catch (e) {
                // Ignore.
            }
        });
    });
};

/**
 * Wire the "View response history" button to open the hidden history block in a Moodle modal.
 */
const initResponseHistoryModal = () => {
    const btn = document.querySelector('.qtype-drawing-grader-history-btn');
    const content = document.querySelector('.qtype-drawing-grader-history-content');
    if (!btn || !content) {
        return;
    }

    btn.addEventListener('click', () => {
        Modal.create({
            title: '',
            body: content.innerHTML,
            large: true,
            show: true,
            removeOnClose: true,
        });
    });
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
            dirty = true;

            // Visual feedback.
            buttons.forEach(b => b.classList.remove('active'));
            button.classList.add('active');
        });
    });
};

const initDirtyTracking = () => {
    const markInput = document.getElementById('grade-mark');
    const commentInput = document.getElementById('grade-comment');
    if (markInput) {
        markInput.addEventListener('input', () => {
            dirty = true;
        });
    }
    if (commentInput) {
        // Atto/TinyMCE write back to the underlying textarea via 'change';
        // plain editor still fires 'input'. Listen for both.
        ['input', 'change'].forEach(evt => {
            commentInput.addEventListener(evt, () => {
                dirty = true;
            });
        });
    }

    // Snapshot svg-edit's undo stack size lazily, on the first real user
    // interaction with the iframe. Capturing earlier (right after methodDraw
    // boots) is unreliable because methodDraw can push commands onto the
    // stack asynchronously during init, leaving us with a stale baseline and
    // false-positive dirty.
    const iframe = document.querySelector('.qtype_drawing_drawingwrapper iframe');
    if (!iframe) {
        return;
    }

    const attachInteractionCapture = () => {
        const doc = iframe.contentDocument;
        if (!doc) {
            return;
        }
        const onFirstInteraction = () => {
            const mgr = getUndoMgr();
            if (mgr) {
                baselineUndoSize = mgr.getUndoStackSize();
            } else {
                baselineUndoSize = 0;
            }
            doc.removeEventListener('pointerdown', onFirstInteraction, true);
            doc.removeEventListener('keydown', onFirstInteraction, true);
        };
        doc.addEventListener('pointerdown', onFirstInteraction, true);
        doc.addEventListener('keydown', onFirstInteraction, true);
    };

    if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
        attachInteractionCapture();
    } else {
        iframe.addEventListener('load', attachInteractionCapture);
    }
};

const getUndoMgr = () => {
    const iframe = document.querySelector('.qtype_drawing_drawingwrapper iframe');
    if (!iframe) {
        return null;
    }
    try {
        const win = iframe.contentWindow;
        if (win && win.svgCanvas && win.svgCanvas.undoMgr &&
                typeof win.svgCanvas.undoMgr.getUndoStackSize === 'function') {
            return win.svgCanvas.undoMgr;
        }
    } catch (e) {
        // Cross-origin / not ready.
    }
    return null;
};

const isDirty = () => {
    if (dirty) {
        return true;
    }
    if (baselineUndoSize !== null) {
        const mgr = getUndoMgr();
        if (mgr && mgr.getUndoStackSize() > baselineUndoSize) {
            return true;
        }
    }
    return false;
};

const showUnsavedChangesModal = async() => {
    const [titleStr, msgStr, cancelStr, discardStr, saveStr] = await Promise.all([
        getString('unsavedchanges', 'qtype_drawing'),
        getString('unsavedchangesmessage', 'qtype_drawing'),
        getString('cancel'),
        getString('discardchanges', 'qtype_drawing'),
        getString('savechanges'),
    ]);
    const footer =
        '<button type="button" class="btn btn-secondary" data-action="cancel">' + cancelStr + '</button>' +
        '<button type="button" class="btn btn-outline-danger ms-2" data-action="discard">' + discardStr + '</button>' +
        '<button type="button" class="btn btn-primary ms-2" data-action="save">' + saveStr + '</button>';
    const modal = await Modal.create({
        title: titleStr,
        body: '<p>' + msgStr + '</p>',
        footer: footer,
        large: false,
        show: true,
        removeOnClose: true,
    });
    return new Promise(resolve => {
        const root = modal.getRoot()[0];
        let resolved = false;
        const finish = (action) => {
            if (resolved) {
                return;
            }
            resolved = true;
            modal.hide();
            resolve(action);
        };
        root.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-action]');
            if (!btn) {
                return;
            }
            finish(btn.dataset.action);
        });
        modal.getRoot().on('hidden.bs.modal', () => finish('cancel'));
    });
};

const initNavigationGuard = () => {
    const navLinks = document.querySelectorAll('.qtype-drawing-grader-nav a[href]');
    const form = document.getElementById('qtype-drawing-grade-form');
    const returnurlField = document.getElementById('returnurl-field');

    navLinks.forEach(link => {
        link.addEventListener('click', async(e) => {
            if (bypassGuard || !isDirty()) {
                return;
            }
            e.preventDefault();
            const action = await showUnsavedChangesModal();
            if (action === 'cancel') {
                return;
            }
            if (action === 'discard') {
                bypassGuard = true;
                window.location.href = link.href;
                return;
            }
            if (action === 'save' && form && returnurlField) {
                returnurlField.value = link.getAttribute('href');
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
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
