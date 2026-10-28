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
 * A time-based provisioning policy: a pure value object that can answer "am I active at time T".
 *
 * No database or global state is used by the evaluation methods, so the effective mode can be computed
 * live for any timestamp (launch time, preview, tests). Windows are stored as local wall-clock times plus
 * an IANA timezone and resolved to instants at evaluation time, which keeps DST transitions correct:
 * a wall-clock time that does not exist (spring-forward gap) is moved forward by the gap length, an
 * ambiguous one (fall-back overlap) resolves to its first occurrence. Windows are half-open: the start
 * second is inside, the end second is outside.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class policy {
    /** @var string Scope: all tools. */
    public const SCOPE_SITE = 'site';
    /** @var string Scope: one published resource (enrol_lti_tools.id). */
    public const SCOPE_TOOL = 'tool';
    /** @var string Scope: every published resource in one course. */
    public const SCOPE_COURSE = 'course';
    /** @var string Kind: regular scheduled window. */
    public const KIND_SCHEDULED = 'scheduled';
    /** @var string Kind: manual override with mandatory expiry. */
    public const KIND_OVERRIDE = 'override';
    /** @var string Recurrence: one absolute window. */
    public const REC_ONCE = 'once';
    /** @var string Recurrence: a time-of-day window repeating every day. */
    public const REC_DAILY = 'daily';

    /** @var int */
    public $id;
    /** @var string */
    public $name;
    /** @var string */
    public $scope;
    /** @var int */
    public $scopeid;
    /** @var string */
    public $kind;
    /** @var int */
    public $priority;
    /** @var bool */
    public $enabled;
    /** @var string */
    public $timezone;
    /** @var string */
    public $recurrence;
    /** @var string|null */
    public $localstart;
    /** @var string|null */
    public $localend;
    /** @var int|null */
    public $modelearner;
    /** @var int|null */
    public $modeinstructor;

    /**
     * Build from a database record or an equivalent object.
     *
     * @param \stdClass $r The record.
     * @return self
     */
    public static function from_record(\stdClass $r): self {
        $p = new self();
        $p->id = (int) ($r->id ?? 0);
        $p->name = (string) ($r->name ?? '');
        $p->scope = (string) ($r->scope ?? self::SCOPE_SITE);
        $p->scopeid = (int) ($r->scopeid ?? 0);
        $p->kind = (string) ($r->kind ?? self::KIND_SCHEDULED);
        $p->priority = (int) ($r->priority ?? 0);
        $p->enabled = !isset($r->enabled) || (bool) $r->enabled;
        $p->timezone = (string) ($r->timezone ?? 'UTC');
        $p->recurrence = (string) ($r->recurrence ?? self::REC_ONCE);
        $p->localstart = ($r->localstart ?? '') === '' ? null : (string) $r->localstart;
        $p->localend = ($r->localend ?? '') === '' ? null : (string) $r->localend;
        $p->modelearner = ($r->modelearner ?? null) === null || $r->modelearner === '' ? null : (int) $r->modelearner;
        $p->modeinstructor = ($r->modeinstructor ?? null) === null || $r->modeinstructor === '' ? null : (int) $r->modeinstructor;
        return $p;
    }

    /**
     * Validate the definition. Returns problems keyed by field, empty when valid.
     *
     * @return string[] Map of field name => language string identifier of the problem.
     */
    public function validate(): array {
        $errors = [];
        if (!in_array($this->scope, [self::SCOPE_SITE, self::SCOPE_TOOL, self::SCOPE_COURSE], true)) {
            $errors['scope'] = 'err_scope';
        } else if ($this->scope !== self::SCOPE_SITE && $this->scopeid <= 0) {
            $errors['scopeid'] = 'err_scopeid';
        }
        if (!in_array($this->kind, [self::KIND_SCHEDULED, self::KIND_OVERRIDE], true)) {
            $errors['kind'] = 'err_kind';
        }
        if (!in_array($this->timezone, \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'err_timezone';
        }
        if (!in_array($this->recurrence, [self::REC_ONCE, self::REC_DAILY], true)) {
            $errors['recurrence'] = 'err_recurrence';
        }
        if ($this->modelearner === null && $this->modeinstructor === null) {
            $errors['modelearner'] = 'err_nomode';
        }
        if ($this->modelearner !== null && !modes::is_valid($this->modelearner)) {
            $errors['modelearner'] = 'err_mode';
        }
        if ($this->modeinstructor !== null && !modes::is_valid($this->modeinstructor)) {
            $errors['modeinstructor'] = 'err_mode';
        }
        if (isset($errors['recurrence']) || isset($errors['timezone'])) {
            return $errors;
        }
        $format = $this->recurrence === self::REC_ONCE ? 'Y-m-d H:i:s' : 'H:i:s';
        foreach (['localstart', 'localend'] as $field) {
            $value = $this->$field;
            if ($value !== null && !self::parse_local($value, $format, new \DateTimeZone($this->timezone), true)) {
                $errors[$field] = 'err_datetime';
            }
        }
        if ($this->recurrence === self::REC_DAILY) {
            if ($this->localstart === null || $this->localend === null) {
                $errors['localstart'] = 'err_dailyboth';
            } else if (!isset($errors['localstart']) && !isset($errors['localend']) && $this->localstart === $this->localend) {
                $errors['localend'] = 'err_emptywindow';
            }
        } else if (!isset($errors['localstart']) && !isset($errors['localend'])) {
            if ($this->localstart !== null && $this->localend !== null && $this->start_ts() >= $this->end_ts()) {
                $errors['localend'] = 'err_endbeforestart';
            }
        }
        if ($this->kind === self::KIND_OVERRIDE) {
            if ($this->recurrence !== self::REC_ONCE) {
                $errors['recurrence'] = 'err_overrideonce';
            }
            if ($this->localend === null && !isset($errors['localend'])) {
                $errors['localend'] = 'err_overrideexpiry';
            }
        }
        return $errors;
    }

    /**
     * Whether this policy governs the given resource.
     *
     * @param int $toolid enrol_lti_tools.id.
     * @param int $courseid Course the tool belongs to.
     * @return bool
     */
    public function applies_to(int $toolid, int $courseid): bool {
        switch ($this->scope) {
            case self::SCOPE_SITE:
                return true;
            case self::SCOPE_TOOL:
                return $this->scopeid === $toolid;
            case self::SCOPE_COURSE:
                return $this->scopeid === $courseid;
        }
        return false;
    }

    /**
     * Specificity rank used as a tie-breaker: course > tool > site.
     *
     * @return int
     */
    public function specificity(): int {
        switch ($this->scope) {
            case self::SCOPE_COURSE:
                return 3;
            case self::SCOPE_TOOL:
                return 2;
        }
        return 1;
    }

    /**
     * Whether the policy is enabled and its window contains the instant.
     *
     * @param int $ts Unix timestamp.
     * @return bool
     */
    public function is_active_at(int $ts): bool {
        if (!$this->enabled || $this->validate()) {
            return false;
        }
        if ($this->recurrence === self::REC_ONCE) {
            $start = $this->start_ts();
            $end = $this->end_ts();
            return ($start === null || $ts >= $start) && ($end === null || $ts < $end);
        }
        return $this->daily_active($ts);
    }

    /**
     * Start instant of a one-off window.
     *
     * @return int|null
     */
    public function start_ts(): ?int {
        if ($this->recurrence !== self::REC_ONCE || $this->localstart === null) {
            return null;
        }
        return self::wall_to_ts($this->localstart, new \DateTimeZone($this->timezone));
    }

    /**
     * End instant (exclusive) of a one-off window.
     *
     * @return int|null
     */
    public function end_ts(): ?int {
        if ($this->recurrence !== self::REC_ONCE || $this->localend === null) {
            return null;
        }
        return self::wall_to_ts($this->localend, new \DateTimeZone($this->timezone));
    }

    /**
     * Daily recurring window test; looks at the window that started yesterday as well, so windows that
     * cross local midnight are handled.
     *
     * @param int $ts Unix timestamp.
     * @return bool
     */
    protected function daily_active(int $ts): bool {
        $tz = new \DateTimeZone($this->timezone);
        $local = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $wraps = strcmp($this->localend, $this->localstart) < 0;
        foreach ([-1, 0] as $offset) {
            $day = $local->modify($offset . ' day')->format('Y-m-d');
            $endday = $wraps ? (new \DateTimeImmutable($day, $tz))->modify('+1 day')->format('Y-m-d') : $day;
            $start = self::wall_to_ts($day . ' ' . $this->localstart, $tz);
            $end = self::wall_to_ts($endday . ' ' . $this->localend, $tz);
            if ($ts >= $start && $ts < $end) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve a local wall-clock time to an instant with a DETERMINISTIC DST rule (independent of the PHP version):
     * a time inside the spring-forward gap is moved forward by the gap length, an ambiguous time inside the
     * fall-back overlap resolves to its FIRST occurrence (before the clocks go back).
     *
     * @param string $wall Y-m-d H:i:s wall-clock time.
     * @param \DateTimeZone $tz Timezone.
     * @return int Unix timestamp.
     */
    public static function wall_to_ts(string $wall, \DateTimeZone $tz): int {
        $naive = (new \DateTimeImmutable($wall, new \DateTimeZone('UTC')))->getTimestamp();
        $transitions = $tz->getTransitions($naive - 2 * DAYSECS, $naive + 2 * DAYSECS);
        $first = array_shift($transitions);
        $offset = (int) $first['offset'];
        foreach ($transitions as $transition) {
            $old = $offset;
            $new = (int) $transition['offset'];
            $at = (int) $transition['ts'];
            if ($new < $old) {
                // Clocks go back: the wall times [at + new, at + old) happen twice.
                if ($naive < $at + $new) {
                    break;
                }
                if ($naive < $at + $old) {
                    return $naive - $old;
                }
            } else {
                // Clocks go forward: the wall times [at + old, at + new) do not exist.
                if ($naive < $at + $old) {
                    break;
                }
                if ($naive < $at + $new) {
                    return $naive - $old;
                }
            }
            $offset = $new;
        }
        return $naive - $offset;
    }

    /**
     * Strict parse of a local date-time string.
     *
     * @param string $value The text.
     * @param string $format DateTime format.
     * @param \DateTimeZone $tz Timezone.
     * @param bool $strict Reject strings PHP would "correct" (e.g. 25:00:00, February 30th).
     * @return \DateTimeImmutable|null
     */
    protected static function parse_local(string $value, string $format, \DateTimeZone $tz, bool $strict): ?\DateTimeImmutable {
        $dt = \DateTimeImmutable::createFromFormat('!' . $format, $value, $tz);
        if (!$dt) {
            return null;
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($strict && $errors && ($errors['warning_count'] || $errors['error_count'])) {
            return null;
        }
        return $dt;
    }
}
