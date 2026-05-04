
// qtype_drawing local fix: type-aware pageY reader. The original code
// relied on the global `isTouch` flag plus `e.pageY` (which old jQuery
// does not populate on TouchEvents), producing NaN values on touch taps.
function _qtypeDrawingGetPageY(e) {
  var oe = e && e.originalEvent;
  if (oe) {
    if (oe.touches && oe.touches.length) return oe.touches[0].pageY;
    if (oe.changedTouches && oe.changedTouches.length) return oe.changedTouches[0].pageY;
  }
  return e.pageY;
}

$.fn.dragInput = function(cfg){
  return this.each(function(){

    this.repeating = false;
    // Apply specified options or defaults:
    // (Ought to refactor this some day to use $.extend() instead)
    this.dragCfg = {
      min: cfg && !isNaN(parseFloat(cfg.min)) ? Number(cfg.min) : null, // Fixes bug with min:0
      max: cfg && !isNaN(parseFloat(cfg.max)) ? Number(cfg.max) : null,
      step: cfg && Number(cfg.step) ? cfg.step : 1,
      stepfunc: cfg && cfg.stepfunc ? cfg.stepfunc : false,
      dragAdjust: cfg && cfg.dragAdjust ? cfg.dragAdjust : 1,
      height: 70,
      cursor: cfg && cfg.cursor ? Boolean(cfg.cursor) : false,
      start: cfg && cfg.start ? Number(cfg.start) : 0,
      _btn_width: 20,
      _direction: null,
      _delay: null,
      _repeat: null,
      callback: cfg && cfg.callback ? cfg.callback : null
    };
    // if a smallStep isn't supplied, use half the regular step
    this.dragCfg.smallStep = cfg && cfg.smallStep ? cfg.smallStep : this.dragCfg.step/2;
    var dragAdjust = this.dragCfg.dragAdjust;
    var $label = $(this).parent();
    var $input = $(this);
    var cursorHeight = this.dragCfg.height;
    var min = this.dragCfg.min;
    var max = this.dragCfg.max
    var step = this.dragCfg.step
    var area = (max - min > 0) ?  (max - min) / step : 200;
    var scale = area/cursorHeight * step;
    var lastY = 0;
    var attr = this.getAttribute("data-attr");
    var canvas = methodDraw.canvas
    var isTouch = svgedit.browser.isTouch();
    var completed = true //for mousewheel
    var $cursor = (area && this.dragCfg.cursor)
      ? $("<div class='draginput_cursor' />").appendTo($label)
      : false
    $input.attr("readonly", "readonly")
    if ($cursor && !isNaN(this.dragCfg.start)) $cursor.css("top", (this.dragCfg.start*-1)/scale+cursorHeight)

    //this is where all the magic happens
    this.adjustValue = function(i, completed){
      var v;
      i = parseFloat(i);
      if(isNaN(this.value)) {
        v = this.dragCfg.reset;
      } else if($.isFunction(this.dragCfg.stepfunc)) {
        v = this.dragCfg.stepfunc(this, i);
      } else {
        v = Number((Number(this.value) + Number(i)).toFixed(5));
      }
      if (max !== null) v = Math.min(v, max);
      if (min !== null) v = Math.max(v, min);
      if ($cursor) this.updateCursor(v);
      this.value = v;
      $label.attr("data-value", v)
      if ($.isFunction(this.dragCfg.callback)) this.dragCfg.callback(this, completed)
    };

    $label.toggleClass("draginput", $label.is("label"))

    // when the mouse is down and moving
    this.move = function(e, oy, val) {
      var pageY = _qtypeDrawingGetPageY(e);
      // just got started let's save for undo purposes
      if (lastY === 0) {
        lastY = oy;
      }
      var deltaY = (pageY - lastY) *-1
      lastY = pageY;
      val = (deltaY * scale) * dragAdjust
      var fixed = (step < 1) ? 1 : 0
      this.adjustValue(val.toFixed(fixed))  //no undo true
    };

    //when the mouse is released
    this.stop = function() {
      var selectedElems = canvas.getSelectedElems();
      $('body').removeClass('dragging');
      $label.removeClass("active");
      completed = true;
      // qtype_drawing local fix: unbind every variant so cleanup is
      // correct regardless of which path launch() took.
      $(window).unbind("touchmove.draginput touchend.draginput "
                     + "mousemove.draginput mouseup.draginput");
      //
      lastY = 0;
      if (selectedElems[0]) {
        var batchCmd = canvas.undoMgr.finishUndoableChange();
        if (!batchCmd.isEmpty()) canvas.undoMgr.addCommandToHistory(batchCmd);
      }
      this.adjustValue(0, completed)
    }

    this.updateCursor = function(){
      var value = parseFloat(this.value);
      var pos = (value*-1)/scale+cursorHeight;
      $cursor.css("top", pos);
    }

    this.launch = function(e) {
      var selectedElems = canvas.getSelectedElems();
      var oy = _qtypeDrawingGetPageY(e);
      var val = this.value;
      var el = this;
      canvas.undoMgr.beginUndoableChange(attr, selectedElems)
      $('body').addClass('dragging');

      $label.addClass('active');
      // qtype_drawing local fix: pick listener pair from the actual
      // triggering event type, not the unreliable global isTouch flag.
      // The touchmove handler also preventDefaults so the browser does
      // not page-scroll while the user drags up/down on the input.
      var triggeredByTouch = !!(e.originalEvent &&
          (e.originalEvent.touches || e.originalEvent.changedTouches));
      if (triggeredByTouch) {
          $(window).bind("touchmove.draginput", function(e){
              e.preventDefault();
              el.move(e, oy, parseFloat(val));
          });
          $(window).bind("touchend.draginput", function(e){
              el.focus();
              el.stop();
          });
      } else {
          $(window).bind("mousemove.draginput", function(e){ el.move(e, oy, parseFloat(val)); });
          $(window).bind("mouseup.draginput", function(e){
              el.focus();
              el.stop();
          });
      }

    }
// .attr("readonly", "readonly")
    $(this)
      .attr("data-scale", scale)
      .attr("data-domain", cursorHeight)
      .attr("data-cursor", ($cursor != false))

    .bind("mousedown touchstart", function(e){ //touchstart
      // qtype_drawing local fix: avoid double-launching when both
      // touchstart and the browser's compat mousedown fire for the
      // same tap. $label gets `active` only while a drag is live.
      if ($(this).parent().hasClass('active')) return;
      // qtype_drawing local fix: on touchstart, suppress the browser's
      // compat mouse events so only one launch fires per tap and the
      // browser does not also start a page-scroll for this gesture.
      if (e.type === 'touchstart') {
        e.preventDefault();
      }
      this.blur();
      this.launch(e);
    })

    .bind("dblclick taphold", function(e) {
      this.removeAttribute("readonly", "readonly");
      this.focus();
      this.select();
    })

    .keydown(function(e){
      // Respond to up/down arrow keys.
      switch(e.keyCode){
        case 13: this.adjustValue(0); this.blur();  break; // Enter
      }
    })

    .focus(function(e){
      if (this.getAttribute("readonly") === "readonly") this.blur()
    })

    .blur(function(e){
      this.setAttribute("readonly", "readonly")
    })

    .bind("mousewheel", function(e, delta, deltaX, deltaY){
      var selectedElems = canvas.getSelectedElems();
      if (completed) canvas.undoMgr.beginUndoableChange(attr, selectedElems)
      completed = false
      clearTimeout(window.undoTimeout)
      window.undoTimeout = setTimeout(function(){
        wheel_input.stop()
      },200)

      var wheel_input = this;
      if (deltaY > 0)
        this.adjustValue(this.dragCfg.step);
      else if (deltaY < 0)
        this.adjustValue(-this.dragCfg.step);
      e.preventDefault();

    })

  });

};

// public function
$.fn.dragInput.updateCursor = function(el){
  var value = parseFloat(el.value);
  var scale = parseFloat(el.getAttribute("data-scale"));
  var domain = parseFloat(el.getAttribute("data-domain"));
  var pos = ((value*-1)/scale+domain) + "px";
  var cursor = el.parentNode.lastChild
  if (cursor.className == "draginput_cursor") cursor.style.top = pos;
}

