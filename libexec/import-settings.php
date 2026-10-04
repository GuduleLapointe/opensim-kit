#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * The settings of a grid or of a simulator that was not made by the kit, ported without changing its files. Run by
 * `opensim import` (libexec/import.php), which is the command: this is its worker for an ini file.
 *
 *   opensim import [NICK] robust FILE [--core DIR] [--apply]
 *   opensim import GRID sim FILE [--name NAME] [--core DIR] [--apply]
 *
 * The grid comes first, as the instance does in the other commands: the nick of the grid to make (the one of its
 * config when left out), or the grid the simulator joins (--nick and --grid work too).
 *
 * What the setup asks is detected in the config (name, ports, database, console...) and goes through the
 * generators of the kit: a standard config, valid for the core chosen, in /etc/opensim/grids/<nick>/. The other
 * values of the original that differ from the defaults of the core are injected into it, so the settings of the
 * user are kept; the places of the data stay where they are (paths made absolute). A simulator's regions are
 * copied, with their UUID. Nothing is written without --apply: the plan and the settings that would be kept are told.
 *
 * Nothing is enabled or started: compare, then `opensim enable <instance>`.
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSim\Installer\Actions;
use OpenSim\Installer\Config;
use OpenSim\Installer\Elevated;
use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Grid\NewGrid;
use OpenSim\Installer\Grid\NewSim;
use OpenSim\Installer\Grid\SimConfig;
use OpenSim\Installer\Import\IniReader;
use OpenSim\Installer\Import\RobustImporter;
use OpenSim\Installer\Import\SimImporter;
use OpenSim\Installer\SetupFailed;
use OpenSim\Installer\Ui\QuietUi;

const USAGE = "usage: opensim import [NICK] robust FILE [--core DIR] [--apply]\n       opensim import GRID sim FILE [--name NAME] [--core DIR] [--apply]\n";

/** Say what is wrong and stop. */
function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "import: $message\n");
    exit($code);
}

/** Do something to the install as the user who owns it. */
function act(array $profile, string $op, array $args): bool
{
    if (!Elevated::needed($profile)) {
        return (new Actions(new QuietUi()))->perform($op, $args, $profile);
    }
    try {
        Elevated::run(
            new QuietUi(),
            '--apply-action',
            ['op' => $op, 'args' => $args, 'profile' => $profile],
            $profile['SystemUser'],
        );
    } catch (SetupFailed) {
        return false;
    }

    return true;
}

/**
 * The text that says what was found.
 *
 * @param list<string> $lines what the plan says
 * @param list<array{section:string,key:string,value:string,unknown:bool}> $customizations
 */
function report(array $lines, array $customizations): string
{
    $text =
        implode("\n", $lines) .
        "\n\n" .
        count($customizations) .
        " setting(s) of the original kept in the new config:\n";
    foreach ($customizations as $c) {
        $text .= sprintf(
            "  [%s] %s = %s%s\n",
            $c['section'],
            $c['key'],
            $c['value'],
            $c['unknown'] ? '   (not in the example of this core: it may be ignored)' : '',
        );
    }
    $unknown = count(array_filter($customizations, static fn(array $c): bool => $c['unknown']));

    return $text .
        ($unknown > 0 ? "\n$unknown of them are not in the example of this core: look at them first.\n" : '');
}

$args = array_slice($argv, 1);
if (in_array($args[0] ?? '', ['-h', '--help'], true)) {
    echo USAGE;
    exit(0);
}
// opensim import [GRID] robust|sim FILE: the grid comes first, as the instance does in the other commands (the
// nick of the grid to make, or the grid the simulator joins)
$grid = '';
if (!in_array($args[0] ?? '', ['robust', 'sim'], true) && !str_starts_with($args[0] ?? '-', '-')) {
    $grid = array_shift($args);
}
$what = $args[0] ?? '';
if (!in_array($what, ['robust', 'sim'], true)) {
    fwrite(STDERR, USAGE);
    exit(2);
}
array_shift($args);

