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
 * Click-to-sort behaviour for the qtype_drawing grader overview table.
 *
 * @module     qtype_drawing/overview_table
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const compareCells = (a, b, kind) => {
    if (kind === 'numeric') {
        const an = parseFloat(a);
        const bn = parseFloat(b);
        const av = isNaN(an) ? -Infinity : an;
        const bv = isNaN(bn) ? -Infinity : bn;
        return av - bv;
    }
    return a.localeCompare(b, undefined, {sensitivity: 'base'});
};

const cellValue = (row, idx) => {
    const cell = row.children[idx];
    if (!cell) {
        return '';
    }
    if (cell.dataset.sortvalue !== undefined) {
        return cell.dataset.sortvalue;
    }
    return cell.textContent.trim();
};

const sortBy = (table, idx, kind, direction) => {
    const tbody = table.tBodies[0];
    if (!tbody) {
        return;
    }
    const rows = Array.from(tbody.rows);
    rows.sort((a, b) => compareCells(cellValue(a, idx), cellValue(b, idx), kind));
    if (direction === 'descending') {
        rows.reverse();
    }
    rows.forEach(r => tbody.appendChild(r));
};

export const init = () => {
    const tables = document.querySelectorAll('table.qtype-drawing-overview-sortable');
    tables.forEach(table => {
        const headers = table.querySelectorAll('thead th');
        headers.forEach((th, idx) => {
            const kind = th.dataset.sortable;
            if (!kind) {
                return;
            }
            th.tabIndex = 0;
            th.setAttribute('role', 'button');
            const activate = () => {
                const current = th.getAttribute('aria-sort');
                const next = current === 'ascending' ? 'descending' : 'ascending';
                headers.forEach(h => h.removeAttribute('aria-sort'));
                th.setAttribute('aria-sort', next);
                sortBy(table, idx, kind, next);
            };
            th.addEventListener('click', activate);
            th.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    activate();
                }
            });
        });
    });
};
