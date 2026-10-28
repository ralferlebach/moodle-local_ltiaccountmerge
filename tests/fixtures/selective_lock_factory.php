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

namespace local_ltiaccountmerge\tests\fixtures;

/**
 * Lock factory that grants every lock except the resources marked busy (another process holds them).
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class selective_lock_factory implements \core\lock\lock_factory {
    /** @var string[] Resources that are held elsewhere. */
    public $busy = [];
    /** @var string[] Resources held right now through this factory. */
    public $held = [];

    /**
     * Constructor.
     *
     * @param string $type Unused.
     */
    public function __construct($type = '') {
        unset($type);
    }

    /**
     * Available.
     *
     * @return bool
     */
    public function is_available() {
        return true;
    }

    /**
     * Timeouts supported.
     *
     * @return bool
     */
    public function supports_timeout() {
        return true;
    }

    /**
     * Auto release supported.
     *
     * @return bool
     */
    public function supports_auto_release() {
        return true;
    }

    /**
     * Grant unless busy or already held.
     *
     * @param string $resource Resource.
     * @param int $timeout Timeout.
     * @param int $maxlifetime Lifetime.
     * @return \core\lock\lock|false
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        if (in_array($resource, $this->busy, true) || isset($this->held[$resource])) {
            return false;
        }
        $this->held[$resource] = true;
        return new \core\lock\lock($resource, $this);
    }

    /**
     * Release.
     *
     * @param \core\lock\lock $lock Lock.
     * @return bool
     */
    public function release_lock(\core\lock\lock $lock) {
        unset($this->held[$lock->get_key()]);
        return true;
    }
}
