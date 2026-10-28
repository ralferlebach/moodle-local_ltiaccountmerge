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
 * Tests of the HTML allow-list, the error catalogue, branding and localisation.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\local\html_policy
 * @covers     \local_ltiaccountmerge\local\error_catalogue
 */
final class error_catalogue_test extends \advanced_testcase {
    /**
     * Provider of XSS and markup-injection vectors that must be rejected.
     *
     * @return array
     */
    public static function xss_provider(): array {
        return [
            'script tag' => ['<p>hi</p><script>alert(1)</script>'],
            'script upper' => ['<SCRIPT SRC=//evil.example/x.js></SCRIPT>'],
            'nested broken script' => ['<scr<script>ipt>alert(1)</scr</script>ipt>'],
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'svg onload' => ['<svg onload=alert(1)>'],
            'body onload' => ['<body onload=alert(1)>'],
            'event handler on allowed tag' => ['<p onclick="alert(1)">x</p>'],
            'mouseover on link' => ['<a href="https://example.org" onmouseover="alert(1)">x</a>'],
            'javascript url' => ['<a href="javascript:alert(1)">x</a>'],
            'javascript url mixed case' => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
            'javascript url with tab' => ["<a href=\"java\tscript:alert(1)\">x</a>"],
            'javascript url entity' => ['<a href="&#106;avascript:alert(1)">x</a>'],
            'javascript url entity colon' => ['<a href="javascript&colon;alert(1)">x</a>'],
            'data url' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'],
            'vbscript url' => ['<a href="vbscript:msgbox(1)">x</a>'],
            'protocol relative url' => ['<a href="//evil.example/x">x</a>'],
            'empty href' => ['<a href="">x</a>'],
            'iframe' => ['<iframe src="https://example.org"></iframe>'],
            'object' => ['<object data="x"></object>'],
            'embed' => ['<embed src="x">'],
            'style tag' => ['<style>body{display:none}</style>'],
            'style attribute' => ['<p style="position:fixed">x</p>'],
            'form' => ['<form action="https://evil.example"><input name="password"></form>'],
            'input' => ['<input type="text">'],
            'link tag' => ['<link rel="stylesheet" href="https://evil.example/x.css">'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">'],
            'base tag' => ['<base href="https://evil.example/">'],
            'html comment' => ['<!-- hidden --><p>x</p>'],
            'class attribute' => ['<p class="alert">x</p>'],
            'id attribute' => ['<p id="x">x</p>'],
            'target attribute' => ['<a href="https://example.org" target="_blank">x</a>'],
            'math' => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>'],
        ];
    }

    /**
     * Everything that can carry script or navigation is rejected.
     *
     * @dataProvider xss_provider
     * @param string $html Hostile input.
     */
    public function test_xss_rejected(string $html): void {
        $this->assertNotEmpty(html_policy::problems($html), 'accepted: ' . $html);
    }

    /**
     * Provider of acceptable texts.
     *
     * @return array
     */
    public static function safe_provider(): array {
        return [
            'plain text' => ['Just text'],
            'paragraph and emphasis' => ['<p>Hello <strong>you</strong> and <em>them</em>.</p>'],
            'list' => ['<ul><li>one</li><li>two</li></ul>'],
            'https link' => ['<p><a href="https://example.org/help" title="Help">Help</a></p>'],
            'mailto link' => ['<a href="mailto:support@example.org">Mail</a>'],
            'relative link' => ['<a href="/local/ltiaccountmerge/error.php">Error</a>'],
            'fragment link' => ['<a href="#top">Top</a>'],
            'language span' => ['<span lang="de">Hallo</span>'],
            'empty' => [''],
        ];
    }

    /**
     * Legitimate formatting passes.
     *
     * @dataProvider safe_provider
     * @param string $html Input.
     */
    public function test_safe_html_accepted(string $html): void {
        $this->assertSame([], html_policy::problems($html));
    }

    /**
     * Saving hostile text throws and stores nothing; saving good text stores, audits and renders.
     */
    public function test_save_rejects_and_accepts(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = get_admin();
        try {
            error_catalogue::save('launcherror', '*', 'Title', '<script>alert(1)</script>', $admin->id);
            $this->fail('hostile text was saved');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_html', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_ltiaccountmerge_text'));

        error_catalogue::save('launcherror', '*', 'Custom <b>title</b>', '<p>Call <strong>us</strong>.</p>', $admin->id);
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_text'));
        $this->assertTrue($DB->record_exists('local_ltiaccountmerge_audit', ['action' => 'savetext']));
        $text = error_catalogue::get('launcherror', 'en');
        $this->assertTrue($text['custom']);
        $this->assertStringContainsString('<strong>us</strong>', $text['body']);
        // The title is plain text: markup is neutralised.
        $this->assertStringNotContainsString('<b>', $text['title']);

        // Updating replaces, it does not duplicate.
        error_catalogue::save('launcherror', '*', 'Other', '<p>Again</p>', $admin->id);
        $this->assertSame(1, $DB->count_records('local_ltiaccountmerge_text'));
    }

