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
 * CLI of the reviewed merge workflow: preview, consent, execute, recover.
 *
 * Examples:
 *   php local/ltiaccountmerge/cli/merge.php --source=12 --target=34 --preview
 *   php local/ltiaccountmerge/cli/merge.php --source=12 --target=34 --consent=<fingerprint> --confirm=<username of 12>
 *       [--acceptconflicts] [--run]
 *   php local/ltiaccountmerge/cli/merge.php --execute=<jobid>
 *   php local/ltiaccountmerge/cli/merge.php --recover
 *
 * The consent is recorded for the main administrator (CLI has no session user). Output is JSON; the preview lists table
 * names, strategies and counters only.
 *
 * @package    local_ltiaccountmerge
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_ltiaccountmerge\local\merge\executor;

[$options, $unrecognised] = cli_get_params([
    'source' => 0, 'target' => 0, 'preview' => false, 'consent' => '', 'confirm' => '', 'acceptconflicts' => false,
    'run' => false, 'execute' => 0, 'recover' => false, 'help' => false,
], ['h' => 'help']);
if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}
if ($options['help'] || (!$options['preview'] && $options['consent'] === '' && !$options['execute'] && !$options['recover'])) {
    cli_writeln("Reviewed account merge of local_ltiaccountmerge (needs tool_mergeusers).
Options:
  --source=ID --target=ID --preview                    Dry run (JSON).
  --source=ID --target=ID --consent=HASH --confirm=USERNAME [--acceptconflicts] [--run]
                                                       Consent to the preview with that fingerprint; --run executes now,
                                                       otherwise an ad hoc task is queued.
  --execute=JOBID                                      Execute a consented job now.
  --recover                                            Resolve jobs of crashed processes, expire old consents.");
    exit(0);
}
\core\session\manager::set_user(get_admin());
$executor = new executor();
$out = function (array $data): void {
    cli_writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
};
try {
    if ($options['recover']) {
        $out(['recover' => $executor->recover()]);
        exit(0);
    }
    if ($options['execute']) {
        $status = $executor->execute((int) $options['execute']);
        $out(['job' => (int) $options['execute'], 'status' => $status]);
        exit(in_array($status, [executor::SUCCEEDED], true) ? 0 : 3);
    }
    $source = (int) $options['source'];
    $target = (int) $options['target'];
    if ($options['preview']) {
        $report = $executor->preview($source, $target);
        $out(['verdict' => $report->verdict, 'hash' => $report->hash, 'blockers' => $report->blockers,
            'conflicts' => $report->conflicts] + \local_ltiaccountmerge\local\merge_preview::summary($report));
        exit(0);
    }
    $job = $executor->consent(
        $source,
        $target,
        (string) $options['consent'],
        (string) $options['confirm'],
        (bool) $options['acceptconflicts'],
        (int) get_admin()->id,
        !$options['run']
    );
    $result = ['job' => (int) $job->id, 'status' => $job->status];
    if ($options['run']) {
        $result['status'] = $executor->execute((int) $job->id);
    }
    $out($result);
    exit(in_array($result['status'], [executor::SUCCEEDED, executor::CONSENTED], true) ? 0 : 3);
} catch (moodle_exception $e) {
    $out(['error' => $e->errorcode]);
    exit(2);
}
