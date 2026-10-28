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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/helper.php');

/**
 * Unit tests of the live mode resolver: precedence rules.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\local\resolver
 */
final class resolver_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /**
     * Build a policy object without a database.
     *
     * @param array $fields Overrides.
     * @return policy
     */
    protected function make(array $fields): policy {
        static $id = 0;
        return policy::from_record((object) array_merge([
            'id' => ++$id, 'name' => 'p' . $id, 'scope' => 'site', 'scopeid' => 0, 'kind' => 'scheduled', 'priority' => 0,
            'enabled' => 1, 'timezone' => 'UTC', 'recurrence' => 'once', 'localstart' => null, 'localend' => null,
            'modelearner' => 1, 'modeinstructor' => null,
        ], $fields));
    }

    /**
     * Nothing governs: null (keep the resource's own setting).
     */
    public function test_no_policy_means_null(): void {
        $resolver = new resolver([]);
        $this->assertNull($resolver->effective_mode(resolver::LEARNER, 1, 1, time())['mode']);
        $this->assertSame(0, $resolver->count());
    }

    /**
     * Roles are independent: a policy that only governs learners leaves instructors alone.
     */
    public function test_roles_are_independent(): void {
        $resolver = new resolver([$this->make(['modelearner' => 3, 'modeinstructor' => null])]);
        $eff = $resolver->effective(1, 1, time());
        $this->assertSame(3, $eff[resolver::LEARNER]['mode']);
        $this->assertNull($eff[resolver::INSTRUCTOR]['mode']);
    }

    /**
     * A higher priority wins over a lower one; specificity only breaks ties.
     */
    public function test_priority_then_specificity(): void {
        $sitehigh = $this->make(['scope' => 'site', 'priority' => 10, 'modelearner' => 1]);
        $coursehigh = $this->make(['scope' => 'course', 'scopeid' => 5, 'priority' => 5, 'modelearner' => 3]);
        $this->assertSame(1, (new resolver([$coursehigh, $sitehigh]))->effective_mode('learner', 1, 5, time())['mode']);
        $tie = $this->make(['scope' => 'tool', 'scopeid' => 1, 'priority' => 10, 'modelearner' => 2]);
        $this->assertSame(2, (new resolver([$sitehigh, $tie]))->effective_mode('learner', 1, 5, time())['mode']);
        $coursetie = $this->make(['scope' => 'course', 'scopeid' => 5, 'priority' => 10, 'modelearner' => 3]);
        $this->assertSame(3, (new resolver([$sitehigh, $tie, $coursetie]))->effective_mode('learner', 1, 5, time())['mode']);
    }

    /**
     * A manual override beats any scheduled policy, whatever the priority, until it expires.
     */
    public function test_override_wins_until_expiry(): void {
        $scheduled = $this->make(['priority' => 1000, 'modelearner' => 1]);
        $override = $this->make(['kind' => 'override', 'priority' => 0, 'modelearner' => 3,
            'localstart' => '2026-06-01 08:00:00', 'localend' => '2026-06-01 09:00:00']);
        $resolver = new resolver([$scheduled, $override]);
        $inside = self::utc('2026-06-01 08:30:00');
        $after = self::utc('2026-06-01 09:00:00');
        $this->assertSame(3, $resolver->effective_mode('learner', 1, 1, $inside)['mode']);
        $this->assertSame($override->id, $resolver->effective_mode('learner', 1, 1, $inside)['policy']->id);
        $this->assertSame(1, $resolver->effective_mode('learner', 1, 1, $after)['mode']);
    }

    /**
     * Inactive or non-matching policies are skipped, not shadowing lower ones.
     */
    public function test_skips_inactive_and_non_matching(): void {
        $future = $this->make(['priority' => 50, 'modelearner' => 3, 'localstart' => '2099-01-01 00:00:00']);
        $othertool = $this->make(['priority' => 40, 'scope' => 'tool', 'scopeid' => 99, 'modelearner' => 2]);
        $base = $this->make(['priority' => 1, 'modelearner' => 1]);
        $this->assertSame(1, (new resolver([$future, $othertool, $base]))->effective_mode('learner', 1, 1, time())['mode']);
    }

    /**
     * Loading from the database ignores disabled policies.
     */
    public function test_from_db_ignores_disabled(): void {
        $this->resetAfterTest();
        $this->create_policy(['name' => 'on', 'modelearner' => 3]);
        $this->create_policy(['name' => 'off', 'enabled' => 0, 'priority' => 99, 'modelearner' => 2]);
        $resolver = resolver::from_db();
        $this->assertSame(1, $resolver->count());
        $this->assertSame(3, $resolver->effective_mode('learner', 1, 1, time())['mode']);
    }
}
