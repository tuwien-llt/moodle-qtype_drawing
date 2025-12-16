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
 * @package qtype
 * @subpackage drawing
 * @copyright ETHZ LET <amr.hourani@id.ethz.ch>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/question/type/questiontypebase.php');

$id = required_param('id', PARAM_INT);
$readonly = optional_param('readonly', 0, PARAM_INT);
$stid = optional_param('stid', 0, PARAM_INT);
$attemptid = required_param('attemptid', PARAM_RAW_TRIMMED);
$uniquefieldnameattemptid = required_param('uniquefieldnameattemptid', PARAM_RAW_TRIMMED);
$sesskey = required_param('sesskey', PARAM_RAW);
$attemptcount = optional_param('attemptcount', 1, PARAM_INT);

$question = question_bank::load_question_data($id);

$cmcontext = context::instance_by_id($question->contextid);

require_login();

$PAGE->set_context($cmcontext);

$pageurl = new moodle_url('/question/type/drawing/drawingarea.php', [
    'id' => $id,
    'readonly' => $readonly,
    'stid' => $stid,
    'attemptid' => $attemptid,
    'uniquefieldnameattemptid' => $uniquefieldnameattemptid,
    'attemptcount' => $attemptcount,
    'sesskey' => sesskey(),
]);

if (!confirm_sesskey($sesskey)) {
    die("Session lost");
}

if (!$fhd = $DB->get_record('qtype_drawing', array('questionid' => $id))) {
    die("No such question.");
}

$stylesheetsurls = [
    new moodle_url('/question/type/drawing/lib/jgraduate/css/jPicker.css'),
    new moodle_url('/question/type/drawing/lib/jgraduate/css/jgraduate.css'),
    new moodle_url('/question/type/drawing/css/method-draw.css'),
    new moodle_url('/question/type/drawing/css/fonts.css'),
    //new moodle_url('/question/type/drawing/lib/jquery-ui/jquery-ui.css'),
    new moodle_url('/question/type/drawing/styles.css'),
];

$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('embedded');
foreach ($stylesheetsurls as $stylesheeturl) {
    $PAGE->requires->css_theme($stylesheeturl);
}
$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin('ui');
$PAGE->requires->jquery_plugin('ui-css');

$useupdateannotationjs = 0;
if (has_capability('mod/quiz:grade', $cmcontext) && $readonly == 1) {
    $useupdateannotationjs = 1;
}

