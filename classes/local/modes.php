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

namespace local_ltiaccountmerge\local;

/**
 * The three first-launch provisioning modes of core auth_lti, mirrored as plain constants.
 *
 * The values are asserted equal to auth_plugin_lti::PROVISIONING_MODE_* by a PHPUnit test, so a
 * future core change is detected instead of silently misapplied.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class modes {
    /** @var int Automatic account creation only. */
    public const AUTO_ONLY = 1;
    /** @var int Prompt: create a new account or link an existing one. */
    public const PROMPT_NEW_EXISTING = 2;
    /** @var int Prompt: link an existing account only. */
    public const PROMPT_EXISTING_ONLY = 3;

    /**
     * All valid mode values.
     *
     * @return int[]
     */
    public static function all(): array {
        return [self::AUTO_ONLY, self::PROMPT_NEW_EXISTING, self::PROMPT_EXISTING_ONLY];
    }

    /**
     * Whether a value is a valid mode.
     *
     * @param mixed $mode The value.
     * @return bool
     */
    public static function is_valid($mode): bool {
        return is_int($mode) && in_array($mode, self::all(), true);
    }

    /**
     * Language string identifier describing a mode.
     *
     * @param int $mode The mode.
     * @return string
     */
    public static function string_id(int $mode): string {
        switch ($mode) {
            case self::AUTO_ONLY:
                return 'mode_auto';
            case self::PROMPT_NEW_EXISTING:
                return 'mode_newexisting';
            case self::PROMPT_EXISTING_ONLY:
                return 'mode_existingonly';
        }
        throw new \coding_exception('Invalid provisioning mode');
    }

    /**
     * Localised name of a mode.
     *
     * @param int|null $mode The mode or null (not governed).
     * @return string
     */
    public static function name(?int $mode): string {
        if ($mode === null) {
            return get_string('mode_none', 'local_ltiaccountmerge');
        }
        return get_string(self::string_id($mode), 'local_ltiaccountmerge');
    }

    /**
     * Options for a select element.
     *
     * @param bool $withnone Whether to include a "not governed" choice with key ''.
     * @return string[]
     */
    public static function options(bool $withnone): array {
        $options = [];
        if ($withnone) {
            $options[''] = get_string('mode_none', 'local_ltiaccountmerge');
        }
        foreach (self::all() as $mode) {
            $options[$mode] = self::name($mode);
        }
        return $options;
    }
}
