#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * opensim save|load iar|oar: the inventory and region archives, through the console of a running simulator (see
 * ArchiveRunner). Run as the user of the instances: it writes where they write.
 *
 *   opensim save iar [GRID [SIM]] [options] FIRST LAST PATH [PASSWORD] [FILE]
 *   opensim load iar [GRID [SIM]] [-m|--merge] FIRST LAST PATH [PASSWORD] [FILE]
 *   opensim save oar [GRID [SIM|REGION]] [options] [FILE]
 *   opensim load oar [GRID [SIM|REGION]] [options] [FILE]
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSim\Installer\Archive\ArchiveRunner;
use OpenSim\Installer\Config;

$args = array_slice($argv, 1);
$verb = array_shift($args);
$asked = in_array($verb, ['-h', '--help'], true) || in_array($args[0] ?? '', ['-h', '--help'], true);
if (!in_array($verb, ['save', 'load'], true) || $args === [] || $asked) {
    echo <<<'USAGE'
usage: opensim save iar [GRID [SIM]] [-h|--home=URL] [-v] [--noassets] [--perm=PERMISSIONS] [--skipbadassets]
                        FIRST LAST PATH [PASSWORD] [FILE]
       opensim load iar [GRID [SIM]] [-m|--merge] FIRST LAST PATH [PASSWORD] [FILE]
       opensim save oar [GRID [SIM|REGION]] [--region NAME] [--all] [--noassets] [-h|--home=URL] [--publish]
                        [--perm=PERMISSIONS] [FILE]
       opensim load oar [GRID [SIM|REGION]] [--region NAME] [--merge] [--skip-assets] [FILE]

The simulator is the one named, else a running one of the grid (an inventory), else the only one (a region).
FILE: a name, kept in <data of the grid>/backups/iar or oar, or a path. Saving makes
gridnick-first-last[-noassets][-perm<P>][-skipbadassets]-stamp.iar
gridnick-sim[-region][-noassets][-perm<P>][-publish]-stamp.oar
when none is given, loading takes the newest such file. The password is asked when it is not given, or
read from OPENSIM_IAR_PASSWORD.

USAGE;
    exit($asked ? 0 : 2);
}

$profile = (new Config())->profile();
if (($profile['EtcRoot'] ?? '') === '') {
    fwrite(STDERR, "$verb: no install found\n");
    exit(1);
}
[$code, $file] = ArchiveRunner::system($profile)->run($verb, $args);
if ($file !== null) {
    echo "$file\n";
}
exit($code);
