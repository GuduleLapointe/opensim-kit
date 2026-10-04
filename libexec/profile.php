#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * opensim profile: the installs the tools know about.
 *
 *   opensim profile list
 *   opensim profile add NAME --core DIR [--etc DIR] [--data DIR] [--default]
 *   opensim profile default NAME
 *   opensim profile remove NAME
 *
 * A profile gives the base directories of an install (core, config, data); without --etc and --data they are the
 * core directory, as in an install by the book (/path/to/opensim/bin). The default profile is only the default of
 * the new grids: a grid that exists follows its own install.
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSim\Installer\Config;
use OpenSim\Installer\I18n;

I18n::init();

const USAGE = "usage: opensim profile list | add NAME --core DIR [--etc DIR] [--data DIR] [--default] | default NAME | remove NAME\n";

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "profile: $message\n");
    exit($code);
}

$args = array_slice($argv, 1);
$action = array_shift($args) ?? '';
$config = new Config(getenv('OPENSIM_CONF') ?: null);

switch ($action) {
    case 'list':
        $default = $config->defaultProfile();
        foreach ($config->profiles() as $name) {
            $p = $config->profile($name);
            printf("%s%s\t%s\n", $name, $name === $default ? ' (default)' : '', $p['CoreDirectory'] ?? '');
        }
        break;

    case 'add':
        $name = array_shift($args) ?? '';
        $opts = ['core' => null, 'etc' => null, 'data' => null];
        $default = false;
        while ($args) {
            $a = array_shift($args);
            if ($a === '--default') {
                $default = true;
            } elseif (preg_match('/^--(core|etc|data)(?:=(.*))?$/', $a, $m)) {
                $opts[$m[1]] = $m[2] ?? array_shift($args);
            } else {
                fail(sprintf(_('Unknown option: %s'), $a), 2);
            }
        }
        if ($name === '' || !$opts['core']) {
            fwrite(STDERR, USAGE);
            exit(2);
        }
        foreach ($opts as $dir) {
            if ($dir !== null && !is_dir($dir)) {
                fail(sprintf(_('No such directory: %s'), $dir));
            }
        }
        try {
            $config->addProfile($name, $opts['core'], $opts['etc'], $opts['data'], $default);
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        printf(_("Profile %s added\n"), $name);
        break;

    case 'default':
        $name = array_shift($args) ?? '';
        if (!in_array($name, $config->profiles(), true)) {
            fail(sprintf(_('Unknown profile: %s'), $name));
        }
        $config->setDefaultProfile($name);
        break;

    case 'remove':
        $name = array_shift($args) ?? '';
        try {
            if (!$config->removeProfile($name)) {
                fail(sprintf(_('Unknown profile: %s'), $name));
            }
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        printf(_("Profile %s removed\n"), $name);
        break;

    default:
        fwrite(STDERR, USAGE);
        exit(in_array($action, ['-h', '--help'], true) ? 0 : 2);
}

