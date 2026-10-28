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
 * The error catalogue: stable error codes, default (translated) texts and admin overrides.
 *
 * Lookup order for a code: override for the viewer's language, override for "all languages" (*), then the plugin's
 * language string. Texts never contain identity data; the support ID is added by the page.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class error_catalogue {
    /** @var string[] Codes required by AR-P02. */
    public const SPEC_CODES = ['launcherror', 'invalidtoken', 'sessionexpired', 'targettaken', 'noconsent', 'mergeconflict'];
    /** @var string[] Codes raised by this plugin's own flows. */
    public const FLOW_CODES = ['nopermission', 'badidentity', 'unknownissuer', 'targetinvalid', 'targetadmin', 'identitybound',
        'noattestation', 'userlinkingoff', 'requestinvalid', 'requestexpired', 'challengefailed', 'busy', 'racelost',
        'mergeblocked', 'firstloginhint'];

    /**
     * All known codes.
     *
     * @return string[]
     */
    public static function codes(): array {
        return array_merge(self::SPEC_CODES, self::FLOW_CODES);
    }

    /**
     * Whether a code exists.
     *
     * @param string $code Code.
     * @return bool
     */
    public static function is_code(string $code): bool {
        return in_array($code, self::codes(), true);
    }

    /**
     * Fall back to a generic code for unknown input, so a page never shows raw user input.
     *
     * @param string $code Requested code.
     * @return string A known code.
     */
    public static function normalise(string $code): string {
        return self::is_code($code) ? $code : 'launcherror';
    }

    /**
     * Title and safe HTML body for a code.
     *
     * @param string $code Known code.
     * @param string|null $lang Language; the current language when null.
     * @return array{title: string, body: string, custom: bool}
     */
    public static function get(string $code, ?string $lang = null): array {
        global $DB;
        $code = self::normalise($code);
        $lang = $lang ?? current_language();
        foreach ([$lang, '*'] as $candidate) {
            $row = $DB->get_record('local_ltiaccountmerge_text', ['code' => $code, 'lang' => $candidate]);
            if ($row && !html_policy::problems((string) $row->body)) {
                return ['title' => format_string($row->title), 'body' => html_policy::render($row->body), 'custom' => true];
            }
        }
        $sm = get_string_manager();
        $title = $sm->get_string('ec_' . $code . '_title', 'local_ltiaccountmerge', null, $lang);
        $body = $sm->get_string('ec_' . $code . '_body', 'local_ltiaccountmerge', null, $lang);
        return ['title' => s($title), 'body' => html_policy::render($body), 'custom' => false];
    }

    /**
     * Save an override. Rejects (throws) when the text violates the HTML policy.
     *
     * @param string $code Known code.
     * @param string $lang Language code or *.
     * @param string $title Title (plain text).
     * @param string $body HTML body.
     * @param int $userid Editing user.
     * @return int Row id.
     * @throws \moodle_exception When the input is not acceptable.
     */
    public static function save(string $code, string $lang, string $title, string $body, int $userid): int {
        global $DB;
        if (!self::is_code($code)) {
            throw new \moodle_exception('err_code', 'local_ltiaccountmerge');
        }
        if ($lang !== '*' && !preg_match('/^[a-z]{2,3}(_[a-z0-9]{1,8})*$/', $lang)) {
            throw new \moodle_exception('err_lang', 'local_ltiaccountmerge');
        }
        $title = trim(clean_param($title, PARAM_TEXT));
        if ($title === '' || trim($body) === '') {
            throw new \moodle_exception('err_empty', 'local_ltiaccountmerge');
        }
        $problems = html_policy::problems($body);
        if ($problems) {
            throw new \moodle_exception('err_html', 'local_ltiaccountmerge', '', implode(', ', $problems));
        }
        $now = time();
        $existing = $DB->get_record('local_ltiaccountmerge_text', ['code' => $code, 'lang' => $lang]);
        $row = (object) ['code' => $code, 'lang' => $lang, 'title' => \core_text::substr($title, 0, 255), 'body' => $body,
            'bodyformat' => FORMAT_HTML, 'usermodified' => $userid, 'timemodified' => $now];
        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('local_ltiaccountmerge_text', $row);
            $id = (int) $existing->id;
        } else {
            $id = (int) $DB->insert_record('local_ltiaccountmerge_text', $row);
        }
        audit::log('savetext', audit::OK, 'text', $id, 0, ['code' => $code, 'lang' => $lang], null, $userid);
        return $id;
    }

    /**
     * Branding values for the plugin's own pages.
     *
     * @return array{name: string, logourl: ?string, support: string}
     */
    public static function branding(): array {
        $name = trim((string) get_config('local_ltiaccountmerge', 'brandname'));
        if ($name === '') {
            $name = format_string(get_site()->shortname);
        }
        $logourl = null;
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            \context_system::instance()->id,
            'local_ltiaccountmerge',
            'brandlogo',
            0,
            'filename',
            false
        );
        if ($files) {
            $file = reset($files);
            $logourl = \moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            )->out(false);
        }
        return [
            'name' => s($name),
            'logourl' => $logourl,
            'support' => s(trim((string) get_config('local_ltiaccountmerge', 'supportcontact'))),
        ];
    }
}
