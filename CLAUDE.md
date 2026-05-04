# qtype_drawing

SVG-based freehand drawing question type from ETH Zurich LET (Amr Hourani).
Manually graded; teachers can overlay SVG annotations on top of student
drawings during grading.

## Plugin identity

- Component: `qtype_drawing` (frankenstyle).
- Version: `2025120200`, requires Moodle `2021050100` (3.11+).
  See `version.php`.
- Maturity: stable. Release tag `5.1`.
- License: GPLv3.
- Bundles SVG-Edit 5.1.0 / methodDraw 2020.08.02 / D3 v6 / Raphaël 2.2.7
  plus several OFL fonts. See `4thirdpartylibs.xml`.

## Big picture

This is **not** an auto-graded question type. Although
`qtype_drawing_question` extends `question_graded_by_strategy`,
`question.php:68-70` forces the `manualgraded` behaviour,
`get_correct_response()` returns `null` (`question.php:101-103`), and
`compare_response_with_answer()` always returns `false`
(`question.php:107-116`). Treat it like an essay question with a custom
canvas response.

Two pieces of state per attempt:

- `answer` qt_var (PARAM_RAW_TRIMMED) — the student's SVG markup.
- `uniqueuattemptid` qt_var — a stable token used as the join key for
  teacher annotations. Generated fresh on first display
  (`renderer.php:100-102`) or derived from the answer hash on review
  (`renderer.php:97-99`).

The drawing canvas runs in an isolated iframe served by
`drawingarea.php`, embedding bundled SVG-Edit/methodDraw. The parent
quiz page hosts only the iframe + the hidden `answer` form field that
receives the SVG on submit.

Teacher annotations are separate SVG layers persisted in the custom
`qtype_drawing_annotations` table and composed at render time on top of
the background and the student's drawing.

## File map

PHP entry points:

- `version.php` — metadata.
- `questiontype.php` — `class qtype_drawing extends question_type`.
  Save/load lives here:
  - `save_question_options` (lines 108-210), including the
    "Make a copy" branch (lines 168-198) that re-clones the background
    image when `pre_existing_question_id` indicates duplication.
  - `delete_question` (lines 94-100) — also wipes annotations.
  - `move_files` (lines 69-73), `delete_files` (lines 82-86).
  - XML import/export (lines 245-348). Background image is round-tripped
    as a base64 (or raw SVG) data URL inside `<bgimage>`.
  - Static `get_colors_for_template($teacherview, $colorsjson)`
    (lines 358-425) — filters the palette by role and surfaces the
    default-selected colour first.
- `question.php` — runtime question class. Read this first to understand
  the response shape and grading.
- `renderer.php` — `formulation_and_controls` (lines 78+) builds the
  iframe context. Also exposes `get_image_for_question()` returning
  `[type, dataURL, filename]`, used by both the renderer and the XML
  exporter.
- `lib.php` — only contains `qtype_drawing_pluginfile()` for serving
  background images from the `qtype_drawing_image_file` filearea.
- `edit_drawing_form.php` — `qtype_drawing_edit_form`. Standard question
  edit form plus drawing-specific fields added in `definition_inner()`.
- `settings.php` — admin defaults: canvas width/height, pen size,
  `questionembed`, `colorsjson`, `colorhighlighter`. Line 90 wires the
  `qtype_drawing/color_config` AMD module that decorates the
  `colorsjson` textarea into a colour-grid editor.
- `drawingarea.php` — iframe page. Required params (will die without
  them): `id`, `attemptid`, `uniquefieldnameattemptid`, `sesskey`.
  Optional: `stid`, `readonly`, `attemptcount`. Loads jQuery + jQuery UI
  explicitly; renders `qtype_drawing/drawingarea`. Has a
  "very, very dirty hack" at lines 129-135 that strips
  `theme/styles.php` `<link>` tags from the iframe header to keep the
  parent theme out of SVG-Edit's CSS.