// Prepare strings
$strings = [
    'drawingcomment' => get_string("drawingcomment", "qtype_drawing"),
    'newconfirmationmsg' => get_string("newconfirmationmsg", "qtype_drawing"),
    'eraseconfirmationmsg' => get_string("eraseconfirmationmsg", "qtype_drawing"),
    'parsingerror' => get_string("parsingerror", "qtype_drawing"),
    'ignorechanges' => get_string("ignorechanges", "qtype_drawing"),
    'ok' => get_string("ok", "qtype_drawing"),
    'cancel' => get_string("cancel", "qtype_drawing"),
    'eyedroppertool' => get_string("eyedroppertool", "qtype_drawing"),
    'shapelibrary' => get_string("shapelibrary", "qtype_drawing"),
    'drawmarkers' => get_string("drawmarkers", "qtype_drawing"),
    'solidcolor' => get_string("solidcolor", "qtype_drawing"),
    'lingrad' => get_string("lingrad", "qtype_drawing"),
    'radgrad' => get_string("radgrad", "qtype_drawing"),
    'new' => get_string("new", "qtype_drawing"),
    'current' => get_string("current", "qtype_drawing"),
    'viewgrid' => get_string("viewgrid", "qtype_drawing"),
    'annotationsaved' => get_string("annotationsaved", "qtype_drawing"),
    'saving' => get_string("saving", "qtype_drawing"),
    'saveannotation' => get_string("saveannotation", "qtype_drawing"),
    'file' => get_string('file', 'qtype_drawing'),
    'erasedrawing' => get_string('erasedrawing', 'qtype_drawing'),
    'edit' => get_string('edit', 'qtype_drawing'),
    'cut' => get_string('cut', 'qtype_drawing'),
    'copy' => get_string('copy', 'qtype_drawing'),
    'paste' => get_string('paste', 'qtype_drawing'),
    'duplicate' => get_string('duplicate', 'qtype_drawing'),
    'delete' => get_string('delete', 'qtype_drawing'),
    'object' => get_string('object', 'qtype_drawing'),
    'bringtofront' => get_string('bringtofront', 'qtype_drawing'),
    'bringforward' => get_string('bringforward', 'qtype_drawing'),
    'sendbackward' => get_string('sendbackward', 'qtype_drawing'),
    'sendtoback' => get_string('sendtoback', 'qtype_drawing'),
    'groupelements' => get_string('groupelements', 'qtype_drawing'),
    'ungroupelements' => get_string('ungroupelements', 'qtype_drawing'),
    'converttopath' => get_string('converttopath', 'qtype_drawing'),
    'reorientpath' => get_string('reorientpath', 'qtype_drawing'),
    'view' => get_string('view', 'qtype_drawing'),
    'viewrulers' => get_string('viewrulers', 'qtype_drawing'),
    'viewwireframe' => get_string('viewwireframe', 'qtype_drawing'),
    'snaptogrid' => get_string('snaptogrid', 'qtype_drawing'),
    'source' => get_string('source', 'qtype_drawing'),
    'annotation' => get_string('annotation', 'qtype_drawing'),
    'originalanswer' => get_string('originalanswer', 'qtype_drawing'),
    'studentview' => get_string('studentview', 'qtype_drawing'),
    'drawingpresets' => get_string('drawingpresets', 'qtype_drawing'),
    'color' => get_string('color', 'qtype_drawing'),
    'size' => get_string('size', 'qtype_drawing'),
    'changestroke' => get_string('changestroke', 'qtype_drawing'),
    'strokewidth' => get_string('strokewidth', 'qtype_drawing'),
    'strokedash' => get_string('strokedash', 'qtype_drawing'),
    'dashstyle' => get_string('dashstyle', 'qtype_drawing'),
    'changerotationangle' => get_string('changerotationangle', 'qtype_drawing'),
    'rotation' => get_string('rotation', 'qtype_drawing'),
    'changeopacity' => get_string('changeopacity', 'qtype_drawing'),
    'opacity' => get_string('opacity', 'qtype_drawing'),
    'changeblur' => get_string('changeblur', 'qtype_drawing'),
    'blur' => get_string('blur', 'qtype_drawing'),
    'changecornerradius' => get_string('changecornerradius', 'qtype_drawing'),
    'roundness' => get_string('roundness', 'qtype_drawing'),
    'align' => get_string('align', 'qtype_drawing'),
    'rectangle' => get_string('rectangle', 'qtype_drawing'),
    'width' => get_string('width', 'qtype_drawing'),
    'height' => get_string('height', 'qtype_drawing'),
    'path' => get_string('path', 'qtype_drawing'),
    'image' => get_string('image', 'qtype_drawing'),
    'circle' => get_string('circle', 'qtype_drawing'),
    'centerx' => get_string('centerx', 'qtype_drawing'),
    'centery' => get_string('centery', 'qtype_drawing'),
    'ellipse' => get_string('ellipse', 'qtype_drawing'),
    'radiusx' => get_string('radiusx', 'qtype_drawing'),
    'radiusy' => get_string('radiusy', 'qtype_drawing'),
    'line' => get_string('line', 'qtype_drawing'),
    'startx' => get_string('startx', 'qtype_drawing'),
    'starty' => get_string('starty', 'qtype_drawing'),
    'endx' => get_string('endx', 'qtype_drawing'),
    'endy' => get_string('endy', 'qtype_drawing'),
    'text' => get_string('text', 'qtype_drawing'),
    'font' => get_string('font', 'qtype_drawing'),
    'fontsize' => get_string('fontsize', 'qtype_drawing'),
    'group' => get_string('group', 'qtype_drawing'),
    'editpath' => get_string('editpath', 'qtype_drawing'),
    'segmenttype' => get_string('segmenttype', 'qtype_drawing'),
    'straight' => get_string('straight', 'qtype_drawing'),
    'curve' => get_string('curve', 'qtype_drawing'),
    'addnode' => get_string('addnode', 'qtype_drawing'),
    'deletenode' => get_string('deletenode', 'qtype_drawing'),
    'openpath' => get_string('openpath', 'qtype_drawing'),
    'multipleelements' => get_string('multipleelements', 'qtype_drawing'),
    'aligntoobjects' => get_string('aligntoobjects', 'qtype_drawing'),
    'aligntopage' => get_string('aligntopage', 'qtype_drawing'),
    'deleteobject' => get_string('deleteobject', 'qtype_drawing'),
    'strokejoin' => get_string('strokejoin', 'qtype_drawing'),
    'strokecap' => get_string('strokecap', 'qtype_drawing'),
    'selecttool' => get_string('selecttool', 'qtype_drawing'),
    'drawingtool' => get_string('drawingtool', 'qtype_drawing'),
    'linetool' => get_string('linetool', 'qtype_drawing'),
    'texttool' => get_string('texttool', 'qtype_drawing'),
    'recttool' => get_string('recttool', 'qtype_drawing'),
    'ellipsetool' => get_string('ellipsetool', 'qtype_drawing'),
    'pathtool' => get_string('pathtool', 'qtype_drawing'),
    'switchstrokefill' => get_string('switchstrokefill', 'qtype_drawing'),
    'changefill' => get_string('changefill', 'qtype_drawing'),
    'changestrokecolor' => get_string('changestrokecolor', 'qtype_drawing'),
    'zoomtool' => get_string('zoomtool', 'qtype_drawing'),
    'changezoom' => get_string('changezoom', 'qtype_drawing'),
    'copysvgsrc' => get_string('copysvgsrc', 'qtype_drawing'),
    'done' => get_string('done', 'qtype_drawing'),
    'applychanges' => get_string('applychanges', 'qtype_drawing'),
    'edittext' => get_string('edittext', 'qtype_drawing')
];

