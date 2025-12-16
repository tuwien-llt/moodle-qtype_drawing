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
 *  description here.
 *
 * @module     qtype_drawing/color_config
 * @copyright  2025 Simeon Naydenov <moniNaydenov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Templates from 'core/templates';
import Notification from 'core/notification';

const co = window.console;
/**
 * Main class to handle the color configuration form.
 */
class ColorConfig {
    constructor(textareaId) {
        this.textarea = document.getElementById(textareaId);
        if (!this.textarea) {
            throw new Error(`Textarea with ID ${textareaId} not found.`);
        }

        // Generate a unique ID for this instance to handle radio button groups unique naming
        this.uniqid = 'color_cfg_' + Math.random().toString(36).substr(2, 9);

        // Internal state
        this.state = {
            colors: [],
            globalSettings: {
                trainerAvailable: false,
                studentAvailable: false
            }
        };

        this.init();
    }

    /**
     * Initialize the component.
     */
    init() {
        // 1. Hide the original textarea
        //this.textarea.style.display = 'none';

        // 2. Load data from textarea if it exists
        try {
            const raw = this.textarea.value.trim();
            if (raw) {
                const parsed = JSON.parse(raw);
                this.state.colors = parsed.colors || [];
                this.state.globalSettings = parsed.globalSettings || { trainerAvailable: false, studentAvailable: false };
            }
        } catch (e) {
            co.error('Invalid JSON in color config textarea', e);
            // Fallback to empty state
        }

        // 3. Render the main container
        this.render();
    }

    /**
     * Render the UI.
     */
    async render() {
        // Prepare context for the main container
        const context = {
            'uniqueid': this.uniqid,
        };

        try {
            // Render the shell
            const { html, js } = await Templates.renderForPromise('qtype_drawing/color_config_form', context);
            // Insert after textarea
            const container = document.createElement('div');
            container.innerHTML = html;
            this.textarea.parentNode.insertBefore(container, this.textarea.nextSibling);
            Templates.runTemplateJS(js);

            // Save reference to the wrapper
            this.wrapper = document.getElementById(this.uniqid);
            this.rowsContainer = this.wrapper.querySelector('[data-region="color-rows-container"]');

            // Set global checkbox states based on loaded data
            const globalTrainer = this.wrapper.querySelector('[data-field="global_trainer"]');
            const globalStudent = this.wrapper.querySelector('[data-field="global_student"]');

            if (globalTrainer) {
                globalTrainer.checked = this.state.globalSettings.trainerAvailable;
            }
            if (globalStudent) {
                globalStudent.checked = this.state.globalSettings.studentAvailable;
            }

            // Render existing rows
            this.state.colors.forEach(color => this.appendRow(color));

            // Attach Event Listeners
            this.attachEvents();

        } catch (error) {
            Notification.exception(error);
        }
    }

    /**
     * Render and append a single row.
     *
     * @param {Object} data Optional data to initialize the row with. If not provided, a blank row will be rendered.
     */
    async appendRow(data = null) {
        const id = Date.now().toString(); // Simple ID generation

        const rowData = data || {
            hex: '',
            avail_student: false,
            avail_trainer: true, // Default per screenshot
            def_student: false,
            def_trainer: false
        };

        const context = {
            id: id,
            uniqueid: this.uniqid,
            ...rowData
        };

        try {
            const html = await Templates.render('qtype_drawing/color_row', context);
            this.rowsContainer.insertAdjacentHTML('beforeend', html);
        } catch (error) {
            Notification.exception(error);
        }
    }

    /**
     * Attach all event listeners to the wrapper using delegation.
     */
    attachEvents() {
        // 1. Add Button
        this.wrapper.addEventListener('click', (e) => {
            if (e.target.closest('[data-action="add-color"]')) {
                e.preventDefault();
                this.appendRow();
                this.updateJson();
            }
        });

        // 2. Delete Button
        this.wrapper.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action="delete-color"]');
            if (btn) {
                e.preventDefault();
                const row = btn.closest('.color-row');
                if (row) {
                    row.remove();
                    this.updateJson();
                }
            }
        });

        // 3. Inputs Change (Delegation for all inputs)
        this.wrapper.addEventListener('change', (e) => {
            const target = e.target;

            // Handle Global Settings
            if (target.dataset.field === 'global_trainer' || target.dataset.field === 'global_student') {
                this.updateJson();
                return;
            }

            const row = target.closest('.color-row');
            if (!row) {
                return;
            }

            // Handle Hex Color Preview
            if (target.dataset.field === 'hex') {
                const preview = row.querySelector('.color-preview');
                let val = target.value;
                // Add hash if missing for preview
                if (val && !val.startsWith('#')) {
                    val = '#' + val;
                }
                preview.style.backgroundColor = val;
            }

            this.updateJson();
        });

        // 4. Input Keyup (for live hex preview typing)
        this.wrapper.addEventListener('input', (e) => {
            if (e.target.dataset.field === 'hex') {
                const row = e.target.closest('.color-row');
                const preview = row.querySelector('.color-preview');
                let val = e.target.value;
                if (val && !val.startsWith('#')) {
                    val = '#' + val;
                }
                preview.style.backgroundColor = val;
            }
        });
    }

    /**
     * Scrape the DOM to rebuild the state object and update the hidden textarea.
     */
    updateJson() {
        const rows = this.wrapper.querySelectorAll('.color-row');
        const colors = [];

        rows.forEach(row => {
            colors.push({
                hex: row.querySelector('[data-field="hex"]').value,
                avail_student: row.querySelector('[data-field="avail_student"]').checked,
                avail_trainer: row.querySelector('[data-field="avail_trainer"]').checked,
                def_student: row.querySelector('[data-field="def_student"]').checked,
                def_trainer: row.querySelector('[data-field="def_trainer"]').checked
            });
        });

        const globalSettings = {
            trainerAvailable: this.wrapper.querySelector('[data-field="global_trainer"]').checked,
            studentAvailable: this.wrapper.querySelector('[data-field="global_student"]').checked
        };

        this.state = {
            colors: colors,
            globalSettings: globalSettings
        };

        // Write to textarea
        this.textarea.value = JSON.stringify(this.state);
    }
}

/**
 * Initialization function intended to be called from the PHP form output.
 * @param {string} textareaId The ID of the hidden textarea
 */
export const init = (textareaId) => {
    new ColorConfig(textareaId);
};