- `drawingarea2.php` (60 KB) — appears to be a legacy/parallel
  implementation. Nothing in the rest of the plugin includes it. Treat
  as dead code unless you confirm otherwise.
- `grade.php` — custom manual-grading UI, included from a quiz report
  (it expects `$cm`, `$quiz`, `$course`, `$DB`, `$OUTPUT` etc. already
  in scope; do not run standalone). Two modes:
  - Overview (no `attemptid`) — renders `grader_overview`.
  - Fullscreen (with `attemptid` + `slot`) — renders
    `grader_fullscreen`, calls `quiz_attempt::render_question`.
  - `action=savegrade` POST → `grade_processor::process_grade` plus
    optional `grade_processor::save_annotation`.
- `saveannotation.php`, `getannotation.php`, `loadannotationdetails.php`
  — `AJAX_SCRIPT` endpoints. All require `mod/quiz:grade` and
  `confirm_sesskey()`. `saveannotation.php` strips `<script>` tags
  server-side before persisting (lines 62-66); the same stripping
  happens again inside `grade_processor::save_annotation_direct()`.

Classes:

- `classes/grading/grade_processor.php`
  - `process_grade()` — validates the mark against
    `min/max_fraction * max_mark`, calls `$quba->manual_grade()`, saves
    the QUBA, then recomputes quiz attempt sumgrades and final grade
    via `quiz_settings::create()->get_grade_calculator()->recompute_final_grade()`.
  - `save_annotation()` / `save_annotation_direct()` — the latter is
    what `saveannotation.php` calls.
- `classes/grading/attempts_loader.php` — joins
  `quiz_slots → question_references → question_bank_entries →
  question_versions → question` to find the drawing-typed slots in a
  quiz and per-attempt grading status used by the overview.
- `classes/privacy/provider.php` — declares **null_provider**. This is
  arguably wrong: `qtype_drawing_annotations` stores `annotatedby` and
  `annotatedfor` user IDs and a free-text `notes` column. A real
  `metadata\provider` is needed if a privacy audit comes up.

Database:

- `db/install.xml` — two tables (see schema below).
- `db/upgrade.php` — readable history of schema churn:
  - `2025120100` dropped `drawingmode` and `alloweraser`, added
    `colorsjson`, `defaultpensize`, `colormarker`, `questionembed`.
  - `2025120200` renamed `colormarker` → `colorhighlighter`.
  - References to the old names elsewhere in the codebase are stale.
- `db/install.php`, `db/uninstall.php` — small lifecycle hooks.

Frontend assets (`amd/src/`):

- `view.js` — student-side iframe sizing.
- `drawingarea.js` — main canvas init; configures RequireJS paths for
  the bundled libs in `lib/` (`pathseg`, `touch`, `taphold`,
  `mousewheel`, `canvg`, `jquery-svgicons`, etc.).
- `embedapi.js` — wraps SVG-Edit's embed API.
- `grader_ui.js` — teacher annotation UI used by
  `grader_fullscreen.mustache`.
- `color_config.js` — admin-settings palette editor (used by
  `settings.php`).
- `form.js` — edit-form-side wiring.

Templates (`templates/`):

- `formulation.mustache` — student-side iframe wrapper.
- `drawingarea.mustache` — the SVG-Edit UI rendered inside the iframe.
- `drawingarea_original.mustache` — older variant; unused.
- `grader_overview.mustache`, `grader_fullscreen.mustache` — grading UI.
- `color_config_form.mustache` + `color_row.mustache` — admin palette
  editor.

Bundled third-party (`lib/`, plus paths declared in
`4thirdpartylibs.xml`): SVG-Edit 5.1.0, methodDraw 2020.08.02, D3 v6,
Raphaël 2.2.7, jQuery SVG icon loader, canvg, plus seven web fonts. All
MIT/BSD/Apache/OFL.

## Database schema