$options = [
    'nick' => $what === 'robust' ? $grid : '',
    'core' => '',
    'grid' => $what === 'sim' ? $grid : '',
    'name' => '',
];
$apply = false;
$words = [];
for ($i = 0; $i < count($args); $i++) {
    if (preg_match('/^--(nick|core|grid|name)(?:=(.*))?$/', $args[$i], $m)) {
        $options[$m[1]] = $m[2] ?? ($args[++$i] ?? fail("--{$m[1]} needs a value", 2));
    } elseif ($args[$i] === '--apply') {
        $apply = true;
    } else {
        $words[] = $args[$i];
    }
}
if (count($words) !== 1 || !is_readable($words[0])) {
    fwrite(STDERR, USAGE);
    exit(2);
}
$path = (string) realpath($words[0]);

$profile = (new Config())->profile();
if (($profile['EtcRoot'] ?? '') === '') {
    fail('no installed framework found: install an OpenSim core first', 2);
}
$raw = IniReader::load($path);

if ($what === 'robust') {
    $core = $options['core'] !== '' ? rtrim($options['core'], '/') : (string) ($profile['CoreDirectory'] ?? '');
    if ($core === '' || !is_dir("$core/bin")) {
        fail('choose the core the grid will run with: --core DIR (the folder that has bin/)', 2);
    }
    $plan = RobustImporter::plan($raw, $path, $profile, $options['nick'] ?: null, $core);
    if (is_string($plan)) {
        fail($plan, 2);
    }
    $exampleFile = "{$plan->binDir}/" . ($plan->enableHypergrid ? 'Robust.HG.ini.example' : 'Robust.ini.example');
    if (!is_file($exampleFile)) {
        fail("the core has no $exampleFile", 2);
    }
    if (is_file($plan->robustIni())) {
        fail(
            "the grid {$plan->gridNick} is already configured ({$plan->robustIni()}): choose another nick: opensim import <nick> robust FILE",
            2,
        );
    }

    $customizations = RobustImporter::customizations(
        $raw,
        IniReader::parse(RobustImporter::generate($plan)),
        IniReader::load($exampleFile),
        dirname($path),
        (string) file_get_contents($exampleFile),
        IniReader::expand($raw),
    );
    $report = report(
        [
            "Grid {$plan->gridName} ({$plan->gridNick}), imported from $path",
            "Core: $core",
            "Database: {$plan->dbName} @ {$plan->dbHost} (user {$plan->dbUser}), kept as it is",
            "Ports: public {$plan->publicPort}, private {$plan->privatePort}" .
            ($plan->consoleMode === 'rest' ? ", console {$plan->consolePort}" : ''),
            'Hypergrid: ' . ($plan->enableHypergrid ? 'yes' : 'no'),
        ],
        $customizations,
    );
    echo $report;
    if (!$apply) {
        echo "\nNothing was written: --apply writes the grid {$plan->gridNick} in {$plan->gridDir}.\n";
        exit(0);
    }

    try {
        if (!Elevated::needed($profile)) {
            (new NewGrid(new QuietUi()))->apply($plan, $profile);
        } else {
            Elevated::run(
                new QuietUi(),
                '--apply-grid',
                ['plan' => $plan->toArray(), 'profile' => $profile],
                $profile['SystemUser'],
            );
        }
    } catch (SetupFailed) {
        fail('the grid could not be written');
    }
    $done = act($profile, 'inject-config', [
        'path' => $plan->robustIni(),
        'customizations' => $customizations,
        'report_path' => "{$plan->gridDir}/import-report.txt",
        'report' => $report,
    ]);
    if (!$done) {
        fail("the grid is written, but its settings could not be added to {$plan->robustIni()}");
    }
    echo "\nWritten: {$plan->robustIni()}\nReport:  {$plan->gridDir}/import-report.txt\nCompare it with the original, then: opensim enable {$plan->gridNick}\n";
    exit(0);
}

