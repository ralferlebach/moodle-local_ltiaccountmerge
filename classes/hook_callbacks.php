<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_ltiaccountmerge;

use local_ltiaccountmerge\local\mode_applier;

/**
 * Callbacks for core hooks.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /** @var int Seconds a launch waits for another process that is applying modes. */
    public const PRELAUNCH_LOCK_WAIT = 3;

    /** @var string[] The only scripts for which the pre-launch apply may run. */
    public const LAUNCH_SCRIPTS = [
        '/enrol/lti/launch.php',
        '/enrol/lti/launch_deeplink.php',
    ];

    /** @var string[] Scripts on which the optional first-launch hint is shown: the launch scripts (their error pages) and
     * the core account-binding dialog. */
    public const HINT_SCRIPTS = [
        '/enrol/lti/launch.php',
        '/enrol/lti/launch_deeplink.php',
        '/auth/lti/login.php',
    ];

    /**
     * Whether a script path (as in SCRIPT_NAME) is exactly one of the launch scripts.
     *
     * The path may be prefixed by a sub-directory install ($CFG->wwwroot path); the suffix must match a whole path
     * segment sequence, so /enrol/lti/launch.php.evil or /x/enrol/lti/launch.phpx never match.
     *
     * @param string $script Script path.
     * @return bool
     */
    public static function is_launch_script(string $script): bool {
        return self::matches($script, self::LAUNCH_SCRIPTS);
    }

    /**
     * Whether a script path is one of the pages that may carry the first-launch hint.
     *
     * @param string $script Script path.
     * @return bool
     */
    public static function is_hint_script(string $script): bool {
        return self::matches($script, self::HINT_SCRIPTS);
    }

    /**
     * Exact script match with an optional sub-directory prefix.
     *
     * @param string $script Script path.
     * @param string[] $suffixes Accepted scripts.
     * @return bool
     */
    protected static function matches(string $script, array $suffixes): bool {
        foreach ($suffixes as $suffix) {
            if ($script === $suffix) {
                return true;
            }
            $len = strlen($suffix);
            if (strlen($script) > $len && substr($script, -$len) === $suffix) {
                // Allowed only as a sub-directory install prefix.
                $prefix = substr($script, 0, -$len);
                if ($prefix !== '' && $prefix[0] === '/' && preg_match('~^(/[A-Za-z0-9_-][A-Za-z0-9._-]*)+$~', $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Applies the effective provisioning mode just before core reads it in a launch script (optional, default off).
     *
     * Cheap by construction: the script name is checked before the configuration or the database is touched.
     * Failures are swallowed (developer-level debugging only) - this callback must never break a request.
     *
     * @param \core\hook\after_config $hook The hook.
     */
    public static function after_config(\core\hook\after_config $hook): void {
        global $CFG;
        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        if ($script === '' || !self::is_launch_script($script)) {
            return;
        }
        if (during_initial_install() || !empty($CFG->upgraderunning)) {
            return;
        }
        try {
            if (!get_config('local_ltiaccountmerge', 'prelaunchapply')) {
                return;
            }
            // A launch is waiting: a short lock wait, and the instant is read after the lock was obtained.
            (new mode_applier(null, null, self::PRELAUNCH_LOCK_WAIT))->apply_all(null, 'prelaunch');
        } catch (\Throwable $e) {
            debugging('local_ltiaccountmerge pre-launch apply failed: ' . get_class($e), DEBUG_DEVELOPER);
        }
    }

    /**
     * Adds the configurable first-launch hint to the core account-binding dialog and to the launch error pages (optional,
     * default off).
     *
     * This does NOT replace or alter the core dialog (gate AR-G1 is negative: core offers no extension point for it). It
     * only adds a notice at the top of the body, through the supported output hook, on exactly three scripts.
     *
     * @param \core\hook\output\before_standard_top_of_body_html_generation $hook The hook.
     */
    public static function before_standard_top_of_body_html_generation(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        if ($script === '' || !self::is_hint_script($script)) {
            return;
        }
        try {
            if (!get_config('local_ltiaccountmerge', 'firsthint')) {
                return;
            }
            $text = \local_ltiaccountmerge\local\error_catalogue::get('firstloginhint');
            $support = trim((string) get_config('local_ltiaccountmerge', 'supportcontact'));
            $hook->add_html($hook->renderer->render_from_template('local_ltiaccountmerge/first_launch_hint', [
                'title' => $text['title'],
                'body' => $text['body'],
                'support' => $support,
                'hassupport' => $support !== '',
            ]));
        } catch (\Throwable $e) {
            debugging('local_ltiaccountmerge first-launch hint failed: ' . get_class($e), DEBUG_DEVELOPER);
        }
    }
}
