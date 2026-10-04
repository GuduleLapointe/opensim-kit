<?php

/**
 * `opensim next port` and `opensim next location`: the next free port and place
 * by the rules of the setup, from libexec/next.php.
 */

/**
 * Run libexec/next.php as a user whose setup is a temporary tree.
 *
 * @param list<string> $arguments What follows `opensim next`.
 * @param string $home The home of that user.
 * @return array{0:int,1:string,2:string} Exit status, output, errors.
 */
function next_run(array $arguments, string $home): array
{
    $root = dirname(__DIR__, 2);
    $process = proc_open(
        [PHP_BINARY, "$root/libexec/next.php", ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        ['HOME' => $home, 'PATH' => getenv('PATH')],
    );
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);

    return [proc_close($process), trim((string) $output), trim((string) $errors)];
}

/**
 * A setup with a grid of two regions side by side, that leaves $spacing free blocks between its regions.
 *
 * @return string The home of the user of that setup.
 */
function next_home(int $spacing = 0): string
{
    $root = test_tmp() . '/next-' . bin2hex(random_bytes(4));
    $etc = "$root/etc";
    mkdir("$root/home", 0o755, true);
    mkdir("$etc/grids/alpha/sims/alpha_sim1/regions", 0o755, true);
    mkdir("$etc/robust.d", 0o755, true);
    file_put_contents(
        "$root/home/.opensim.conf",
        "[Defaults]\nDefaultProfile = 0.9.3.0\n\n[0.9.3.0]\nEtcRoot = $etc\n",
    );
    file_put_contents("$etc/grids/alpha/Robust.HG.ini", "[Network]\n");
    symlink("$etc/grids/alpha/Robust.HG.ini", "$etc/robust.d/alpha.ini");
    file_put_contents("$etc/grids/alpha/alpha.conf", "[Grid]\nGridNick = alpha\nRegionSpacing = $spacing\n");
    file_put_contents("$etc/grids/alpha/sims/alpha_sim1/regions/Sim1.ini", "[Sim1]\nLocation = 1000,1000\n");
    file_put_contents("$etc/grids/alpha/sims/alpha_sim1/regions/Sim1North.ini", "[Sim1North]\nLocation = 1000,1001\n");

    return "$root/home";
}

describe('opensim next port', function () {
    test('gives free ports from the one asked', function () {
        [$status, $output] = next_run(['port', '9500', '3'], next_home());

        expect($status)->toBe(0);
        expect(array_map('intval', explode("\n", $output)))->toBe([9500, 9501, 9502]);
    });

    test('skips the ports given with -e', function () {
        [, $output] = next_run(['port', '-e', '9500', '-e', '9501', '9500', '2'], next_home());

        expect(array_map('intval', explode("\n", $output)))->toBe([9502, 9503]);
    });

    test('refuses what is not a number', function () {
        [$status, , $errors] = next_run(['port', 'abc'], next_home());

        expect($status)->toBe(2);
        expect($errors)->toContain('usage: opensim next port');
    });
});

describe('opensim next port, what takes a port', function () {
    test('is an instance, not the defaults OpenSim ships', function () {
        $home = next_home();
        $grid = dirname($home) . '/etc/grids/alpha';
        mkdir("$grid/config-include");
        file_put_contents(
            "$grid/OpenSimDefaults.ini",
            "[Network]\n    http_listener_port = 19000\n    http_listener_sslport = 19001\n",
        );
        file_put_contents("$grid/config-include/GridCommon.ini", "[Network]\n    PublicPort = 19002\n");

        [, $output] = next_run(['port', '19000', '3'], $home);

        expect(array_map('intval', explode("\n", $output)))->toBe([19000, 19001, 19002]);
    });

    test('is a simulator of the grid', function () {
        $home = next_home();
        file_put_contents(
            dirname($home) . '/etc/grids/alpha/sims/alpha_sim1.ini',
            "[Network]\n    http_listener_port = 19000\n",
        );
        file_put_contents(
            dirname($home) . '/etc/grids/alpha/sims/alpha_sim1/regions/Sim1.ini',
            "[Sim1]\nInternalPort = 19002\n",
        );

        [, $output] = next_run(['port', '19000', '3'], $home);

        expect(array_map('intval', explode("\n", $output)))->toBe([19001, 19003, 19004]);
    });
});

describe('opensim next location', function () {
    test('gives the nearest free place', function () {
        [$status, $output] = next_run(['location', 'alpha', '1000,1000'], next_home());

        expect($status)->toBe(0);
        expect($output)->toBe('1001,1000');
    });

    test('gives several places, each one taken for the next', function () {
        [, $output] = next_run(['location', 'alpha', '1000,1000', '3'], next_home());

        expect(explode("\n", $output))->toBe(['1001,1000', '999,1000', '1000,999']);
    });

    test('keeps the free blocks the grid leaves between its regions', function () {
        [, $output] = next_run(['location', 'alpha', '1000,1000'], next_home(1));

        expect($output)->toBe('1002,1000');
    });

    test('takes the places given with -e as taken', function () {
        [, $output] = next_run(['location', '-e', '1001,1000', 'alpha', '1000,1000'], next_home());

        expect($output)->toBe('999,1000');
    });

    test('says when the grid does not exist', function () {
        [$status, , $errors] = next_run(['location', 'nosuchgrid'], next_home());

        expect($status)->toBe(1);
        expect($errors)->toContain("no grid named 'nosuchgrid'");
    });
});

describe('opensim next location default', function () {
    test('starts from the public port of the grid, on both axes', function () {
        expect(OpenSim\Installer\Grid\LocationFinder::first(8012))->toBe([8012, 8012]);
    });
});

describe('the center of a grid', function () {
    test('is the port twice, until the grid has its own', function () {
        $grid = new OpenSim\Installer\Grid\GridInfo();
        $grid->publicPort = 8012;
        expect($grid->centerPlace())->toBe([8012, 8012]);

        $grid->center = '1000,1020';
        expect($grid->centerPlace())->toBe([1000, 1020]);
    });
});
