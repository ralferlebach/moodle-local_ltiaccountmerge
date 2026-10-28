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
 * Computes the effective provisioning mode live from a set of policies.
 *
 * Precedence (documented in docs/DOCUMENTATION.md): manual overrides first, then higher priority, then more
 * specific scope (course over tool over site), then the newer policy id. The first active policy that governs
 * the requested role decides; if none does, the result is null, meaning "leave the tool's own configured mode alone".
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resolver {
    /** @var string Role key for learner launches. */
    public const LEARNER = 'learner';
    /** @var string Role key for instructor launches. */
    public const INSTRUCTOR = 'instructor';

    /** @var policy[] Policies in precedence order. */
    protected $policies;

    /**
     * Constructor.
     *
     * @param policy[] $policies Policies (any order).
     */
    public function __construct(array $policies) {
        $this->policies = array_values($policies);
        usort($this->policies, [self::class, 'compare']);
    }

    /**
     * Load all enabled policies from the database.
     *
     * @return self
     */
    public static function from_db(): self {
        global $DB;
        $policies = [];
        foreach ($DB->get_records('local_ltiaccountmerge_policy', ['enabled' => 1]) as $record) {
            $policies[] = policy::from_record($record);
        }
        return new self($policies);
    }

    /**
     * Precedence comparison.
     *
     * @param policy $a First.
     * @param policy $b Second.
     * @return int
     */
    public static function compare(policy $a, policy $b): int {
        $ao = $a->kind === policy::KIND_OVERRIDE ? 1 : 0;
        $bo = $b->kind === policy::KIND_OVERRIDE ? 1 : 0;
        return [$bo, $b->priority, $b->specificity(), $b->id] <=> [$ao, $a->priority, $a->specificity(), $a->id];
    }

    /**
     * Number of policies loaded.
     *
     * @return int
     */
    public function count(): int {
        return count($this->policies);
    }

    /**
     * Effective mode for one role of one resource at one instant.
     *
     * @param string $role resolver::LEARNER or resolver::INSTRUCTOR.
     * @param int $toolid enrol_lti_tools.id.
     * @param int $courseid Course of the tool.
     * @param int $ts Unix timestamp.
     * @return array{mode: ?int, policy: ?policy}
     */
    public function effective_mode(string $role, int $toolid, int $courseid, int $ts): array {
        foreach ($this->policies as $policy) {
            $mode = $role === self::INSTRUCTOR ? $policy->modeinstructor : $policy->modelearner;
            if ($mode === null || !$policy->applies_to($toolid, $courseid) || !$policy->is_active_at($ts)) {
                continue;
            }
            return ['mode' => $mode, 'policy' => $policy];
        }
        return ['mode' => null, 'policy' => null];
    }

    /**
     * Effective modes for both roles.
     *
     * @param int $toolid enrol_lti_tools.id.
     * @param int $courseid Course of the tool.
     * @param int $ts Unix timestamp.
     * @return array{learner: array, instructor: array}
     */
    public function effective(int $toolid, int $courseid, int $ts): array {
        return [
            self::LEARNER => $this->effective_mode(self::LEARNER, $toolid, $courseid, $ts),
            self::INSTRUCTOR => $this->effective_mode(self::INSTRUCTOR, $toolid, $courseid, $ts),
        ];
    }
}
