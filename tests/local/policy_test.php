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
 * Unit tests of the policy value object: windows, boundaries, DST, validation.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltiaccountmerge\local\policy
 */
final class policy_test extends \advanced_testcase {
    use \local_ltiaccountmerge\tests\helper;

    /**
     * Build a policy object without a database.
     *
     * @param array $fields Field overrides.
     * @return policy
     */
    protected function make(array $fields): policy {
        return policy::from_record((object) array_merge([
            'id' => 1, 'name' => 'p', 'scope' => 'site', 'scopeid' => 0, 'kind' => 'scheduled', 'priority' => 0, 'enabled' => 1,
            'timezone' => 'UTC', 'recurrence' => 'once', 'localstart' => null, 'localend' => null, 'modelearner' => 1,
            'modeinstructor' => null,
        ], $fields));
    }

    /**
     * Half-open window: the start second is inside, the end second is outside.
     */
    public function test_boundary_second(): void {
        $p = $this->make(['localstart' => '2026-06-01 08:00:00', 'localend' => '2026-06-01 09:00:00']);
        $this->assertSame([], $p->validate());
        $this->assertFalse($p->is_active_at(self::utc('2026-06-01 07:59:59')));
        $this->assertTrue($p->is_active_at(self::utc('2026-06-01 08:00:00')));
        $this->assertTrue($p->is_active_at(self::utc('2026-06-01 08:59:59')));
        $this->assertFalse($p->is_active_at(self::utc('2026-06-01 09:00:00')));
    }

    /**
     * Open-ended windows.
     */
    public function test_open_ended(): void {
        $from = $this->make(['localstart' => '2026-06-01 08:00:00']);
        $this->assertFalse($from->is_active_at(self::utc('2026-06-01 07:59:59')));
        $this->assertTrue($from->is_active_at(self::utc('2030-01-01 00:00:00')));
        $until = $this->make(['localend' => '2026-06-01 08:00:00']);
        $this->assertTrue($until->is_active_at(self::utc('2020-01-01 00:00:00')));
        $this->assertFalse($until->is_active_at(self::utc('2026-06-01 08:00:00')));
    }

    /**
     * A disabled or invalid policy is never active.
     */
    public function test_disabled_and_invalid(): void {
        $this->assertFalse($this->make(['enabled' => 0])->is_active_at(time()));
        $this->assertFalse($this->make(['timezone' => 'Mars/Olympus'])->is_active_at(time()));
    }

    /**
     * Wall-clock times are interpreted in the policy's timezone.
     */
    public function test_timezone_offset(): void {
        $p = $this->make(['timezone' => 'Europe/Berlin', 'localstart' => '2026-01-15 09:00:00',
            'localend' => '2026-01-15 10:00:00']);
        // January: CET = UTC+1, so 09:00 local is 08:00 UTC.
        $this->assertSame(self::utc('2026-01-15 08:00:00'), $p->start_ts());
        $this->assertSame(self::utc('2026-01-15 09:00:00'), $p->end_ts());
        $summer = $this->make(['timezone' => 'Europe/Berlin', 'localstart' => '2026-07-15 09:00:00']);
        $this->assertSame(self::utc('2026-07-15 07:00:00'), $summer->start_ts());
    }

    /**
     * Spring-forward: a one-second window around the gap, and a start inside the gap.
     */
    public function test_dst_spring_forward(): void {
        // Berlin: 2026-03-29 02:00 CET jumps to 03:00 CEST (01:00 UTC).
        $edge = $this->make(['timezone' => 'Europe/Berlin', 'localstart' => '2026-03-29 01:59:59',
            'localend' => '2026-03-29 03:00:00']);
        $this->assertSame(self::utc('2026-03-29 00:59:59'), $edge->start_ts());
        $this->assertSame(self::utc('2026-03-29 01:00:00'), $edge->end_ts());
        $this->assertTrue($edge->is_active_at(self::utc('2026-03-29 00:59:59')));
        $this->assertFalse($edge->is_active_at(self::utc('2026-03-29 01:00:00')));
        // A wall-clock time that does not exist is moved forward by the gap.
        $gap = $this->make(['timezone' => 'Europe/Berlin', 'localstart' => '2026-03-29 02:30:00']);
        $this->assertSame(self::utc('2026-03-29 01:30:00'), $gap->start_ts());
    }

