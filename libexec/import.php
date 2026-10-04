#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * opensim import: bring what a file holds into the install, a grid, its simulators, its accounts.
 *
 *   opensim import [GRID] FILE [--grid] [--simulator] [--users] [--apply] [--core DIR] [--name NAME]
 *                              [--keep-going] [--result FILE] [--format csv|json|yaml]
 *
 * FILE is what it is: the config of a grid that was not made by the kit (a Robust ini), of a simulator (an
 * OpenSim ini), a setup file of the kit (JSON or YAML: the grid, its simulators and regions, the accounts), or a
 * list of accounts (CSV, JSON or YAML). Everything it holds is imported, or only what --grid, --simulator and
 * --users ask for.
 *
 * GRID, first, is the nick of the grid to make, or the grid the simulators and accounts join. It can be left out
 * when FILE tells it (a setup file does) or when the machine has only one grid.
 *
 * Nothing is written without --apply: what would be made is told.
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSim\Installer\Config;
use OpenSim\Installer\Setup\SetupFile;
use OpenSim\Installer\Setup\SetupRunner;
use OpenSim\Installer\SetupFailed;
use OpenSim\Installer\Ui\PromptsUi;
use OpenSim\Installer\Ui\QuietUi;

const USAGE = "usage: opensim import [GRID] FILE [--grid] [--simulator] [--users] [--apply] [--core DIR] [--name NAME]\n";

/** Say what is wrong and stop. */
function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "import: $message\n");
    exit($code);
}

/** What kind of file it is: robust (a Robust ini), sim (an OpenSim ini), setup (a setup of the kit) or accounts. */
function kind(string $path, string $format): string
{
    $text = (string) file_get_contents($path);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($format === 'csv' || ($format === '' && $ext === 'csv')) {
        return 'accounts';
    }
    $isYaml = in_array($format ?: $ext, ['yaml', 'yml'], true);
    $json =
        in_array($format ?: $ext, ['json'], true) || preg_match('/^\s*[\[{]/', $text) === 1
            ? json_decode($text, true)
            : null;
    // An ini starts with a [Section], which a list of JSON does too: it is JSON when it reads as JSON
    if ($isYaml || $format === 'json' || $ext === 'json' || is_array($json)) {
        $data = $isYaml ? yamlData($text) : $json;
        if (!is_array($data)) {
            fail("$path is not a list of settings or of accounts", 2);
        }

        return array_is_list($data) ||
            (isset($data['accounts']) && count($data) === 1) ||
            (isset($data['users']) && count($data) === 1)
            ? 'accounts'
            : 'setup';
    }
    if (preg_match('/^\s*\[(?:ServiceList|GridInfoService|LoginService|Hypergrid|GridService)\]/mi', $text) === 1) {
        return 'robust';
    }
    if (preg_match('/^\s*\[(?:Startup|Estates|Network|Architecture)\]/mi', $text) === 1) {
        return 'sim';
    }
    if (preg_match('/^\s*\[\w+\]\s*$/m', $text) !== 1) {
        return 'accounts'; // a list with no sections: the lines of a spreadsheet
    }

    fail("$path is not the config of a grid or of a simulator, nor a setup, nor a list of accounts", 2);
}

function yamlData(string $text): mixed
{
    try {
        return \Symfony\Component\Yaml\Yaml::parse($text);
    } catch (\Symfony\Component\Yaml\Exception\ParseException) {
        return null;
    }
}

/** A worker of this folder, with its output and its exit code as they are. */
function worker(string $script, array $args): never
{
    $command = array_merge([PHP_BINARY, __DIR__ . "/$script"], $args);
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    exit(is_resource($process) ? proc_close($process) : 1);
}

$args = array_slice($argv, 1);
if ($args === [] || in_array($args[0], ['-h', '--help'], true)) {
    echo USAGE;
    exit($args === [] ? 2 : 0);
}

$options = ['core' => '', 'name' => '', 'result' => '', 'format' => ''];
$only = ['grid' => false, 'simulator' => false, 'users' => false];
$apply = false;
$keepGoing = false;
$words = [];
for ($i = 0; $i < count($args); $i++) {
    if (preg_match('/^--(core|name|result|format)(?:=(.*))?$/', $args[$i], $m)) {
        $options[$m[1]] = $m[2] ?? ($args[++$i] ?? fail("--{$m[1]} needs a value", 2));
    } elseif (preg_match('/^--(grid|simulator|users)$/', $args[$i], $m)) {
        $only[$m[1]] = true;
    } elseif ($args[$i] === '--apply') {
        $apply = true;
    } elseif ($args[$i] === '--keep-going') {
        $keepGoing = true;
    } else {
        $words[] = $args[$i];
    }
}
if ($options['format'] !== '' && !in_array($options['format'], ['csv', 'json', 'yaml'], true)) {
    fail('the format is csv, json or yaml', 2);
}

