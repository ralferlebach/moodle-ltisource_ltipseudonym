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
 * Concurrency check: parallel first launches of one user produce exactly one mapping.
 *
 * @package    ltisource_ltipseudonym
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Usage: php race.php [processes]. Disposable development sites only (writes and removes mapping rows).
define('CLI_SCRIPT', true); // phpcs:ignore moodle.Files.MoodleInternal.MoodleInternalGlobalState

require(__DIR__ . '/../../../../../../config.php');

$typeid = 999999;
$procs = 8;
$worker = false;
$start = 0.0;
$userid = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--worker=') === 0) {
        $worker = true;
        [$userid, $start] = array_map('floatval', explode(',', substr($arg, 9)));
        $userid = (int) $userid;
    } else {
        $procs = max(2, (int) $arg);
    }
}

if ($worker) {
    // Spin until the common start instant so all processes hit the insert path together.
    while (microtime(true) < $start) {
        usleep(200);
    }
    $service = new \ltisource_ltipseudonym\local\token_service();
    echo $service->get_or_create($userid, $typeid, 'deployment', $typeid, 'random_persisted'), "\n";
    exit(0);
}

$userid = 2000000000 + random_int(0, 99999);
$DB->delete_records('ltisource_ltipseudonym_map', ['userid' => $userid]);
$start = microtime(true) + 3.0;
$children = [];
for ($i = 0; $i < $procs; $i++) {
    $cmd = [PHP_BINARY, __FILE__, '--worker=' . $userid . ',' . $start];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $children[] = [$proc, $pipes];
}
$tokens = [];
$failed = 0;
foreach ($children as [$proc, $pipes]) {
    $tokens[] = trim(stream_get_contents($pipes[1]));
    stream_get_contents($pipes[2]);
    if (proc_close($proc) !== 0) {
        $failed++;
    }
}
$rows = $DB->count_records('ltisource_ltipseudonym_map', ['userid' => $userid]);
$distinct = count(array_unique($tokens));
$DB->delete_records('ltisource_ltipseudonym_map', ['userid' => $userid]);

echo "processes: $procs, failed: $failed, mapping rows: $rows, distinct tokens returned: $distinct\n";
$ok = ($failed === 0 && $rows === 1 && $distinct === 1);
echo $ok ? "PASS\n" : "FAIL\n";
exit($ok ? 0 : 1);
