<?php
/**
 * The packages and the container image, tested in containers (podman) by the
 * scripts of this directory, so that one command runs every test:
 * PACKAGING=1 vendor/bin/pest
 *
 * Slow, and they need podman and the packages of dist/ (apt-package), so they
 * run only when asked, on a host that has them.
 */

$root = dirname(__DIR__, 2);
$podman = trim((string) shell_exec('command -v podman'));
$packages = glob("$root/dist/*.deb") ?: [];

if (!getenv('PACKAGING')) {
    $skip = 'slow, PACKAGING=1 to run';
} elseif (!$podman) {
    $skip = 'no podman';
} else {
    $skip = '';
}

/**
 * Runs a script of this directory and gives its exit status and the end of its output.
 *
 * @param array $command Script and arguments.
 * @param array $env What to add to the environment of the script.
 * @return array Exit status, output.
 */
function packaging_run(array $command, array $env = [])
{
    $root = dirname(__DIR__, 2);
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $root,
        $env === [] ? null : $env + getenv(),
    );
    $lines = [];
    while (false !== ($line = fgets($pipes[1]))) {
        $lines[] = rtrim($line);
    }

    return [proc_close($process), implode("\n", array_slice($lines, -60))];
}

describe('Packaging', function () use ($root, $skip, $packages) {
    $images = [
        'Debian 12' => 'docker.io/library/debian:bookworm',
        'Ubuntu 24.04' => 'docker.io/library/ubuntu:24.04',
    ];

    foreach ($images as $name => $image) {
        test("packages install and run on $name", function () use ($root, $image) {
            [$status, $output] = packaging_run(["$root/tests/Packaging/run", $image]);

            expect($status, $output)->toBe(0);
        })->skip('' !== $skip || !$packages, $skip ?: 'no package in dist/, build them with apt-package');

        test("pest suites pass with the php of $name", function () use ($root, $image) {
            [$status, $output] = packaging_run(["$root/tests/Packaging/distro-pest", $image]);

            expect($status, $output)->toBe(0);
        })->skip('' !== $skip, $skip);
    }

    test('web servers serve the site of a grid on Debian 12', function () use ($root) {
        [$status, $output] = packaging_run(
            ["$root/tests/Packaging/run", 'docker.io/library/debian:bookworm'],
            [
                'SCENARIO' => 'web-scenario.sh',
            ],
        );

        expect($status, $output)->toBe(0);
    })->skip('' !== $skip || !$packages, $skip ?: 'no package in dist/, build them with apt-package');

    test('container image', function () use ($root) {
        [$status, $output] = packaging_run(["$root/tests/Packaging/container-image"]);

        expect($status, $output)->toBe(0);
    })->skip('' !== $skip || !$packages, $skip ?: 'no package in dist/, build them with apt-package');
});