    /**
     * Unknown codes and languages and empty texts are rejected.
     */
    public function test_save_validates_code_lang_and_content(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        foreach (
            [['nosuchcode', '*', 'T', '<p>x</p>', 'err_code'], ['launcherror', 'Not A Lang!', 'T', '<p>x</p>', 'err_lang'],
                ['launcherror', '*', '', '<p>x</p>', 'err_empty'], ['launcherror', '*', 'T', '  ', 'err_empty']] as $case
        ) {
            try {
                error_catalogue::save($case[0], $case[1], $case[2], $case[3], $admin->id);
                $this->fail('accepted ' . $case[4]);
            } catch (\moodle_exception $e) {
                $this->assertSame($case[4], $e->errorcode);
            }
        }
    }

    /**
     * Lookup order: language specific override, then all-languages override, then the plugin default.
     */
    public function test_lookup_order_and_fallback(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        $default = error_catalogue::get('busy', 'en');
        $this->assertFalse($default['custom']);
        $this->assertNotEmpty($default['title']);
        error_catalogue::save('busy', '*', 'All', '<p>all</p>', $admin->id);
        error_catalogue::save('busy', 'de', 'Deutsch', '<p>de</p>', $admin->id);
        $this->assertSame('Deutsch', error_catalogue::get('busy', 'de')['title']);
        $this->assertSame('All', error_catalogue::get('busy', 'en')['title']);
        $this->assertSame('All', error_catalogue::get('busy', 'fr')['title']);
        $this->assertSame('All', error_catalogue::get('busy', 'en')['title']);
    }

    /**
     * A tampered database row (not passing the policy) is never rendered; the default text is used instead.
     */
    public function test_tampered_row_is_not_rendered(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->insert_record('local_ltiaccountmerge_text', (object) ['code' => 'busy', 'lang' => '*', 'title' => 'x',
            'body' => '<script>alert(1)</script>', 'bodyformat' => FORMAT_HTML, 'usermodified' => 0, 'timemodified' => time()]);
        $text = error_catalogue::get('busy', 'en');
        $this->assertFalse($text['custom']);
        $this->assertStringNotContainsString('script', $text['body']);
    }

    /**
     * Unknown codes fall back to the generic launch error; every spec code exists.
     */
    public function test_codes(): void {
        $this->assertSame('launcherror', error_catalogue::normalise('<script>'));
        $this->assertSame('busy', error_catalogue::normalise('busy'));
        foreach (['launcherror', 'invalidtoken', 'sessionexpired', 'targettaken', 'noconsent', 'mergeconflict'] as $code) {
            $this->assertTrue(error_catalogue::is_code($code), $code);
        }
    }

    /**
     * Every catalogue code has a title and body in English AND German, and both are accepted by the HTML policy.
     */
    public function test_every_code_is_localised_en_and_de(): void {
        global $CFG;
        $this->resetAfterTest();
        $strings = [];
        foreach (['en', 'de'] as $lang) {
            $string = [];
            include($CFG->dirroot . '/local/ltiaccountmerge/lang/' . $lang . '/local_ltiaccountmerge.php');
            $strings[$lang] = $string;
        }
        foreach (error_catalogue::codes() as $code) {
            foreach (['en', 'de'] as $lang) {
                $this->assertNotEmpty($strings[$lang]['ec_' . $code . '_title'] ?? '', "$lang title $code");
                $this->assertNotEmpty($strings[$lang]['ec_' . $code . '_body'] ?? '', "$lang body $code");
                $this->assertSame([], html_policy::problems($strings[$lang]['ec_' . $code . '_body']));
            }
            $this->assertNotSame($strings['en']['ec_' . $code . '_title'], $strings['de']['ec_' . $code . '_title'], $code);
        }
    }

