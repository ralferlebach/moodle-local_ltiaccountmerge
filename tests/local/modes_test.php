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
 * Tests for the mode constants.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\local\modes
 */
final class modes_test extends \advanced_testcase {
    /**
     * The mirrored constants must equal the core ones (detects a future core change).
     */
    public function test_constants_equal_core(): void {
        global $CFG;
        require_once($CFG->dirroot . '/auth/lti/auth.php');
        $this->assertSame(\auth_plugin_lti::PROVISIONING_MODE_AUTO_ONLY, modes::AUTO_ONLY);
        $this->assertSame(\auth_plugin_lti::PROVISIONING_MODE_PROMPT_NEW_EXISTING, modes::PROMPT_NEW_EXISTING);
        $this->assertSame(\auth_plugin_lti::PROVISIONING_MODE_PROMPT_EXISTING_ONLY, modes::PROMPT_EXISTING_ONLY);
    }

    /**
     * Validation helpers.
     */
    public function test_validity_and_names(): void {
        $this->resetAfterTest();
        $this->assertTrue(modes::is_valid(1));
        $this->assertFalse(modes::is_valid(0));
        $this->assertFalse(modes::is_valid(4));
        $this->assertFalse(modes::is_valid('1'));
        $this->assertFalse(modes::is_valid(null));
        foreach (modes::all() as $mode) {
            $this->assertNotEmpty(modes::name($mode));
        }
        $this->assertSame(get_string('mode_none', 'local_ltiaccountmerge'), modes::name(null));
        $this->assertCount(4, modes::options(true));
        $this->assertCount(3, modes::options(false));
        $this->expectException(\coding_exception::class);
        modes::string_id(9);
    }
}