    /**
     * Fall-back: an ambiguous wall-clock time resolves to its first occurrence.
     */
    public function test_dst_fall_back(): void {
        // Berlin: 2026-10-25 03:00 CEST falls back to 02:00 CET; 02:30 happens twice (00:30 UTC and 01:30 UTC).
        $p = $this->make(['timezone' => 'Europe/Berlin', 'localstart' => '2026-10-25 02:30:00',
            'localend' => '2026-10-25 03:00:00']);
        $this->assertSame(self::utc('2026-10-25 00:30:00'), $p->start_ts());
        // 03:00 CET (second 03:00, after the fall-back) is 02:00 UTC.
        $this->assertSame(self::utc('2026-10-25 02:00:00'), $p->end_ts());
        // The window therefore covers 90 real minutes on this night.
        $this->assertTrue($p->is_active_at(self::utc('2026-10-25 01:30:00')));
        $this->assertTrue($p->is_active_at(self::utc('2026-10-25 01:59:59')));
        $this->assertFalse($p->is_active_at(self::utc('2026-10-25 02:00:00')));
    }

    /**
     * Provider for the deterministic DST resolution in several zones (southern hemisphere, fixed offset, far future).
     *
     * @return array
     */
    public static function wall_provider(): array {
        return [
            'UTC has no transitions' => ['UTC', '2026-06-01 12:00:00', '2026-06-01 12:00:00'],
            'Sydney ambiguous (clocks go back 03:00 to 02:00 on 2026-04-05)' => ['Australia/Sydney', '2026-04-05 02:30:00',
                '2026-04-04 15:30:00'],
            'Sydney gap (02:00 to 03:00 on 2026-10-04)' => ['Australia/Sydney', '2026-10-04 02:30:00', '2026-10-03 16:30:00'],
            'Sydney just before gap' => ['Australia/Sydney', '2026-10-04 01:59:59', '2026-10-03 15:59:59'],
            'Sydney just after gap' => ['Australia/Sydney', '2026-10-04 03:00:00', '2026-10-03 16:00:00'],
            'New York ambiguous (2026-11-01 02:00 to 01:00)' => ['America/New_York', '2026-11-01 01:30:00', '2026-11-01 05:30:00'],
            'Berlin far future summer' => ['Europe/Berlin', '2045-07-01 12:00:00', '2045-07-01 10:00:00'],
            'Berlin far future winter' => ['Europe/Berlin', '2045-12-01 12:00:00', '2045-12-01 11:00:00'],
            'Kolkata half hour offset' => ['Asia/Kolkata', '2026-06-01 12:00:00', '2026-06-01 06:30:00'],
        ];
    }

    /**
     * Wall-clock resolution is deterministic and DST-correct.
     *
     * @dataProvider wall_provider
     * @param string $timezone Timezone identifier.
     * @param string $wall Local wall-clock time.
     * @param string $expectedutc Expected UTC instant.
     */
    public function test_wall_to_ts(string $timezone, string $wall, string $expectedutc): void {
        $this->assertSame(self::utc($expectedutc), policy::wall_to_ts($wall, new \DateTimeZone($timezone)));
    }

    /**
     * Provider for daily windows that cross midnight.
     *
     * @return array
     */
    public static function daily_provider(): array {
        return [
            'before start' => ['2026-06-10 21:59:59', false],
            'at start' => ['2026-06-10 22:00:00', true],
            'late evening' => ['2026-06-10 23:59:59', true],
            'after midnight' => ['2026-06-11 00:00:00', true],
            'last second' => ['2026-06-11 05:59:59', true],
            'at end' => ['2026-06-11 06:00:00', false],
            'midday' => ['2026-06-11 12:00:00', false],
        ];
    }

    /**
     * Daily window 22:00-06:00 in UTC (crossing midnight).
     *
     * @dataProvider daily_provider
     * @param string $when UTC instant.
     * @param bool $expected Whether the window is active.
     */
    public function test_daily_crossing_midnight(string $when, bool $expected): void {
        $p = $this->make(['recurrence' => 'daily', 'localstart' => '22:00:00', 'localend' => '06:00:00']);
        $this->assertSame([], $p->validate());
        $this->assertSame($expected, $p->is_active_at(self::utc($when)));
    }

