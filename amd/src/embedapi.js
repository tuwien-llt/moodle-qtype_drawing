/* eslint-disable */
/*
function embedded_svg_edit(frame){
  //initialize communication
  this.frame = frame;
  this.stack = []; //callback stack

  var editapi = this;

  window.addEventListener("message", function(e){
    if(e.data.substr(0,5) == "ERROR"){
      editapi.stack.splice(0,1)[0](e.data,"error")
    }else{
      editapi.stack.splice(0,1)[0](e.data)
    }
  }, false)
}

embedded_svg_edit.prototype.call = function(code, callback){
  this.stack.push(callback);
  this.frame.contentWindow.postMessage(code,"*");
}

embedded_svg_edit.prototype.getSvgString = function(callback){
  this.call("svgCanvas.getSvgString()",callback)
}

embedded_svg_edit.prototype.setSvgString = function(svg){
  this.call("svgCanvas.setSvgString('"+svg.replace(/'/g, "\\'")+"')");
}
*/


/*
Embedded SVG-edit API

General usage:
- Have an iframe somewhere pointing to a version of svg-edit > r1000
- Initialize the magic with:
var svgCanvas = new embedded_svg_edit(window.frames['svgedit']);
- Pass functions in this format:
svgCanvas.setSvgString("string")
- Or if a callback is needed:
svgCanvas.setSvgString("string")(function(data, error){
  if(error){
    //there was an error
  }else{
    //handle data
  }
})

Everything is done with the same API as the real svg-edit,
and all documentation is unchanged. The only difference is
when handling returns, the callback notation is used instead.

var blah = new embedded_svg_edit(window.frames['svgedit']);
blah.clearSelection("woot","blah",1337,[1,2,3,4,5,"moo"],-42,{a: "tree",b:6, c: 9})(function(){console.log("GET DATA",arguments)})
*/
// eslint-disable-next-line no-control-regex
// eslint-disable-next-line no-misleading-character-class
const regexrepl = new RegExp(
    '[' +
    '\\\\\\"' + // Matches \ and "
    '\\x00-\\x1f' + // Control characters
    '\\x7f-\\x9f' + // Delete & C1 controls
    '\\u00ad' + // Soft hyphen
    '\\u0600-\\u0604' + // Arabic marks
    '\\u070f' + // Syriac mark
    '\\u17b4\\u17b5' + // Khmer vowels (Combining marks)
    '\\u200c-\\u200f' + // Format marks
    '\\u2028-\\u202f' + // Line/Para separators
    '\\u2060-\\u206f' + // General punctuation
    '\\ufeff' + // BOM
    '\\ufff0-\\uffff' + // Specials
    ']',
    'g'
);
// eslint-disable-next-line no-unused-vars
const co = window.console;
export default class embedded_svg_edit {
    constructor(frame) {
        //initialize communication
        this.frame = frame;
        //this.stack = [] //callback stack
        this.callbacks = {}; //successor to stack
        this.encode = embedded_svg_edit.encode;
        //Newer, well, it extracts things that aren't documented as well. All functions accessible through
        // the normal thingy can now be accessed though the API
        //var functions=[];for(var i in svgCanvas){if(typeof svgCanvas[i] == "function"){l.push(i)}};
        //run in svgedit itself
        const functions = [
            "updateElementFromJson", "embedImage", "fixOperaXML", "clearSelection", "addToSelection",
            "removeFromSelection", "addNodeToSelection", "open", "save", "getSvgString", "setSvgString",
            "setSvgStringEraser", "createLayer", "deleteCurrentLayer", "getCurrentDrawing", "setCurrentLayer",
            "renameCurrentLayer", "setCurrentLayerPosition", "setLayerVisibility", "moveSelectedToLayer", "clear",
            "clearPath", "getNodePoint", "clonePathNode", "deletePathNode", "getResolution", "getImageTitle",
            "setImageTitle", "setResolution", "setBBoxZoom", "setZoom", "getMode", "setMode", "getStrokeColor",
            "setStrokeColor", "getFillColor",
            "setFillColor", "setStrokePaint", "setFillPaint", "getStrokeWidth", "setStrokeWidth", "getStrokeStyle",
            "setStrokeStyle", "getOpacity", "setOpacity", "getFillOpacity", "setFillOpacity",
            "getStrokeOpacity", "setStrokeOpacity", "getTransformList", "getBBox", "getRotationAngle",
            "setRotationAngle", "each",
            "bind", "setIdPrefix", "getBold", "setBold", "getItalic", "setItalic", "getFontFamily", "setFontFamily",
            "getFontSize",
            "setFontSize", "getText", "setTextContent", "notifyTextchange", "addCommandToHistory", "getDOMContainer",
            "setImageURL", "setRectRadius", "setSegType", "quickClone",
            "changeSelectedAttributeNoUndo", "changeSelectedAttribute", "deleteSelectedElements",
            "groupSelectedElements",
            "ungroupSelectedElement", "moveToTopSelectedElement", "moveToBottomSelectedElement", "moveSelectedElements",
            "getStrokedBBox", "getVisibleElements", "cycleElement", "getUndoStackSize", "getRedoStackSize",
            "getNextUndoCommandText",
            "getNextRedoCommandText", "undo", "redo", "cloneSelectedElements", "getSelectedElems", "setCursor",
            "alignSelectedElements", "getZoom", "getVersion",
            "setIconSize", "setLang", "setCustomHandlers", "updateCanvas", "setFHDBackground", "setBackground",
            "getFontColor", "setFontColor", "SaveDrawingToMoodle", "getHDQuestionID", "setHDQuestionID", "getElem",
            "getDOMContainer", "getDOMDocument", "assignAttributes", "transformPoint"
        ];

        // Generate proxy methods
        for (let i = 0; i < functions.length; i++) {
            this[functions[i]] = ((d) => {
                return function () {
                    const t = this; //new callback
                    const args = [];
                    for (let g = 0; g < arguments.length; g++) {
                        args.push(arguments[g]);
                    }
                    const cbid = t.send(d, args, function () {
                    }); //the callback (currently it's nothing, but will be set later!

                    return function (newcallback) {
                        t.callbacks[cbid] = newcallback; //set callback
                    };
                };
            })(functions[i]);
        }

        //TODO: use AddEvent for Trident browsers, currently they dont support SVG, but they do support onmessage
        const t = this;
        window.addEventListener("message", function (e) {
            if (e.data.substr(0, 4) == "SVGe") { //because svg-edit is too longish
                const data = e.data.substr(4);
                const cbid = data.substr(0, data.indexOf(";"));
                if (t.callbacks[cbid]) {
                    if (data.substr(0, 6) != "error:") {
                        co.log("Running eval for: ", "(" + data.substr(cbid.length + 1) + ")");
                        // eslint-disable-next-line no-eval
                        t.callbacks[cbid](eval("(" + data.substr(cbid.length + 1) + ")"));
                    } else {
                        t.callbacks[cbid](data, "error");
                    }
                }
            }
            //this.stack.shift()[0](e.data,e.data.substr(0,5) == "ERROR"?'error':null) //replace with shift
        }, false);
    }

