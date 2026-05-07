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

namespace qtype_drawing\grading;

/**
 * Helper class for loading quiz attempts for drawing questions.
 *
 * @package    qtype_drawing
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempts_loader {
    /** @var array<string, array> Per-request memo of attempts keyed by quizid:slot:cmid. */
    private static array $slotattemptscache = [];

    /**
     * Get all drawing question slots in a quiz.
     *
     * @param int $quizid The quiz ID.
     * @return array Array of slot objects with question info, keyed by slot number.
     */
    public static function get_drawing_slots(int $quizid): array {
        global $DB;

        // Use qs.id as the unique key for the query, but we'll re-key by slot after.
        $sql = "SELECT qs.id AS slotid,
                       qs.slot,
                       qs.maxmark,
                       q.id AS questionid,
                       q.name AS questionname
                  FROM {quiz_slots} qs
                  JOIN {question_references} qr ON qr.itemid = qs.id
                       AND qr.component = 'mod_quiz'
                       AND qr.questionarea = 'slot'
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                       AND qv.version = (
                           SELECT MAX(qv2.version)
                           FROM {question_versions} qv2
                           WHERE qv2.questionbankentryid = qbe.id
                       )
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qs.quizid = :quizid
                   AND q.qtype = 'drawing'
              ORDER BY qs.slot";

        $records = $DB->get_records_sql($sql, ['quizid' => $quizid]);

        // Re-key by slot number for easier lookup.
        $result = [];
        foreach ($records as $record) {
            $result[$record->slot] = $record;
        }
        return $result;
    }

    /**
     * Get all attempts needing grading for all drawing questions in a quiz.
     *
     * @param int $quizid The quiz ID.
     * @param int $cmid The course module ID.
     * @param int|null $groupid Optional group id to restrict to members of one group.
     * @return array Array of attempt objects grouped by slot with grading info.
     */
    public static function get_all_drawing_attempts(int $quizid, int $cmid, ?int $groupid = null): array {
        $slots = self::get_drawing_slots($quizid);
        $result = [];

        foreach ($slots as $slotinfo) {
            $attempts = self::get_attempts_for_slot($quizid, $slotinfo->slot, $cmid, $groupid);
            foreach ($attempts as $attempt) {
                $attempt->slot = $slotinfo->slot;
                $attempt->questionname = $slotinfo->questionname;
                $result[] = $attempt;
            }
        }

        return $result;
    }

    /**
     * Get grading statistics for all drawing questions in a quiz.
     *
     * @param int $quizid The quiz ID.
     * @param int $cmid The course module ID.
     * @param int|null $groupid Optional group id to restrict to members of one group.
     * @return object Statistics object.
     */
    public static function get_grading_stats(int $quizid, int $cmid, ?int $groupid = null): object {
        $attempts = self::get_all_drawing_attempts($quizid, $cmid, $groupid);

        $stats = new \stdClass();
        $stats->total = count($attempts);
        $stats->needsgrading = count(array_filter($attempts, fn($a) => $a->needsgrading));
        $stats->graded = $stats->total - $stats->needsgrading;

        return $stats;
    }

    /**
     * Build per-question overview rows for the grader landing page.
     *
     * Each row aggregates the attempts for one drawing slot in the quiz:
     * distinct responding students, total finished attempts, count of
     * attempts that still need grading, count of already-graded
     * attempts, and the URL of the first ungraded attempt (or first
     * attempt overall when fully graded) so the action button can land
     * the teacher inside the fullscreen grader.
     *
     * @param int $quizid The quiz ID.
     * @param int $cmid The course module ID.
     * @param int|null $groupid Optional group id to restrict to members of one group.
     * @return array Array of row objects, one per drawing slot.
     */
    public static function get_overview_rows(int $quizid, int $cmid, ?int $groupid = null): array {
        global $DB;

        $slots = self::get_drawing_slots($quizid);

        // Denominator for "37/42": distinct users with finished attempts on this quiz.
        $params = ['quizid' => $quizid];
        $groupjoin = '';
        if ($groupid) {
            $groupjoin = 'JOIN {groups_members} gm ON gm.userid = qa.userid AND gm.groupid = :groupid';
            $params['groupid'] = $groupid;
        }
        $totalstudents = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT qa.userid)
               FROM {quiz_attempts} qa
               $groupjoin
              WHERE qa.quiz = :quizid AND qa.state = 'finished'",
            $params
        );

        $rows = [];
        foreach ($slots as $slotinfo) {
            $attempts = self::get_attempts_for_slot($quizid, $slotinfo->slot, $cmid, $groupid);

            $totalattempts = count($attempts);
            $tograde = 0;
            $graded = 0;
            $responseusers = [];
            $firstungraded = null;
            $firstany = null;

            foreach ($attempts as $attempt) {
                if ($attempt->hasresponse) {
                    $responseusers[$attempt->userid] = true;
                }
                if ($attempt->needsgrading) {
                    $tograde++;
                    $firstungraded = $firstungraded ?? $attempt->attemptid;
                } else {
                    $graded++;
                }
                $firstany = $firstany ?? $attempt->attemptid;
            }

            $startid = $firstungraded ?? $firstany;
            $needsattention = $tograde > 0;

            $row = new \stdClass();
            $row->slot = $slotinfo->slot;
            $row->questionname = format_string($slotinfo->questionname);
            $row->responsecount = count($responseusers);
            $row->totalstudents = $totalstudents;
            $row->totalattempts = $totalattempts;
            $row->tograde = $tograde;
            $row->graded = $graded;
            $row->hasattempts = $startid !== null;
            $row->needsattention = $needsattention;
            $row->actionurl = $startid !== null
                ? (new \moodle_url('/mod/quiz/report.php', [
                    'id' => $cmid,
                    'mode' => 'drawing',
                    'slot' => $slotinfo->slot,
                    'attemptid' => $startid,
                ]))->out(false)
                : '';
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Get all attempts for a specific question slot in a quiz.
     *
     * @param int $quizid The quiz ID.
     * @param int $slot The question slot number.
     * @param int $cmid The course module ID.
     * @param int|null $groupid Optional group id to restrict to members of one group.
     * @return array Array of attempt objects with grading info.
     */
    public static function get_attempts_for_slot(int $quizid, int $slot, int $cmid, ?int $groupid = null): array {
        $cachekey = "{$quizid}:{$slot}:{$cmid}:" . ($groupid ?? '0');
        if (isset(self::$slotattemptscache[$cachekey])) {
            return self::$slotattemptscache[$cachekey];
        }

        global $DB;

        // Get user name fields for fullname() function.
        $userfieldsapi = \core_user\fields::for_name();
        $userfieldssql = $userfieldsapi->get_sql('u', false, '', '', false);

        $params = ['quizid' => $quizid];
        $groupwhere = '';
        if ($groupid) {
            $groupwhere = 'AND EXISTS (SELECT 1 FROM {groups_members} gm
                                        WHERE gm.groupid = :groupid AND gm.userid = qa.userid)';
            $params['groupid'] = $groupid;
        }

        // Get all finished attempts for this quiz (optionally filtered by group).
        $sql = "SELECT qa.id AS attemptid,
                       qa.userid,
                       qa.uniqueid AS qubaid,
                       qa.state,
                       qa.timefinish,
                       qa.attempt AS attemptnumber,
                       {$userfieldssql->selects}
                  FROM {quiz_attempts} qa
                  JOIN {user} u ON u.id = qa.userid
                 WHERE qa.quiz = :quizid
                   AND qa.state = 'finished'
                   $groupwhere
              ORDER BY u.lastname, u.firstname, qa.attempt";

        $attempts = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($attempts as $attempt) {
            // Load the question usage to get the state for this specific slot.
            try {
                $quba = \question_engine::load_questions_usage_by_activity($attempt->qubaid);
                $qa = $quba->get_question_attempt($slot);
            } catch (\Exception $e) {
                // Skip if we can't load the question attempt.
                continue;
            }

            $state = $qa->get_state();
            $mark = $qa->get_mark();
            $maxmark = $qa->get_max_mark();

            $answer = (string) $qa->get_last_qt_var('answer');

            $attemptobj = new \stdClass();
            $attemptobj->attemptid = $attempt->attemptid;
            $attemptobj->userid = $attempt->userid;
            $attemptobj->qubaid = $attempt->qubaid;
            $attemptobj->username = fullname($attempt);
            $attemptobj->attemptnumber = $attempt->attemptnumber;
            $attemptobj->timefinish = userdate($attempt->timefinish);
            $attemptobj->state = $state->default_string(true);
            $attemptobj->stateclass = self::get_state_class($state);
            $attemptobj->needsgrading = $state->is_graded() === false || $state == \question_state::$needsgrading;
            $attemptobj->hasresponse = ($answer !== '');
            $attemptobj->grade = $mark !== null ? format_float($mark, 2) : '-';
            $attemptobj->maxgrade = format_float($maxmark, 2);
            $attemptobj->gradeurl = (new \moodle_url('/mod/quiz/report.php', [
                'id' => $cmid,
                'mode' => 'drawing',
                'slot' => $slot,
                'attemptid' => $attempt->attemptid,
            ]))->out(false);

            $result[$attempt->attemptid] = $attemptobj;
        }

        self::$slotattemptscache[$cachekey] = $result;
        return $result;
    }

    /**
     * Get navigation info for moving between attempts.
     *
     * @param int $quizid The quiz ID.
     * @param int $slot The question slot number.
     * @param int $currentattemptid The current attempt ID being graded.
     * @param int $cmid The course module ID.
     * @return object Object with prev, next, current_index, total.
     */
    public static function get_attempt_navigation(int $quizid, int $slot, int $currentattemptid, int $cmid): object {
        $attempts = self::get_attempts_for_slot($quizid, $slot, $cmid);
        $attemptids = array_keys($attempts);

        $nav = new \stdClass();
        $nav->prev = null;
        $nav->next = null;
        $nav->current_index = 0;
        $nav->total = count($attemptids);

        $currentpos = array_search($currentattemptid, $attemptids);
        if ($currentpos !== false) {
            $nav->current_index = $currentpos + 1;
            if ($currentpos > 0) {
                $nav->prev = $attemptids[$currentpos - 1];
            }
            if ($currentpos < count($attemptids) - 1) {
                $nav->next = $attemptids[$currentpos + 1];
            }
        }

        return $nav;
    }

    /**
     * Get student-level navigation: prev/next student for a slot,
     * each represented by their latest attempt id.
     *
     * Attempts in `get_attempts_for_slot()` are already ordered by
     * lastname, firstname, attempt number ascending, so the last record
     * we see for any given user is their latest attempt.
     *
     * @param int $quizid The quiz ID.
     * @param int $slot The question slot number.
     * @param int $currentattemptid The attempt currently being graded.
     * @param int $cmid The course module ID.
     * @param int|null $groupid Optional group id to restrict the navigation
     *                          set to members of one group.
     * @return object Object with prev (attemptid), next (attemptid),
     *                current_index (1-based student position),
     *                total (count of distinct students).
     */
    public static function get_student_navigation(
        int $quizid,
        int $slot,
        int $currentattemptid,
        int $cmid,
        ?int $groupid = null
    ): object {
        $attempts = self::get_attempts_for_slot($quizid, $slot, $cmid, $groupid);

        $latestbyuser = [];
        $userorder = [];
        $currentuserid = null;
        foreach ($attempts as $attempt) {
            if (!isset($latestbyuser[$attempt->userid])) {
                $userorder[] = $attempt->userid;
            }
            $latestbyuser[$attempt->userid] = $attempt;
            if ($attempt->attemptid == $currentattemptid) {
                $currentuserid = $attempt->userid;
            }
        }

        $nav = new \stdClass();
        $nav->prev = null;
        $nav->next = null;
        $nav->current_index = 0;
        $nav->total = count($userorder);

        if ($currentuserid !== null) {
            $pos = array_search($currentuserid, $userorder);
            if ($pos !== false) {
                $nav->current_index = $pos + 1;
                if ($pos > 0) {
                    $nav->prev = $latestbyuser[$userorder[$pos - 1]]->attemptid;
                }
                if ($pos < count($userorder) - 1) {
                    $nav->next = $latestbyuser[$userorder[$pos + 1]]->attemptid;
                }
            }
        }

        return $nav;
    }

    /**
     * Get all of a user's attempts for one slot, ordered by attempt
     * number ascending — used to populate the per-student attempt
     * dropdown in the fullscreen grader.
     *
     * @param int $quizid The quiz ID.
     * @param int $slot The question slot number.
     * @param int $userid The user whose attempts to return.
     * @param int $cmid The course module ID.
     * @return array Array of attempt objects (same shape as
     *               get_attempts_for_slot output).
     */
    public static function get_attempts_for_user(int $quizid, int $slot, int $userid, int $cmid): array {
        $attempts = self::get_attempts_for_slot($quizid, $slot, $cmid);
        $userattempts = array_filter($attempts, fn($a) => $a->userid == $userid);
        usort($userattempts, fn($a, $b) => $a->attemptnumber <=> $b->attemptnumber);
        return $userattempts;
    }

    /**
     * Get CSS class for a question state.
     *
     * @param \question_state $state The question state.
     * @return string CSS class name.
     */
    private static function get_state_class(\question_state $state): string {
        if ($state == \question_state::$needsgrading) {
            return 'badge-warning';
        } else if ($state->is_graded()) {
            if ($state == \question_state::$gradedright) {
                return 'badge-success';
            } else if ($state == \question_state::$gradedwrong) {
                return 'badge-danger';
            } else {
                return 'badge-info';
            }
        }
        return 'badge-secondary';
    }
}