    /**
     * Daily window in Berlin across the DST night: local clock decides, not the UTC offset.
     */
    public function test_daily_across_dst_night(): void {
        $p = $this->make(['timezone' => 'Europe/Berlin', 'recurrence' => 'daily', 'localstart' => '22:00:00',
            'localend' => '06:00:00']);
        // Night of the spring-forward: 22:00 CET (21:00 UTC) until 06:00 CEST (04:00 UTC) = 7 real hours.
        $this->assertFalse($p->is_active_at(self::utc('2026-03-28 20:59:59')));
        $this->assertTrue($p->is_active_at(self::utc('2026-03-28 21:00:00')));
        $this->assertTrue($p->is_active_at(self::utc('2026-03-29 03:59:59')));
        $this->assertFalse($p->is_active_at(self::utc('2026-03-29 04:00:00')));
        // Night of the fall-back: 22:00 CEST (20:00 UTC) until 06:00 CET (05:00 UTC) = 9 real hours.
        $this->assertFalse($p->is_active_at(self::utc('2026-10-24 19:59:59')));
        $this->assertTrue($p->is_active_at(self::utc('2026-10-24 20:00:00')));
        $this->assertTrue($p->is_active_at(self::utc('2026-10-25 04:59:59')));
        $this->assertFalse($p->is_active_at(self::utc('2026-10-25 05:00:00')));
    }

    /**
     * A daily window that lies entirely inside the spring-forward gap is empty on that day only.
     */
    public function test_daily_window_inside_dst_gap(): void {
        $p = $this->make(['timezone' => 'Europe/Berlin', 'recurrence' => 'daily', 'localstart' => '02:00:00',
            'localend' => '03:00:00']);
        // Normal day: 02:00-03:00 CET = 01:00-02:00 UTC.
        $this->assertTrue($p->is_active_at(self::utc('2026-03-28 01:30:00')));
        // Gap day: both ends map to 03:00 CEST (01:00 UTC): empty.
        $this->assertFalse($p->is_active_at(self::utc('2026-03-29 00:59:59')));
        $this->assertFalse($p->is_active_at(self::utc('2026-03-29 01:00:00')));
        $this->assertFalse($p->is_active_at(self::utc('2026-03-29 01:59:59')));
    }

    /**
     * Provider for invalid definitions: field => expected error key.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'impossible date' => [['localstart' => '2026-02-30 00:00:00'], 'localstart'],
            'hour 25' => [['recurrence' => 'daily', 'localstart' => '25:00:00', 'localend' => '06:00:00'], 'localstart'],
            'bad format' => [['localend' => 'tomorrow'], 'localend'],
            'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
            'end before start' => [['localstart' => '2026-06-01 10:00:00', 'localend' => '2026-06-01 09:00:00'], 'localend'],
            'zero length' => [['localstart' => '2026-06-01 10:00:00', 'localend' => '2026-06-01 10:00:00'], 'localend'],
            'daily one side' => [['recurrence' => 'daily', 'localstart' => '08:00:00'], 'localstart'],
            'daily empty' => [['recurrence' => 'daily', 'localstart' => '08:00:00', 'localend' => '08:00:00'], 'localend'],
            'no mode' => [['modelearner' => null, 'modeinstructor' => null], 'modelearner'],
            'bad mode' => [['modelearner' => 7], 'modelearner'],
            'bad scope' => [['scope' => 'galaxy'], 'scope'],
            'tool without id' => [['scope' => 'tool', 'scopeid' => 0], 'scopeid'],
            'override without expiry' => [['kind' => 'override', 'localstart' => '2026-06-01 10:00:00'], 'localend'],
            'override daily' => [['kind' => 'override', 'recurrence' => 'daily', 'localstart' => '08:00:00',
                'localend' => '09:00:00'], 'recurrence'],
        ];
    }

    /**
     * Invalid definitions are reported per field.
     *
     * @dataProvider invalid_provider
     * @param array $fields Overrides.
     * @param string $errorfield Field that must carry an error.
     */
    public function test_validation_errors(array $fields, string $errorfield): void {
        $errors = $this->make($fields)->validate();
        $this->assertArrayHasKey($errorfield, $errors);
        $this->assertNotEmpty(get_string_manager()->get_string($errors[$errorfield], 'local_ltiaccountmerge'));
    }

    /**
     * Scope matching and specificity.
     */
    public function test_scope_and_specificity(): void {
        $site = $this->make(['scope' => 'site']);
        $tool = $this->make(['scope' => 'tool', 'scopeid' => 7]);
        $course = $this->make(['scope' => 'course', 'scopeid' => 3]);
        $this->assertTrue($site->applies_to(7, 3));
        $this->assertTrue($tool->applies_to(7, 99));
        $this->assertFalse($tool->applies_to(8, 3));
        $this->assertTrue($course->applies_to(99, 3));
        $this->assertFalse($course->applies_to(7, 4));
        $this->assertGreaterThan($tool->specificity(), $course->specificity());
        $this->assertGreaterThan($site->specificity(), $tool->specificity());
        $this->assertFalse($this->make(['scope' => 'galaxy'])->applies_to(1, 1));
    }

