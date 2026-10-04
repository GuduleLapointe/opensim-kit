#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * opensim next: the next free port or place, by the rules of the setup (the
 * same code, so that every script gives the same answer).
 *
 *   opensim next port [-e PORT] [MIN] [COUNT]
 *       COUNT free ports from MIN (default 9010), none of them in a config file,
 *       bound on this machine, or given with -e
 *   opensim next GRID location [X,Y] [COUNT] [-e X,Y]
 *       COUNT free places nearest to X,Y (default: the center of the grid) in the grid, by its
 *       rule (the free blocks it leaves between its regions); the places given
 *       with -e are taken
 *
 * -e can be given several times.
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSim\Installer\Config;
use OpenSim\Installer\Grid\Database;
use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Grid\GridRegistry;
use OpenSim\Installer\Grid\LocationFinder;
use OpenSim\Installer\Grid\Places;
use OpenSim\Installer\Ports;
use OpenSim\Installer\Ui\QuietUi;

const USAGE = "usage: opensim next port [-e PORT] [MIN] [COUNT]\n       opensim next GRID location [X,Y] [COUNT] [-e X,Y]\n";

/** Say what is wrong and stop. */
function usage(string $message = ''): never
{
    fwrite(STDERR, ($message !== '' ? "next: $message\n" : '') . USAGE);
    exit($message === '' ? 0 : 2);
}

$what = $argv[1] ?? '';
if ($what === '-h' || $what === '--help') {
    echo USAGE;
    exit(0);
}
// opensim next GRID location ...: the grid comes first, as the instance does in the other commands
$gridFirst = null;
$rest = array_slice($argv, 2);
if ($what !== '' && !in_array($what, ['port', 'location'], true) && ($argv[2] ?? '') === 'location') {
    $gridFirst = $what;
    $what = 'location';
    $rest = array_slice($argv, 3);
}
if (!in_array($what, ['port', 'location'], true)) {
    usage($what === '' ? 'port or location?' : "unknown: $what");
}

$exclude = [];
$args = $gridFirst === null ? [] : [$gridFirst];
while ($rest !== []) {
    $arg = array_shift($rest);
    if ($arg === '-e') {
        $exclude[] = (string) array_shift($rest);
    } elseif ($arg === '-h' || $arg === '--help') {
        usage();
    } else {
        $args[] = $arg;
    }
}

$number = static fn(?string $value, int $default): int => $value === null
    ? $default
    : (ctype_digit($value)
        ? (int) $value
        : -1);

if ($what === 'port') {
    $min = $number($args[0] ?? null, 9010);
    $count = $number($args[1] ?? null, 1);
    if (
        $min < 1 ||
        $count < 1 ||
        count($args) > 2 ||
        array_filter($exclude, static fn(string $e): bool => !ctype_digit($e)) !== []
    ) {
        usage('MIN, COUNT and the ports given with -e are numbers');
    }
    echo implode("\n", Ports::nextFree($min, $count, array_map('intval', $exclude))), "\n";
    exit(0);
}

$nick = $args[0] ?? '';
$wish = isset($args[1]) ? LocationFinder::parse($args[1]) : [];
$count = $number($args[2] ?? null, 1);
if ($nick === '' || $wish === null || $count < 1 || count($args) > 3) {
    usage('a grid, then a place as x,y and a number');
}

$grid = GridInfo::load((new Config())->profile(), $nick);
if ($grid === null) {
    fwrite(STDERR, "next: no grid named '$nick'\n");
    exit(1);
}

$known = (new GridRegistry(new Database(new QuietUi())))->locations($grid);
foreach ($exclude as $place) {
    if (($taken = LocationFinder::parse($place)) === null) {
        usage("'$place' is not a place, use x,y");
    }
    LocationFinder::take($known, $taken[0], $taken[1]);
}

$wish = $wish ?: LocationFinder::center($known, $grid->publicPort);
$unreachable = false;
for ($i = 0; $i < $count; $i++) {
    [$x, $y] = Places::nearestFree($grid, $known, $wish[0], $wish[1], null, $unreachable);
    echo "$x,$y\n";
    LocationFinder::take($known, $x, $y);
}
if ($unreachable) {
    fwrite(
        STDERR,
        "next: the grid does not answer at {$grid->baseHostname}:{$grid->privatePort}, its regions are not all known here\n",
    );
}

