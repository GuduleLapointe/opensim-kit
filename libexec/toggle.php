#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * opensim enable|disable: enable or disable a grid or a simulator by the name of its instance, the
 * same action as the menus of the setup (see Actions). An enabled instance is started with the others
 * by `opensim start` and at boot; disabling it does not stop it.
 *
 *   opensim enable <instance>...
 *   opensim disable <instance>...
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSim\Installer\Actions;
use OpenSim\Installer\Config;
use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Grid\GridState;
use OpenSim\Installer\Grid\SimState;
use OpenSim\Installer\Ui\QuietUi;

$verb = $argv[1] ?? '';
$names = array_slice($argv, 2);
if (!in_array($verb, ['enable', 'disable'], true) || $names === []) {
    fwrite(STDERR, "usage: opensim enable <instance>...\n       opensim disable <instance>...\n");
    exit(2);
}

$profile = (new Config())->profile();
$etc = $profile['EtcRoot'] ?? '';

// The grids and the simulators the setup made, by the name of their instance
$instances = [];
foreach (glob("$etc/grids/*", GLOB_ONLYDIR) ?: [] as $dir) {
    $nick = basename($dir);
    if (GridState::robustIni($etc, $nick) !== null) {
        $instances[GridInfo::instanceName($nick)] = [
            'kind' => 'grid',
            'args' => ['nick' => $nick],
            'enabled' => GridState::isEnabled($etc, $nick),
        ];
    }
    foreach (SimState::names($dir) as $slug) {
        $instances[$slug] = [
            'kind' => 'simulator',
            'args' => ['slug' => $slug, 'ini' => "$dir/sims/$slug.ini"],
            'enabled' => SimState::isEnabled($etc, $slug),
        ];
    }
}

$actions = new Actions(new QuietUi());
$failed = false;
foreach ($names as $name) {
    $instance = $instances[GridInfo::instanceName($name)] ?? null;
    if ($instance === null) {
        fwrite(
            STDERR,
            "$verb: no grid or simulator called $name" .
                ($instances === [] ? '' : ' (known: ' . implode(', ', array_keys($instances)) . ')') .
                "\n",
        );
        $failed = true;
        continue;
    }

    $enable = $verb === 'enable';
    if ($instance['enabled'] === $enable) {
        echo "{$instance['kind']} $name is already {$verb}d\n";
        continue;
    }
    if ($actions->perform($instance['kind'] === 'grid' ? "grid-$verb" : "sim-$verb", $instance['args'], $profile)) {
        echo "{$instance['kind']} $name {$verb}d" . ($enable ? ": opensim start $name" : '') . "\n";
    } else {
        $failed = true;
    }
}

exit($failed ? 1 : 0);

