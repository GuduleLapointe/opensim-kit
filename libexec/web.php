#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * opensim web: the web side of a grid, the helpers (economy, search, offline messages) its viewers use.
 *
 *   opensim web [GRID] [show]            where the services are, and what the grid tells its viewers
 *   opensim web [GRID] snippet <caddy|nginx|apache> [--docroot DIR] [--socket PATH]
 *                                        what the web server needs to serve them, to include in its config
 *   opensim web [GRID] check             asks each service on the web, tells what answers
 *
 * The grid is the first word, as the instance is in the other commands; it can be left out when the machine
 * has only one (--grid GRID works too).
 *
 * The path of the helpers and of each service is the operator's: `path` and [Urls] in the helpers.ini of the
 * grid (see Web\HelpersConfig).
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSim\Installer\Config;
use OpenSim\Installer\Grid\GridState;
use OpenSim\Installer\Web\HelpersConfig;
use OpenSim\Installer\Web\Services;
use OpenSim\Installer\Web\Snippets;

const USAGE = "usage: opensim web [GRID] [show]\n       opensim web [GRID] snippet <caddy|nginx|apache> [--docroot DIR] [--socket PATH]\n       opensim web [GRID] check\n";

/** Say what is wrong and stop. */
function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "web: $message\n");
    exit($code);
}

$args = array_slice($argv, 1);
if ($args !== [] && ($args[0] === '-h' || $args[0] === '--help')) {
    echo USAGE;
    exit(0);
}

$options = ['grid' => '', 'docroot' => Snippets::DOCROOT, 'socket' => Snippets::SOCKET];
$words = [];
for ($i = 0; $i < count($args); $i++) {
    if (preg_match('/^--(grid|docroot|socket)(?:=(.*))?$/', $args[$i], $m)) {
        $options[$m[1]] = $m[2] ?? ($args[++$i] ?? fail("--{$m[1]} needs a value", 2));
    } else {
        $words[] = $args[$i];
    }
}

// opensim web [GRID] [show|check|snippet SERVER]: the grid comes first, as the instance does in the other commands
$actions = ['show', 'snippet', 'check'];
$command = 'show';
if ($words !== [] && !in_array($words[0], $actions, true)) {
    $options['grid'] = $options['grid'] ?: array_shift($words);
}
if ($words !== []) {
    $command = array_shift($words);
    if (!in_array($command, $actions, true)) {
        fail("unknown action $command\n" . USAGE, 2);
    }
}

$etc = (new Config())->profile()['EtcRoot'] ?? '';
$grids = [];
foreach (glob("$etc/grids/*", GLOB_ONLYDIR) ?: [] as $dir) {
    if (GridState::robustIni($etc, basename($dir)) !== null) {
        $grids[] = basename($dir);
    }
}
if ($options['grid'] === '' && count($grids) === 1) {
    $options['grid'] = $grids[0];
}
if ($options['grid'] === '' || !in_array($options['grid'], $grids, true)) {
    fail(
        $grids === []
            ? 'no grid of this machine: make one with `opensim setup`'
            : ($options['grid'] === ''
                ? 'several grids, say which: opensim web <grid> (' . implode(', ', $grids) . ')'
                : "no grid {$options['grid']} (" . implode(', ', $grids) . ')'),
        2,
    );
}

$nick = $options['grid'];
$gridDir = "$etc/grids/$nick";
$services = HelpersConfig::services($gridDir);
$ini = HelpersConfig::read($gridDir);
$robust = (string) file_get_contents((string) GridState::robustIni($etc, $nick));
$webUrl =
    $ini['Helpers']['web_url'] ?? (preg_match('/^\s*WebURL\s*=\s*"?([^"\r\n]+)"?/m', $robust, $m) ? trim($m[1]) : '');

if ($command === 'snippet') {
    $server = $words[0] ?? '';
    try {
        echo Snippets::render($server, $nick, $options['docroot'], $options['socket'], $webUrl);
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage() . "\n" . USAGE, 2);
    }
    exit(0);
}

$rows = [];
foreach (array_keys(Services::SCRIPTS) as $service) {
    $rows[$service] = [$services->url($webUrl, $service), Services::SCRIPTS[$service]];
}
foreach (array_keys(Services::PAGES) as $page) {
    $rows[$page] = [$services->url($webUrl, $page), "@$page"];
}

if ($command === 'check') {
    $failed = false;
    foreach ($rows as $service => [$url]) {
        $headers = @get_headers(
            $url,
            false,
            stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]),
        );
        $status = $headers === false ? 'no answer' : (string) ($headers[0] ?? '?');
        // A script that answers, even with an error of its own (a query without arguments), is served
        $ok = $headers !== false && !preg_match('# (404|403|50[234]) #', $status);
        $failed = $failed || !$ok;
        printf("%-15s %-50s %s\n", $service, $url, $ok ? "ok ($status)" : "FAILED ($status)");
    }
    exit($failed ? 1 : 0);
}

echo "Grid:      $nick\n";
echo 'Web URL:   ' . ($webUrl !== '' ? $webUrl : '(none: WebURL of the Robust config)') . "\n";
echo "Helpers:   {$services->base()}" .
    (is_file(HelpersConfig::path($gridDir)) ? '' : '  (no helpers.ini: run `opensim setup` for this grid)') .
    "\n";
echo 'Docroot:   ' .
    $options['docroot'] .
    (is_dir(Snippets::WEBROOT) ? '' : '  (the helpers are not installed: apt install opensim-helpers)') .
    "\n\n";
foreach ($rows as $service => [$url, $script]) {
    printf("  %-15s %s\n", $service, $url);
}
echo "\nThe web server needs: opensim web $nick snippet <caddy|nginx|apache>\n";

