#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * opensim backup: what it takes to install the grids again as they are (see Backup), as the user of the instances.
 *
 *   opensim backup [options]                  every grid of this machine and its simulators
 *   opensim backup GRID [options]             a grid and its simulators
 *   opensim backup GRID SIM|_SIM [options]    one simulator
 *   --logs        the logs too
 *   --archives    the archives of the users (iar, oar) too, they are backups already
 *   --output DIR  where the files go (default: <data>/backups/admin)
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSim\Installer\Archive\Backup;
use OpenSim\Installer\Config;

$usage = "usage: opensim backup [GRID [SIM]] [--logs] [--archives] [--output DIR]\n";
$refs = [];
$options = [];
$args = array_slice($argv, 1);
while ($args !== []) {
    $arg = array_shift($args);
    if ($arg === '-h' || $arg === '--help') {
        echo $usage;
        exit(0);
    } elseif ($arg === '--logs' || $arg === '--archives') {
        $options[substr($arg, 2)] = true;
    } elseif ($arg === '--output' || str_starts_with($arg, '--output=')) {
        $options['output'] = str_contains($arg, '=') ? substr($arg, 9) : array_shift($args) ?? '';
        if ($options['output'] === '') {
            fwrite(STDERR, "backup: --output needs a folder\n");
            exit(2);
        }
        if (!str_starts_with($options['output'], '/')) {
            $options['output'] = getcwd() . '/' . $options['output'];
        }
    } elseif (str_starts_with($arg, '-') && $arg !== '_') {
        fwrite(STDERR, "backup: unknown option $arg\n$usage");
        exit(2);
    } else {
        $refs[] = $arg;
    }
}

$profile = (new Config())->profile();
if (($profile['EtcRoot'] ?? '') === '') {
    fwrite(STDERR, "backup: no install found\n");
    exit(1);
}
try {
    $files = (new Backup($profile, Backup::mysqldump(...), static function (string $message): void {
        fwrite(STDERR, $message . "\n");
    }))->run($refs, $options);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'backup: ' . $e->getMessage() . "\n");
    exit(2);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'backup: ' . $e->getMessage() . "\n");
    exit(1);
}
echo implode("\n", $files), "\n";

