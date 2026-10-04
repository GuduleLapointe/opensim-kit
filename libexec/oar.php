#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * opensim oar: OpenSimulator archives (see OpenSim_Oar of the engine).
 *
 *   opensim oar pack DIR [FILE]     make an OAR from a folder laid out as its content (FILE: DIR without -src, .oar)
 *   opensim oar info FILE           what the archive holds
 *   opensim oar check FILE          what is wrong in it, exit code 1 when something is
 *   opensim oar unpack FILE [DIR]   extract it (DIR: FILE without .oar, -src)
 */

// The engine classes refuse to run outside a project that uses them
defined('OPENSIM_ENGINE') || define('OPENSIM_ENGINE', true);

require __DIR__ . '/../vendor/autoload.php';

const USAGE = "usage: opensim oar pack DIR [FILE] | info FILE | check FILE | unpack FILE [DIR]\n";

[$action, $source, $target] = array_pad(array_slice($argv, 1), 3, null);
if ($action === null || $source === null) {
    fwrite(STDERR, USAGE);
    exit(in_array($action, ['-h', '--help'], true) ? 0 : 2);
}

try {
    switch ($action) {
        case 'pack':
            $dir = rtrim($source, '/');
            $target ??= preg_replace('/-src$/', '', $dir) . '.oar';
            OpenSim_Oar::pack($dir, $target);
            echo "$target\n";
            break;

        case 'info':
            foreach (OpenSim_Oar::info($source) as $key => $value) {
                printf("%-16s %s\n", $key, is_array($value) ? implode('x', $value) : var_export($value, true));
            }
            break;

        case 'check':
            $problems = OpenSim_Oar::check($source);
            echo $problems === [] ? "ok\n" : implode("\n", $problems) . "\n";
            exit($problems === [] ? 0 : 1);

        case 'unpack':
            $target ??= preg_replace('/\.oar$/', '', $source) . '-src';
            OpenSim_Oar::unpack($source, $target);
            echo "$target\n";
            break;

        default:
            fwrite(STDERR, USAGE);
            exit(2);
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, 'oar: ' . $e->getMessage() . "\n");
    exit(1);
}