// Map strings for specific sub-keys (e.g. str.comment) needed by JS
$jsstrings = [
    'comment' => $strings['drawingcomment'],
    'newconfirmationmsg' => $strings['newconfirmationmsg'],
    'eraseconfirmationmsg' => $strings['eraseconfirmationmsg'],
    'parsingerror' => $strings['parsingerror'],
    'ignorechanges' => $strings['ignorechanges'],
    'ok' => $strings['ok'],
    'cancel' => $strings['cancel'],
    'eyedroppertool' => $strings['eyedroppertool'],
    'shapelibrary' => $strings['shapelibrary'],
    'drawmarkers' => $strings['drawmarkers'],
    'solidcolor' => $strings['solidcolor'],
    'lingrad' => $strings['lingrad'],
    'radgrad' => $strings['radgrad'],
    'new' => $strings['new'],
    'current' => $strings['current'],
    'viewgrid' => $strings['viewgrid'],
    'annotationsaved' => $strings['annotationsaved'],
    'saving' => $strings['saving'],
    'saveannotation' => $strings['saveannotation']
];

// Annotations
$annotationslist = [];
$fields = array('questionid' => $question->id, 'attemptid' => $attemptid, 'annotatedfor' => $stid, 'attemptcount' => $attemptcount);
if ($annotations = $DB->get_records('qtype_drawing_annotations', $fields, 'timemodified DESC')) {
    foreach ($annotations as $teacherannotation) {
        $user = $DB->get_record('user', array('id' => $teacherannotation->annotatedby));
        $annotationslist[] = [
            'id' => $teacherannotation->id,
            'userid' => $user->id,
            'username' => fullname($user),
            'date' => userdate($teacherannotation->timemodified),
            'ago' => ' (' . get_string('ago', 'core_message', format_time(time() - $teacherannotation->timemodified)) . ')'
        ];
    }
}

$context = [
    'base_url' => $CFG->wwwroot . '/question/type/drawing/',
    'id' => $id,
    'useupdateannotationjs' => ($useupdateannotationjs == 1),
    'backgroundwidth' => $fhd->backgroundwidth,
    'backgroundheight' => $fhd->backgroundheight,
    'questionembed' => $fhd->questionembed == 1,
    'questiontext' => $question-> questiontext,
    'defaultpensize' => $fhd->defaultpensize,
    'stid' => $stid,
    'attemptid' => strip_tags($attemptid),
    'uniquefieldnameattemptid' => strip_tags($uniquefieldnameattemptid),
    'sesskey' => strip_tags($sesskey),
    'attemptcount' => $attemptcount,
    'annotations_list' => $annotationslist,
    'str' => array_merge($strings, $jsstrings),
    'displaystylefull' => 'style="display: inline-block;"'
];

// Very, very dirty hack to get rid of theme css
$baseurl = preg_quote($CFG->wwwroot . '/theme/styles.php', '#');
$baseurl2 = preg_quote($CFG->wwwroot . '/theme/styles_debug.php', '#');
$pattern = '#<link\b[^>]*\bhref=(["\'])' . $baseurl . '\/[^\/]+\/[^\/]+\/all\1[^>]*>#i'; // Normal styles.
$pattern2 = '#<link\b[^>]*\bhref=(["\'])' . $baseurl2 . '[^>]*>#i'; // Debug styles.
$headerhtml = $OUTPUT->header();
$cleanheaderhtml = preg_replace($pattern, '', $headerhtml);
$cleanheaderhtml = preg_replace($pattern2, '', $cleanheaderhtml);
echo $cleanheaderhtml;

echo $OUTPUT->render_from_template('qtype_drawing/drawingarea', $context);

echo $OUTPUT->footer();