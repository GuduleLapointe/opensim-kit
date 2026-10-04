<?php

/**
 * `opensim enable` and `opensim disable`: a grid or a simulator by the name of its instance, from
 * libexec/toggle.php.
 */

/**
 * Run libexec/toggle.php as a user whose setup is a temporary tree.
 *
 * @param list<string> $arguments What follows `opensim`.
 * @param string $home The home of that user.
 * @return array{0:int,1:string,2:string} Exit status, output, errors.
 */
function toggle_run(array $arguments, string $home): array
{
    $root = dirname(__DIR__, 2);
    $process = proc_open(
        [PHP_BINARY, "$root/libexec/toggle.php", ...$arguments],
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
 * A setup with an enabled grid and its simulator, enabled too, and a disabled simulator.
 *
 * @return array{0:string,1:string} The home of the user, the etc folder.
 */
function toggle_tree(): array
{
    $root = sys_get_temp_dir() . '/toggle-' . bin2hex(random_bytes(4));
    $etc = "$root/etc";
    mkdir("$root/home", 0o755, true);
    mkdir("$etc/grids/Alpha/sims", 0o755, true);
    mkdir("$etc/robust.d", 0o755, true);
    mkdir("$etc/opensim.d", 0o755, true);
    file_put_contents(
        "$root/home/.opensim.conf",
        "[Defaults]\nDefaultProfile = 0.9.3.0\n\n[0.9.3.0]\nEtcRoot = $etc\n",
    );
    file_put_contents("$etc/grids/Alpha/Robust.HG.ini", "[Network]\n");
    symlink("$etc/grids/Alpha/Robust.HG.ini", "$etc/robust.d/Alpha.ini");
    file_put_contents("$etc/grids/Alpha/sims/alpha_sim1.ini", "[Startup]\n");
    file_put_contents("$etc/grids/Alpha/sims/alpha_sim2.ini", "[Startup]\n");
    symlink("$etc/grids/Alpha/sims/alpha_sim1.ini", "$etc/opensim.d/alpha_sim1.ini");

    return ["$root/home", $etc];
}

describe('opensim disable and enable', function () {
    test('take a simulator out of opensim.d and put it back', function () {
        [$home, $etc] = toggle_tree();

        [$status, $output] = toggle_run(['disable', 'alpha_sim1'], $home);
        expect($status)->toBe(0);
        expect($output)->toBe('simulator alpha_sim1 disabled');
        expect(is_link("$etc/opensim.d/alpha_sim1.ini"))->toBeFalse();

        [$status, $output] = toggle_run(['enable', 'alpha_sim1'], $home);
        expect($status)->toBe(0);
        expect($output)->toBe('simulator alpha_sim1 enabled: opensim start alpha_sim1');
        expect(readlink("$etc/opensim.d/alpha_sim1.ini"))->toBe("$etc/grids/Alpha/sims/alpha_sim1.ini");
    });

    test('enable a simulator or a grid', function () {
        [$home, $etc] = toggle_tree();

        [$status] = toggle_run(['enable', 'Alpha_Sim2'], $home);
        expect($status)->toBe(0);
        expect(is_link("$etc/opensim.d/alpha_sim2.ini"))->toBeTrue();

        toggle_run(['disable', 'ALPHA'], $home);
        expect(is_link("$etc/robust.d/Alpha.ini"))->toBeFalse();
    });

    test('say when there is nothing to do', function () {
        [$home] = toggle_tree();

        [$status, $output] = toggle_run(['enable', 'alpha'], $home);

        expect($status)->toBe(0);
        expect($output)->toBe('grid alpha is already enabled');
    });

    test('refuse an unknown name, go on', function () {
        [$home, $etc] = toggle_tree();

        [$status, , $errors] = toggle_run(['disable', 'nowhere', 'alpha_sim1'], $home);

        expect($status)->toBe(1);
        expect($errors)->toContain('no grid or simulator called nowhere');
        expect($errors)->toContain('alpha_sim2');
        expect(is_link("$etc/opensim.d/alpha_sim1.ini"))->toBeFalse();
    });

    test('need an instance', function () {
        [$home] = toggle_tree();

        [$status, , $errors] = toggle_run(['enable'], $home);

        expect($status)->toBe(2);
        expect($errors)->toContain('usage: opensim enable');
    });
});
