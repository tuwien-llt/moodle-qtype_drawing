<?php
// phpcs:ignoreFile
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
 * @package qtype_drawing
 * @author Amr Hourani amr.hourani@id.ethz.ch
 * @copyright ETHz 2016 amr.hourani@id.ethz.ch
 */
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot . '/question/type/drawing/lib.php');

    // Introductory explanation that all the settings are defaults for the edit_drawing_form.
    $settings->add(
        new admin_setting_heading('configintro', '', get_string('configintro', 'qtype_drawing'))
    );

    // Default canvas width.
    $settings->add(
        $x = new admin_setting_configtext(
            'qtype_drawing/defaultcanvaswidth',
            get_string('defaultcanvaswidth', 'qtype_drawing'),
            get_string('defaultcanvaswidth_help', 'qtype_drawing'),
            580,
            PARAM_INT,
            4
        )
    );
    // Default canvas height.
    $settings->add(
        new admin_setting_configtext(
            'qtype_drawing/defaultcanvasheight',
            get_string('defaultcanvasheight', 'qtype_drawing'),
            get_string('defaultcanvasheight_help', 'qtype_drawing'),
            400,
            PARAM_INT,
            4
        )
    );

    // Default pen size.
    $pensizechoices = [];
    for ($i = 1; $i < 100; $i++) {
        $pensizechoices[$i] = $i . 'px';
    }
    $settings->add(
        new admin_setting_configselect(
            'qtype_drawing/defaultpensize',
            get_string('defaultpensize', 'qtype_drawing'),
            get_string('defaultpensize_help', 'qtype_drawing'),
            10,
            $pensizechoices
        )
    );

    // add setting questionembed
    $settings->add(
        new admin_setting_configcheckbox(
            'qtype_drawing/questionembed',
            get_string('questionembed', 'qtype_drawing'),
            get_string('questionembed_help', 'qtype_drawing'),
            1
        )
    );

    // Add setting colorsjson - textarea
    $defaultjson = '{"colors":[{"hex":"#336699","avail_student":true,"avail_trainer":true,"def_student":false,"def_trainer":false},{"hex":"#456","avail_student":true,"avail_trainer":true,"def_student":false,"def_trainer":false},{"hex":"#ccc","avail_student":true,"avail_trainer":true,"def_student":true,"def_trainer":false},{"hex":"#ee0","avail_student":false,"avail_trainer":true,"def_student":false,"def_trainer":false},{"hex":"#f00","avail_student":false,"avail_trainer":true,"def_student":false,"def_trainer":true}],"globalSettings":{"trainerAvailable":true,"studentAvailable":true}}';
    $settings->add(
        new admin_setting_configtextarea(
            'qtype_drawing/colorsjson',
            get_string('colors:config', 'qtype_drawing'),
            get_string('colors:config_help', 'qtype_drawing'),
            $defaultjson,
            PARAM_RAW
        )
    );

    $PAGE->requires->js_call_amd('qtype_drawing/color_config', 'init', ['id_s_qtype_drawing_colorsjson']);

    // Add setting colorhighlighter - text
    $settings->add(
        new admin_setting_configtext('qtype_drawing/colorhighlighter', get_string('color:highlighter', 'qtype_drawing'), get_string('color:highlighter_help', 'qtype_drawing'), '#ff0', PARAM_RAW)
    );
}