```
qtype_drawing                          per-question config
  id PK
  questionid          int     FK -> question.id
  allowstudentimage   int     legacy; not exposed in current settings UI
  backgrounduploaded  int     1 if a background file lives in the filearea
  backgroundwidth     float   canvas width in px
  backgroundheight    float   canvas height in px
  preservear          int     keep background aspect ratio
  drawingoptions      text    serialized; the install.xml comment
                              "DataURI for the background image" is
                              stale -- backgrounds live in the filearea,
                              this column is generic option storage
  colorsjson          text    JSON: { colors: [{hex, avail_student,
                              avail_trainer, def_student, def_trainer},
                              ...], globalSettings: {studentAvailable,
                              trainerAvailable} }
  defaultpensize      int
  colorhighlighter    char(16) hex like '#ff0' (was named colormarker
                              pre-2025120200)
  questionembed       int     1 = render question stem inside the canvas

qtype_drawing_annotations              teacher SVG overlays
  id PK
  attemptid     char(16)   unique attempt token; NOT quiz_attempts.id.
                          Mirrors qa->get_last_qt_var('uniqueuattemptid'),
                          falling back to substr(md5(answer), 0, 14).'XX'
  questionid    int        index only -- not a real FK
  annotatedby   int        teacher user id
  annotatedfor  int        student user id
  annotation    text       SVG markup
  attemptcount  int        which quiz attempt (1, 2, ...)
  notes         text       free-text feedback alongside the SVG
  timecreated, timemodified
```

Annotation upsert key:
`(questionid, annotatedby, annotatedfor, attemptid, attemptcount)` —
that's the tuple `grade_processor::save_annotation_direct()` matches on
(lines 173-180).

Background images live in the file storage:
`component='qtype_drawing'`, `filearea='qtype_drawing_image_file'`,
`itemid=question.id`. Access is gated by
`qtype_drawing_question::check_file_access()` (`question.php:118-140`).

## Lifecycle / data flow

Authoring:

1. Teacher opens the edit form → `qtype_drawing_edit_form::definition()`
   and `definition_inner()` build the form. Background image uses the
   Moodle filemanager backed by a draft area.
2. Save → `save_question_options()` upserts the `qtype_drawing` row,
   then either pulls the draft files into the question filearea OR (if
   this is a "Make copy" identified by `pre_existing_question_id`)
   clones the existing background via
   `file_storage::create_file_from_storedfile`.

Attempt rendering (student):

1. `qtype_drawing_renderer::formulation_and_controls()` loads the
   `qtype_drawing` row, computes/loads `uniqueuattemptid`, builds the
   iframe URL pointing at `/question/type/drawing/drawingarea.php`.
2. `drawingarea.php` validates sesskey, loads the colour palette via
   `qtype_drawing::get_colors_for_template()`, renders
   `qtype_drawing/drawingarea`. The dirty CSS-strip hack at lines
   129-135 removes parent-theme stylesheets from the iframe head.
3. JS in `drawingarea.js` boots SVG-Edit via a RequireJS shim chain; on
   submit, the SVG markup is written into the parent form's hidden
   `answer` field.

Manual grading:

1. The custom quiz report mode (`mode=drawing` URLs) includes
   `grade.php` which renders overview or fullscreen.
2. Teacher submits → `grade_processor::process_grade()` validates the
   mark, calls `$quba->manual_grade()`, saves the QUBA, and recomputes
   quiz attempt sumgrades and the user's final quiz grade.
3. Annotation: client posts SVG to `saveannotation.php`, which strips
   `<script>` tags and upserts in `qtype_drawing_annotations` keyed by
   `(questionid, annotatedby, annotatedfor, attemptid, attemptcount)`.

Composite review view: when `readonly=1` and the viewer has
`mod/quiz:grade`, the renderer composes background + student SVG + N
teacher annotations as stacked SVG layers. Annotations are not
flattened to a single image; each teacher's overlay stays addressable.

## Settings & capabilities

No `db/access.php` — the plugin reuses Moodle core capabilities. The
only access check it adds is `mod/quiz:grade`, used in:

- `drawingarea.php:84` (decides whether the iframe loads the annotator
  JS) and the read-only review path,