// The file is the last word that is one; what comes before it is the grid
$grid = '';
$file = '';
if (count($words) === 2 && is_readable($words[1])) {
    [$grid, $file] = $words;
} elseif (count($words) === 1 && is_readable($words[0])) {
    $file = $words[0];
} else {
    fwrite(STDERR, USAGE);
    exit(2);
}
$path = (string) realpath($file);
$kind = kind($path, $options['format']);
$all = !in_array(true, $only, true);

// What the file holds, and what was asked of it
$holds = match ($kind) {
    'robust' => ['grid'],
    'sim' => ['simulator'],
    'accounts' => ['users'],
    default => ['grid', 'simulator', 'users'],
};
$wanted = array_values(array_filter($holds, static fn(string $part): bool => $all || $only[$part]));
if ($wanted === []) {
    fail(
        "nothing to import: $file holds " .
            ($kind === 'robust'
                ? 'the config of a grid'
                : ($kind === 'sim'
                    ? 'the config of a simulator'
                    : 'a list of accounts')) .
            ', not what ' .
            implode(' and ', array_keys(array_filter($only))) .
            ' asks for',
        2,
    );
}

$extra = $apply ? ['--apply'] : [];
if ($kind === 'robust') {
    worker(
        'import-settings.php',
        array_merge(
            $grid !== '' ? [$grid] : [],
            ['robust', $path],
            $options['core'] !== '' ? ['--core', $options['core']] : [],
            $extra,
        ),
    );
}
if ($kind === 'sim') {
    worker(
        'import-settings.php',
        array_merge(
            $grid !== '' ? [$grid] : [],
            ['sim', $path],
            $options['core'] !== '' ? ['--core', $options['core']] : [],
            $options['name'] !== '' ? ['--name', $options['name']] : [],
            $extra,
        ),
    );
}
if ($kind === 'accounts') {
    worker(
        'import-users.php',
        array_merge(
            $grid !== '' ? [$grid] : [],
            ['import', $path],
            $options['result'] !== '' ? ['--result', $options['result']] : [],
            $options['format'] !== '' ? ['--format', $options['format']] : [],
            $keepGoing ? ['--keep-going'] : [],
            $extra,
        ),
    );
}

// A setup file of the kit: its grid, its simulators, its accounts
try {
    $data = SetupFile::load($path);
} catch (InvalidArgumentException $e) {
    fail($e->getMessage(), 2);
}
if (!in_array('grid', $wanted, true)) {
    // The grid is one that is made already: the one named, or the one the file names
    $nick = $grid !== '' ? $grid : (string) ($data['grid']['nick'] ?? '');
    $data['grid'] = $nick !== '' ? ['nick' => $nick] : [];
    if ($data['grid'] === []) {
        unset($data['grid']);
    }
} elseif ($grid !== '') {
    $data['grid']['nick'] = $grid;
}
if (!in_array('simulator', $wanted, true)) {
    unset($data['simulators']);
}
if (!in_array('users', $wanted, true)) {
    unset($data['users']);
}
if (!in_array('grid', $wanted, true) && !in_array('simulator', $wanted, true)) {
    unset($data['owner']);
}

$problems = SetupFile::problems($data);
if ($problems !== []) {
    fail(implode("\n", $problems), 2);
}

if (!$apply) {
    $made = [];
    if (trim((string) ($data['grid']['name'] ?? '')) !== '') {
        $made[] = "the grid {$data['grid']['name']}";
    }
    $regions = array_sum(
        array_map(static fn(array $s): int => max(1, count($s['regions'] ?? [])), $data['simulators'] ?? []),
    );
    if (!empty($data['simulators'])) {
        $made[] = count($data['simulators']) . ' simulator(s) with ' . $regions . ' region(s)';
    }
    if (!empty($data['owner']['name'])) {
        $made[] = "the account {$data['owner']['name']}, owner of the estate";
    }
    if (!empty($data['users'])) {
        $made[] = count($data['users']) . ' account(s)';
    }
    echo 'It would make: ' . implode(', ', $made) . ".\nNothing was made: --apply makes it.\n";
    exit(0);
}

try {
    $made = (new SetupRunner(new PromptsUi()))->run($data, [], $options['result']);
} catch (SetupFailed) {
    exit(1);
}
exit($made === null ? 1 : 0);

