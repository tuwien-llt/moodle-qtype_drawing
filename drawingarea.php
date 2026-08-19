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
require_once(__DIR__ . '/questiontype.php');

$id = required_param('id', PARAM_INT);
$readonly = optional_param('readonly', 0, PARAM_INT);
$stid = optional_param('stid', 0, PARAM_INT);
$attemptid = required_param('attemptid', PARAM_RAW_TRIMMED);
$uniquefieldnameattemptid = required_param('uniquefieldnameattemptid', PARAM_RAW_TRIMMED);
$sesskey = required_param('sesskey', PARAM_RAW);
$attemptcount = optional_param('attemptcount', 1, PARAM_INT);
$qubaid = optional_param('qubaid', 0, PARAM_INT);
$slot = optional_param('slot', 0, PARAM_INT);

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

if (!$fhd = $DB->get_record('qtype_drawing', ['questionid' => $id])) {
    die("No such question.");
}

// Questions created before the 2025120100 upgrade have NULL in the option columns added by it
// (defaultpensize, colorsjson, colorhighlighter): the upgrade did not backfill existing rows.
// Fall back to the admin defaults at display time so those questions keep loading. empty()
// also covers '' and the 0 an XML import of such a question stores in the int column. The
// hardcoded values behind the config are a last resort for an unconfigured/broken config.
$drawingconfig = get_config('qtype_drawing');
if (empty($fhd->defaultpensize)) {
    $fhd->defaultpensize = empty($drawingconfig->defaultpensize) ? 1 : $drawingconfig->defaultpensize;
}
if (empty($fhd->colorhighlighter)) {
    $fhd->colorhighlighter = empty($drawingconfig->colorhighlighter) ? '#ff0' : $drawingconfig->colorhighlighter;
}

$stylesheetsurls = [
    new moodle_url('/question/type/drawing/lib/jgraduate/css/jPicker.css'),
    new moodle_url('/question/type/drawing/lib/jgraduate/css/jgraduate.css'),
    new moodle_url('/question/type/drawing/css/method-draw.css'),
    new moodle_url('/question/type/drawing/css/fonts.css'),
    new moodle_url('/question/type/drawing/styles.css'),
];

$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('embedded');

foreach ($stylesheetsurls as $stylesheeturl) {
    $stylesheeturl->param('rev', $CFG->themerev);
    $PAGE->requires->css_theme($stylesheeturl);
}
$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin('ui');
$PAGE->requires->jquery_plugin('ui-css');

$useupdateannotationjs = 0;
if (has_capability('mod/quiz:grade', $cmcontext) && $readonly == 1) {
    $useupdateannotationjs = 1;
}


// Annotations.
$annotationslist = [];
$fields = ['questionid' => $question->id, 'attemptid' => $attemptid, 'annotatedfor' => $stid, 'attemptcount' => $attemptcount];
if ($annotations = $DB->get_records('qtype_drawing_annotations', $fields, 'timemodified DESC')) {
    foreach ($annotations as $teacherannotation) {
        $user = $DB->get_record('user', ['id' => $teacherannotation->annotatedby]);
        $annotationslist[] = [
            'id' => $teacherannotation->id,
            'userid' => $user->id,
            'username' => fullname($user),
            'date' => userdate($teacherannotation->timemodified),
            'ago' => ' (' . get_string('ago', 'core_message', format_time(time() - $teacherannotation->timemodified)) . ')',
        ];
    }
}

[$colors, $defaultcolor, $showallcolorschooser] = qtype_drawing::get_colors_for_template($useupdateannotationjs, $fhd->colorsjson);

// Cache-busting revision for the bundled SVG-Edit lib/ scripts (loaded directly, outside the AMD
// pipeline). Tie it to Moodle's JS revision so "Purge all caches" (which bumps $CFG->jsrev) forces
// browsers to refetch them. In theme-designer/debug mode jsrev is -1 (no caching); fall back to a
// per-request value so the scripts are always fresh, matching that intent.
$jsrev = isset($CFG->jsrev) ? (int) $CFG->jsrev : 1;
if ($jsrev < 1) {
    $jsrev = time();
}

// Rewrite @@PLUGINFILE@@ URLs in the question text. Question text files are served through
// the attempt path (pluginfile.php/{ctx}/question/questiontext/{qubaid}/{slot}/{questionid}/...),
// so the usage id and slot passed by the renderer are needed to build valid URLs.
$questiontext = $question->questiontext;
if ($qubaid && $slot) {
    $questiontext = question_rewrite_question_urls(
        $questiontext,
        'pluginfile.php',
        $question->contextid,
        'question',
        'questiontext',
        [$qubaid, $slot],
        $question->id
    );
}
$questiontext = format_text($questiontext, $question->questiontextformat, ['context' => $cmcontext]);

$context = [
    'base_url' => $CFG->wwwroot . '/question/type/drawing/',
    'jsrev' => $jsrev,
    'id' => $id,
    'useupdateannotationjs' => ($useupdateannotationjs == 1),
    'backgroundwidth' => $fhd->backgroundwidth,
    'backgroundheight' => $fhd->backgroundheight,
    'questionembed' => $fhd->questionembed == 1,
    'hidemenu' => !empty($fhd->hidemenu),
    'questiontext' => $questiontext,
    'defaultpensize' => (int) $fhd->defaultpensize,
    'stid' => $stid,
    'attemptid' => strip_tags($attemptid),
    'uniquefieldnameattemptid' => strip_tags($uniquefieldnameattemptid),
    'sesskey' => strip_tags($sesskey),
    'attemptcount' => $attemptcount,
    'colorhighlighter' => $fhd->colorhighlighter,
    'annotations_list' => $annotationslist,
    'displaystylefull' => 'style="display: inline-block;"',
    'colors' => $colors,
    'defaultcolor' => $defaultcolor,
    'showallcolorschooser' => $showallcolorschooser,
];

// Per-question drawing tool visibility. Restrictions apply to the student-facing canvas only;
// when a teacher is annotating during grading (mod/quiz:grade + readonly) every tool stays available.
$restricttools = ($useupdateannotationjs != 1);
$enabledtools = [];
foreach (qtype_drawing_tool_names() as $tool) {
    $enabledtools[$tool] = $restricttools ? !empty($fhd->$tool) : true;
    // Boolean flag consumed by the template to add the matching hide class on #svg_editor.
    $context[$tool] = $enabledtools[$tool];
}
// Same flags for the JS default-tool fallback (pick a visible tool if the pencil is hidden).
$context['enabledtoolsjson'] = json_encode($enabledtools);

// Very, very dirty hack to get rid of theme css.
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