- `saveannotation.php:50` (rejects non-graders with
  `{"result":"No permission"}`),
- `grade.php:48` (`require_capability` on the grading UI).

Standard `moodle/question:*` capabilities apply via the question
framework.

Admin settings (Site admin → Plugins → Question types → Drawing,
defined in `settings.php`):

- `defaultcanvaswidth` / `defaultcanvasheight`.
- `defaultpensize`.
- `questionembed` (checkbox).
- `colorsjson` (textarea, decorated by `qtype_drawing/color_config`).
- `colorhighlighter` (default `#ff0`).

These defaults are read in `save_question_options()` lines 121-128 only
when the question row does not yet exist; existing questions retain
their per-question values.

## Quirks, gotchas, and dead code

- `tests_disabled/` (not `tests/`) holds the original PHPUnit and Behat
  tests. Moodle's test runner won't pick them up. Re-enable by renaming
  to `tests/` and expect breakage.
- `drawingarea2.php` (60 KB) appears to be an older parallel
  implementation. Current code paths use `drawingarea.php`. Don't edit
  `drawingarea2.php` without checking it's actually wired up first.
- `templates/drawingarea_original.mustache` similarly looks unused.
- The `drawingoptions` column comment in `install.xml` ("DataURI for
  the background image") is wrong — backgrounds live in the filearea,
  not this column.
- The privacy provider declares no personal data, but the annotations
  table records user IDs and free-text notes. If a privacy review
  comes up, switch to a real `metadata\provider`.
- `qtype_drawing_annotations.questionid` is declared as an index but
  there is no foreign key (`install.xml:43-45`). Cleanup on question
  delete is done explicitly in `qtype_drawing::delete_question()`
  (`questiontype.php:94-100`).
- `question.php:113` calls `qtype_drawing_renderer::compare_drawings()`,
  assigns `$answer->fraction = 0`, then returns `false` — the
  comparison result is computed but discarded. Vestigial auto-grading
  code; if you ever wire it up, double-check `compare_drawings()` even
  exists in `renderer.php`.
- Many files start with `// phpcs:ignoreFile` — don't expect them to
  pass current Moodle coding-style checks.
- Naming churn is recent: `colormarker` → `colorhighlighter`, dropped
  `drawingmode` and `alloweraser`. Prefer reading `db/upgrade.php` from
  the bottom up when working on schema.

## Testing & dev pointers

- No working PHPUnit or Behat coverage right now (see
  `tests_disabled/`).
- Manual smoke test: create a quiz with a drawing question, add a
  background image, attempt as a student, then grade as a teacher with
  both a mark and an annotation. Verify the annotation persists and
  shows up in the review.
- AJAX endpoints all require sesskey + `mod/quiz:grade`; pasting URLs
  into the browser without an active grader session won't work.
- For schema changes, bump `version.php`'s trailing `.NN` only for
  small changes (project rule), and add a corresponding
  `if ($oldversion < ...)` block in `db/upgrade.php`.

## Cheat sheet

| Want to... | Look at |
|---|---|
| Change what gets saved per question | `questiontype.php::save_question_options` |
| Change the drawing canvas UI | `templates/drawingarea.mustache`, `amd/src/drawingarea.js` |
| Change the student-side iframe wrapper | `templates/formulation.mustache`, `amd/src/view.js`, `renderer.php::formulation_and_controls` |
| Change the grader overview/fullscreen | `grade.php`, `grader_overview.mustache`, `grader_fullscreen.mustache`, `amd/src/grader_ui.js` |
| Change manual-grade behaviour | `classes/grading/grade_processor.php` |
| Change colour palette config UI | `settings.php`, `amd/src/color_config.js`, `color_config_form.mustache`, `qtype_drawing::get_colors_for_template()` |
| Change annotation persistence | `saveannotation.php`, `grade_processor::save_annotation_direct()` |
| Change schema | `db/install.xml` + `db/upgrade.php` (bump only the trailing `.NN` of `version.php` for small changes) |