// A simulator of a grid the kit knows
$grid = $options['grid'] === '' ? null : GridInfo::load($profile, $options['grid']);
if ($grid === null) {
    fail(
        'the grid of the simulator is one the kit knows (opensim setup, or opensim import for its Robust config): opensim import <grid> FILE',
        2,
    );
}
$core =
    $options['core'] !== ''
        ? rtrim($options['core'], '/')
        : ($grid->coreDirectory !== ''
            ? $grid->coreDirectory
            : (string) ($profile['CoreDirectory'] ?? ''));
if ($core === '' || !is_dir("$core/bin")) {
    fail('choose the core the simulator will run with: --core DIR (the folder that has bin/)', 2);
}
$plan = SimImporter::plan($raw, $path, $grid, $profile, $options['name'] ?: null, $core);
if (is_string($plan)) {
    fail($plan, 2);
}
if (is_file($plan->iniPath())) {
    fail(
        "the simulator {$plan->simName} is already configured ({$plan->iniPath()}): choose another name with --name",
        2,
    );
}
$regions = SimImporter::regionFiles($raw, $path);
$plan->externalHost = SimImporter::externalHost($regions);

// What the core has by default: its defaults file, then the example of OpenSim.ini
$defaults = IniReader::load("{$plan->binDir()}/OpenSimDefaults.ini");
$example = IniReader::load("{$plan->binDir()}/OpenSim.ini.example");
$exampleText =
    (string) @file_get_contents("{$plan->binDir()}/OpenSimDefaults.ini") .
    "\n" .
    (string) @file_get_contents("{$plan->binDir()}/OpenSim.ini.example");
$default = array_replace_recursive($defaults, $example);
$customizations = RobustImporter::customizations(
    $raw,
    IniReader::parse((new SimConfig())->render($plan)),
    $default,
    dirname($path),
    $exampleText,
    IniReader::expand($raw),
);
$report = report(
    [
        "Simulator {$plan->simName} ({$plan->slug}) of the grid {$grid->nick}, imported from $path",
        "Core: $core",
        "Database: {$plan->dbName} @ {$plan->dbHost} (user {$plan->dbUser}), kept as it is",
        "HTTP port: {$plan->httpPort}" . ($plan->consoleMode === 'rest' ? ", console {$plan->consolePort}" : ''),
        'Regions: ' .
        count($regions) .
        ' file(s) copied with their UUID' .
        ($regions === [] ? ' (none found: regionload_regionsdir of [Startup], else Regions/ next to bin/)' : ''),
    ],
    $customizations,
);
echo $report;
if (!$apply) {
    echo "\nNothing was written: --apply writes the simulator {$plan->slug} in {$plan->gridDir}/sims/.\n";
    exit(0);
}

try {
    if (!Elevated::needed($profile)) {
        (new NewSim(new QuietUi()))->apply($plan, $profile);
    } else {
        Elevated::run(
            new QuietUi(),
            '--apply-sim',
            ['plan' => $plan->toArray(), 'profile' => $profile],
            $profile['SystemUser'],
        );
    }
} catch (SetupFailed) {
    fail('the simulator could not be written');
}
$copied = $regions === [] || act($profile, 'copy-regions', ['files' => $regions, 'to' => $plan->regionsDir()]);
$done = act($profile, 'inject-config', [
    'path' => $plan->iniPath(),
    'customizations' => $customizations,
    'report_path' => "{$plan->gridDir}/sims/{$plan->slug}.import-report.txt",
    'report' => $report,
]);
if (!$copied || !$done) {
    fail('the simulator is written, but its regions or its settings could not be added');
}
echo "\nWritten: {$plan->iniPath()}\nRegions: {$plan->regionsDir()}\nReport:  {$plan->gridDir}/sims/{$plan->slug}.import-report.txt\nCompare it with the original, then: opensim enable {$plan->slug}\n";