    static encode(obj) {
        //simple partial JSON encoder implementation
        if (window.JSON && JSON.stringify) {
            return JSON.stringify(obj);
        }
        // eslint-disable-next-line no-caller
        const enc = arguments.callee; //for purposes of recursion

        if (typeof obj == "boolean" || typeof obj == "number") {
            return obj + ''; // Should work...
        } else if (typeof obj == "string") {
            //a large portion of this is stolen from Douglas Crockford's json2.js
            return '"' +
                obj.replace(
                    regexrepl
                    , function (a) {
                        return '\\u' + ('0000' + a.charCodeAt(0).toString(16)).slice(-4);
                    })
                + '"'; //note that this isn't quite as purtyful as the usualness
        } else if (obj.length) { //simple hackish test for arrayish-ness
            for (let i = 0; i < obj.length; i++) {
                obj[i] = enc(obj[i]); //encode every sub-thingy on top
            }
            return "[" + obj.join(",") + "]";
        } else {
            const pairs = []; //pairs will be stored here
            for (const k in obj) { //loop through thingys
                pairs.push(enc(k) + ":" + enc(obj[k])); //key: value
            }
            return "{" + pairs.join(",") + "}"; //wrap in the braces
        }
    }

    send(name, args, callback) {
        const cbid = Math.floor(Math.random() * 31776352877 + 993577).toString();
        //this.stack.push(callback);
        this.callbacks[cbid] = callback;
        const argstr = [];
        for (let i = 0; i < args.length; i++) {
            argstr.push(this.encode(args[i]));
        }
        const t = this;
        setTimeout(function () {//delay for the callback to be set in case its synchronous
            t.frame.contentWindow.postMessage(cbid + ";svgCanvas['" + name + "'](" + argstr.join(",") + ")", "*");
        }, 0);
        return cbid;
        //this.stack.shift()("svgCanvas['"+name+"']("+argstr.join(",")+")")
    }
}

// Named export as well for flexibility
export {embedded_svg_edit};

// Backwards compatibility: expose to global scope if available
if (typeof window !== 'undefined') {
    window.embedded_svg_edit = embedded_svg_edit;
}
