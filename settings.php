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

/**
 * Admin settings and pages of local_ltiaccountmerge.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if (
    $hassiteconfig || has_any_capability(
        [
        'local/ltiaccountmerge:managepolicy', 'local/ltiaccountmerge:link', 'local/ltiaccountmerge:viewaudit',
        'local/ltiaccountmerge:simulatemerge', 'local/ltiaccountmerge:managetexts', 'local/ltiaccountmerge:merge'],
        context_system::instance()
    )
) {
    // The "localplugins" category only exists for users with moodle/site:config. Delegated roles that hold just one of
    // this plugin's capabilities get the category at the top level of the administration tree instead.
    $ADMIN->add($hassiteconfig ? 'localplugins' : 'root', new admin_category(
        'local_ltiaccountmerge_cat',
        new lang_string('pluginname', 'local_ltiaccountmerge')
    ));

    $pages = [
        'policies' => ['managepolicy', '/local/ltiaccountmerge/policies.php'],
        'preview' => ['managepolicy', '/local/ltiaccountmerge/preview.php'],
        'link' => ['link', '/local/ltiaccountmerge/link.php'],
        'texts' => ['managetexts', '/local/ltiaccountmerge/texts.php'],
        'audit' => ['viewaudit', '/local/ltiaccountmerge/audit.php'],
        'simulate' => ['simulatemerge', '/local/ltiaccountmerge/simulate.php'],
        'merge' => ['merge', '/local/ltiaccountmerge/merge.php'],
    ];
    foreach ($pages as $key => [$capability, $url]) {
        $ADMIN->add('local_ltiaccountmerge_cat', new admin_externalpage(
            'local_ltiaccountmerge_' . $key,
            new lang_string('page_' . $key, 'local_ltiaccountmerge'),
            new moodle_url($url),
            'local/ltiaccountmerge:' . $capability
        ));
    }

    if ($hassiteconfig) {
        $settings = new admin_settingpage(
            'local_ltiaccountmerge_settings',
            new lang_string('settings', 'local_ltiaccountmerge')
        );
        $settings->add(new admin_setting_configcheckbox(
            'local_ltiaccountmerge/prelaunchapply',
            new lang_string('set_prelaunchapply', 'local_ltiaccountmerge'),
            new lang_string('set_prelaunchapply_desc', 'local_ltiaccountmerge'),
            0
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_ltiaccountmerge/firsthint',
            new lang_string('set_firsthint', 'local_ltiaccountmerge'),
            new lang_string('set_firsthint_desc', 'local_ltiaccountmerge'),
            0
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_ltiaccountmerge/mergemovebindings',
            new lang_string('set_mergemovebindings', 'local_ltiaccountmerge'),
            new lang_string('set_mergemovebindings_desc', 'local_ltiaccountmerge'),
            1
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_ltiaccountmerge/requireconsent',
            new lang_string('set_requireconsent', 'local_ltiaccountmerge'),
            new lang_string('set_requireconsent_desc', 'local_ltiaccountmerge'),
            1
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_ltiaccountmerge/userlinking',
            new lang_string('set_userlinking', 'local_ltiaccountmerge'),
            new lang_string('set_userlinking_desc', 'local_ltiaccountmerge'),
            0
        ));
        $settings->add(new admin_setting_configtext(
            'local_ltiaccountmerge/auditretention',
            new lang_string('set_auditretention', 'local_ltiaccountmerge'),
            new lang_string('set_auditretention_desc', 'local_ltiaccountmerge'),
            0,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_ltiaccountmerge/brandname',
            new lang_string('set_brandname', 'local_ltiaccountmerge'),
            new lang_string('set_brandname_desc', 'local_ltiaccountmerge'),
            '',
            PARAM_TEXT
        ));
        $settings->add(new admin_setting_configtext(
            'local_ltiaccountmerge/supportcontact',
            new lang_string('set_supportcontact', 'local_ltiaccountmerge'),
            new lang_string('set_supportcontact_desc', 'local_ltiaccountmerge'),
            '',
            PARAM_TEXT
        ));
        $settings->add(new admin_setting_configstoredfile(
            'local_ltiaccountmerge/brandlogo',
            new lang_string('set_brandlogo', 'local_ltiaccountmerge'),
            new lang_string('set_brandlogo_desc', 'local_ltiaccountmerge'),
            'brandlogo',
            0,
            ['accepted_types' => ['web_image'], 'maxfiles' => 1]
        ));
        $ADMIN->add('local_ltiaccountmerge_cat', $settings);
    }
}