    /**
     * The English and German language files define exactly the same keys.
     */
    public function test_language_files_have_identical_keys(): void {
        global $CFG;
        $keys = [];
        foreach (['en', 'de'] as $lang) {
            $string = [];
            include($CFG->dirroot . '/local/ltiaccountmerge/lang/' . $lang . '/local_ltiaccountmerge.php');
            $keys[$lang] = array_keys($string);
            sort($keys[$lang]);
        }
        $this->assertSame($keys['en'], $keys['de']);
        $this->assertGreaterThan(100, count($keys['en']));
    }

    /**
     * Every string identifier used in code and templates exists in the language file.
     */
    public function test_all_used_strings_are_defined(): void {
        global $CFG;
        $string = [];
        include($CFG->dirroot . '/local/ltiaccountmerge/lang/en/local_ltiaccountmerge.php');
        $root = $CFG->dirroot . '/local/ltiaccountmerge';
        $missing = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $skip = ['/tests/', '/lang/', 'node_modules'];
            if (
                array_filter($skip, function ($part) use ($path) {
                    return strpos($path, $part) !== false;
                })
            ) {
                continue;
            }
            $source = file_get_contents($path);
            if (substr($path, -4) === '.php') {
                preg_match_all("/get_string\(\s*'([a-z0-9_:]+)'\s*,\s*'local_ltiaccountmerge'/", $source, $m);
                foreach ($m[1] as $id) {
                    if (!isset($string[$id])) {
                        $missing[] = $id;
                    }
                }
            } else if (substr($path, -9) === '.mustache') {
                preg_match_all('/\{\{#str\}\}\s*([a-z0-9_:]+)\s*,\s*local_ltiaccountmerge/', $source, $m);
                foreach ($m[1] as $id) {
                    if (!isset($string[$id])) {
                        $missing[] = $id;
                    }
                }
            }
        }
        $this->assertSame([], array_values(array_unique($missing)));
    }

    /**
     * Branding: defaults to the site short name, escapes configured text, exposes the logo URL when a file exists.
     */
    public function test_branding(): void {
        $this->resetAfterTest();
        $default = error_catalogue::branding();
        $this->assertSame(s(format_string(get_site()->shortname)), $default['name']);
        $this->assertNull($default['logourl']);
        set_config('brandname', 'Uni <script>x</script>', 'local_ltiaccountmerge');
        set_config('supportcontact', 'help@example.org', 'local_ltiaccountmerge');
        $branding = error_catalogue::branding();
        $this->assertStringNotContainsString('<script>', $branding['name']);
        $this->assertSame('help@example.org', $branding['support']);

        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => \context_system::instance()->id, 'component' => 'local_ltiaccountmerge', 'filearea' => 'brandlogo',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'logo.png',
        ], 'png');
        $this->assertStringContainsString('/pluginfile.php/', error_catalogue::branding()['logourl']);
    }

    /**
     * The rendered error page is accessible and contains the support ID but nothing else personal.
     */
    public function test_error_page_rendering(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/local/ltiaccountmerge/error.php');
        $PAGE->set_context(\context_system::instance());
        $renderer = $PAGE->get_renderer('local_ltiaccountmerge');
        $sid = audit::new_support_id();
        $html = $renderer->error_page('targettaken', $sid);
        $this->assertStringContainsString($sid, $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('aria-labelledby="ltiam-error-title"', $html);
        $this->assertStringContainsString('<h2', $html);
        $this->assertStringNotContainsString('{{', $html);
        $without = $renderer->error_page('nosuchcode', null);
        $this->assertStringContainsString('data-ltiam-code="launcherror"', $without);
        $this->assertStringNotContainsString('ltiam-supportid', $without);
    }

    /**
     * Support IDs are well-formed, unique and validated strictly.
     */
    public function test_support_id(): void {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $id = audit::new_support_id();
            $this->assertTrue(audit::is_support_id($id), $id);
            $ids[$id] = true;
        }
        $this->assertCount(50, $ids);
        foreach (['', 'LTIAM-', 'LTIAM-zzzzzzzz', 'ltiam-0123ABCD', 'LTIAM-0123ABCD ', "LTIAM-0123ABCD\n", '<script>'] as $bad) {
            $this->assertFalse(audit::is_support_id($bad), $bad);
        }
    }
}
