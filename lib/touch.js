// Local modification for qtype_drawing: state machine to avoid stroke
// artifacts when a second finger arrives on the canvas. Pen events
// bypass this shim entirely (they fire as PointerEvent/MouseEvent on
// the Wacom One 13, never as TouchEvent).
(function() {

    // modern Chrome requires { passive: false } when adding event
    var supportsPassive = false;
    try {
        window.addEventListener("test", null, Object.defineProperty({}, 'passive', {
            get: function() { supportsPassive = true; }
        }));
    } catch (e) {}
    var moveOpt = supportsPassive ? { passive: false } : false;
    var captureNonPassive = supportsPassive ? { capture: true, passive: false } : true;

    // Tunables.
    var DRAW_DELAY_MS = 100;          // Delay before a finger touch becomes a draw.
    var MOVE_THRESHOLD_PX = 5;        // Movement during pending that escapes the delay.

    // States: 'idle' | 'pending' | 'drawing' | 'ignored'.
    //   pending  = first finger down, waiting to see if a 2nd arrives.
    //   drawing  = mousedown synthesised, stroke in progress.
    //   ignored  = multi-finger gesture detected, swallow until all up.
    var state = 'idle';
    var pendingTimer = null;
    var pendingTouch = null;

    function isOnCanvas(target) {
        if (!target || typeof target.closest !== 'function') {
            return false;
        }
        // Anything inside the SVG drawing surface gets the delay.
        return target.closest('#svgcanvas') !== null
            || target.closest('svg') !== null;
    }

    function dispatchMouse(type, touch) {
        if (!touch || !touch.target) {
            return;
        }
        var newEvt = document.createEvent("MouseEvents");
        newEvt.initMouseEvent(type, true, true, window, 0,
            touch.screenX, touch.screenY, touch.clientX, touch.clientY,
            false, false, false, false, 0, null);
        touch.target.dispatchEvent(newEvt);
    }

    function clearPending() {
        if (pendingTimer !== null) {
            clearTimeout(pendingTimer);
            pendingTimer = null;
        }
        pendingTouch = null;
    }

    function touchHandler(evt) {
        var touch = evt.changedTouches && evt.changedTouches[0];
        var totalTouches = evt.touches ? evt.touches.length : 0;

        if (evt.type === 'touchstart') {
            if (state === 'idle') {
                if (totalTouches >= 2) {
                    state = 'ignored';
                    return;
                }
                if (isOnCanvas(touch && touch.target)) {
                    // On-canvas: take over. Suppress compat mouse events so
                    // they don't fire alongside our synthesised ones — that
                    // double-click was breaking menus and forcing path selects
                    // into node-edit mode.
                    evt.preventDefault();
                    pendingTouch = touch;
                    state = 'pending';
                    pendingTimer = setTimeout(function() {
                        if (state === 'pending') {
                            var t = pendingTouch;
                            pendingTimer = null;
                            pendingTouch = null;
                            state = 'drawing';
                            dispatchMouse('mousedown', t);
                        }
                    }, DRAW_DELAY_MS);
                }
                // Off-canvas (toolbars, menus, scrollbars): let the browser's
                // native compat events handle the tap/drag. State stays idle.
                return;
            }
            if (state === 'pending') {
                // 2nd finger arrived during the delay window — never start the stroke.
                clearPending();
                state = 'ignored';
                return;
            }
            if (state === 'drawing' && totalTouches >= 2) {
                // 2nd finger arrived after stroke started — close the stroke cleanly.
                dispatchMouse('mouseup', touch);
                state = 'ignored';
                return;
            }
            // 'ignored', or 'drawing' with totalTouches still 1: swallow.
            return;
        }

        if (evt.type === 'touchmove') {
            if (state === 'pending') {
                if (totalTouches >= 2) {
                    clearPending();
                    state = 'ignored';
                    return;
                }
                if (!pendingTouch || !touch) {
                    return;
                }
                var dx = touch.clientX - pendingTouch.clientX;
                var dy = touch.clientY - pendingTouch.clientY;
                if (dx * dx + dy * dy > MOVE_THRESHOLD_PX * MOVE_THRESHOLD_PX) {
                    // User started moving fast — commit the stroke now so we don't
                    // lose the start of a quick flick.
                    var startTouch = pendingTouch;
                    clearPending();
                    state = 'drawing';
                    evt.preventDefault();
                    dispatchMouse('mousedown', startTouch);
                    dispatchMouse('mousemove', touch);
                }
                return;
            }
            if (state === 'drawing') {
                if (totalTouches > 1) {
                    return;
                }
                evt.preventDefault();
                dispatchMouse('mousemove', touch);
                return;
            }
            return;
        }

        if (evt.type === 'touchend' || evt.type === 'touchcancel') {
            if (state === 'pending') {
                if (totalTouches === 0) {
                    // Tap — fire mousedown + mouseup so tools that key off
                    // a click (text tool, etc.) get the full sequence.
                    var tapTouch = pendingTouch;
                    clearPending();
                    state = 'idle';
                    if (tapTouch) {
                        dispatchMouse('mousedown', tapTouch);
                        dispatchMouse('mouseup', tapTouch);
                    }
                }
                return;
            }
            if (state === 'drawing') {
                if (totalTouches === 0) {
                    state = 'idle';
                    dispatchMouse('mouseup', touch);
                }
                return;
            }
            if (state === 'ignored') {
                if (totalTouches === 0) {
                    state = 'idle';
                }
                return;
            }
            return;
        }
        // doubletap or any other event: no-op.
    }

    document.addEventListener("touchstart", touchHandler, captureNonPassive);
    document.addEventListener("touchmove", touchHandler, moveOpt);
    document.addEventListener("touchend", touchHandler, captureNonPassive);
    document.addEventListener("touchcancel", touchHandler, captureNonPassive);
    document.addEventListener("doubletap", touchHandler, captureNonPassive);

})();