    /**
     * Provider of unusual daylight-saving transitions: half-hour shift, a skipped calendar day, southern hemisphere.
     *
     * @return array
     */
    public static function unusual_transition_provider(): array {
        return [
            // Lord Howe moves by 30 minutes: 02:00 -> 02:30 on 2026-10-04 (+10:30 -> +11:00).
            'half-hour gap' => ['Australia/Lord_Howe', '2026-10-04 02:15:00', '2026-10-03 15:45:00'],
            'half-hour overlap, first occurrence' => ['Australia/Lord_Howe', '2026-04-05 01:45:00', '2026-04-04 14:45:00'],
            // Samoa skipped 2011-12-30 completely: a time on the missing day moves forward by 24 hours.
            'skipped day' => ['Pacific/Apia', '2011-12-30 12:00:00', '2011-12-30 22:00:00'],
            // Sao Paulo (southern hemisphere, midnight transition in 2018).
            'midnight gap' => ['America/Sao_Paulo', '2018-11-04 00:30:00', '2018-11-04 03:30:00'],
            'ordinary time next to a gap' => ['Europe/Berlin', '2026-03-29 03:00:00', '2026-03-29 01:00:00'],
            'last second before the gap' => ['Europe/Berlin', '2026-03-29 01:59:59', '2026-03-29 00:59:59'],
            'ambiguous end of overlap' => ['Europe/Berlin', '2026-10-25 02:59:59', '2026-10-25 00:59:59'],
            'first time after overlap' => ['Europe/Berlin', '2026-10-25 03:00:00', '2026-10-25 02:00:00'],
        ];
    }

    /**
     * Wall-clock resolution is deterministic at every unusual transition.
     *
     * @dataProvider unusual_transition_provider
     * @param string $timezone Timezone.
     * @param string $wall Local wall-clock time.
     * @param string $expectedutc Expected UTC time.
     */
    public function test_unusual_transitions(string $timezone, string $wall, string $expectedutc): void {
        $this->assertSame(self::utc($expectedutc), policy::wall_to_ts($wall, new \DateTimeZone($timezone)));
    }

    /**
     * A daily window whose start lies in the fall-back overlap starts at the FIRST 02:30 and ends at the local 03:30 (CET),
     * so on that night it lasts two real hours.
     */
    public function test_daily_window_starting_in_the_overlap(): void {
        $p = $this->make(['timezone' => 'Europe/Berlin', 'recurrence' => 'daily', 'localstart' => '02:30:00',
            'localend' => '03:30:00']);
        $this->assertFalse($p->is_active_at(self::utc('2026-10-25 00:29:59')));
        $this->assertTrue($p->is_active_at(self::utc('2026-10-25 00:30:00')));
        $this->assertTrue($p->is_active_at(self::utc('2026-10-25 01:30:00')));
        $this->assertTrue($p->is_active_at(self::utc('2026-10-25 02:29:59')));
        $this->assertFalse($p->is_active_at(self::utc('2026-10-25 02:30:00')));
        // The next day is an ordinary 1-hour window again.
        $this->assertTrue($p->is_active_at(self::utc('2026-10-26 01:30:00')));
        $this->assertFalse($p->is_active_at(self::utc('2026-10-26 02:30:00')));
    }

    /**
     * Every second around the spring-forward gap belongs to exactly one side of a window that ends at 03:00 local.
     */
    public function test_no_second_is_lost_or_doubled_around_the_gap(): void {
        $berlin = ['timezone' => 'Europe/Berlin'];
        $before = $this->make($berlin + ['localstart' => '2026-03-29 00:00:00', 'localend' => '2026-03-29 03:00:00']);
        $after = $this->make($berlin + ['localstart' => '2026-03-29 03:00:00', 'localend' => '2026-03-29 06:00:00']);
        for ($t = self::utc('2026-03-29 00:59:50'); $t <= self::utc('2026-03-29 01:00:10'); $t++) {
            $this->assertNotSame($before->is_active_at($t), $after->is_active_at($t), "second $t");
        }
    }
}
