<?php

declare(strict_types=1);

use OpenSim\Installer\Archive\ArchiveRunner;
use OpenSim\Installer\Archive\Archives;
use OpenSim\Installer\Archive\Backup;
use OpenSim\Installer\Archive\Instances;

/**
 * An install with one grid, alpha, whose simulators sim1 (a region, Welcome) and sim2 (two regions) are enabled.
 *
 * @return array<string,string> the profile, with the root of the files
 */
function archive_install(): array
{
    $root = test_tmp() . '/archive-' . bin2hex(random_bytes(4));
    $etc = "$root/etc";
    $grid = "$etc/grids/alpha";
    mkdir("$grid/sims/alpha_sim1/regions", 0o755, true);
    mkdir("$grid/sims/alpha_sim2/regions", 0o755, true);
    mkdir("$etc/robust.d", 0o755, true);
    mkdir("$etc/opensim.d", 0o755, true);
    mkdir("$root/data/alpha/alpha_sim1", 0o755, true);
    mkdir("$root/data/alpha/backups/oar", 0o755, true);
    mkdir("$root/logs", 0o755, true);
    file_put_contents("$grid/Robust.HG.ini", "[DatabaseService]\nConnectionString = \"Data Source=localhost;Database=alpha_robust;User ID=opensim;Password=secret;Old Guids=true;\"\n");
    file_put_contents("$grid/alpha.conf", "[Grid]\nGridNick = alpha\n");
    foreach (['alpha_sim1', 'alpha_sim2'] as $sim) {
        file_put_contents("$grid/sims/$sim.ini", "[DatabaseService]\nConnectionString = \"Data Source=localhost;Database=$sim;User ID=opensim;Password=secret;Old Guids=true;\"\n");
        symlink("$grid/sims/$sim.ini", "$etc/opensim.d/$sim.ini");
    }
    symlink("$grid/Robust.HG.ini", "$etc/robust.d/alpha.ini");
    file_put_contents("$grid/sims/alpha_sim1/regions/Welcome.ini", "[Welcome]\nLocation = 1000,1000\n");
    file_put_contents("$grid/sims/alpha_sim2/regions/North.ini", "[North]\nLocation = 1000,1001\n");
    file_put_contents("$grid/sims/alpha_sim2/regions/South.ini", "[South]\nLocation = 1000,999\n");
    file_put_contents("$etc/opensim.conf", "[Defaults]\nDefaultProfile = x\n");
    file_put_contents("$root/data/alpha/alpha_sim1/state", 'persistent');
    file_put_contents("$root/data/alpha/backups/oar/old.oar", 'archive of a user');

    return ['root' => $root, 'EtcRoot' => $etc, 'DataRoot' => "$root/data", 'LogsRoot' => "$root/logs"];
}

describe('the names of the archives', function () {
    test('tell what is in them, the modes in suffixes', function () {
        $stamp = '20261003-101500';

        expect(Archives::iarName('alpha', 'Jane', 'Doe', [], $stamp))->toBe('alpha-Jane-Doe-20261003-101500.iar')
            ->and(Archives::iarName('alpha', 'Jane', 'Doe', ['noassets' => true, 'perm' => 'CMT'], $stamp))
            ->toBe('alpha-Jane-Doe-noassets-permCMT-20261003-101500.iar')
            ->and(Archives::oarName('alpha', 'sim1', 'Welcome', [], $stamp))->toBe('alpha-sim1-Welcome-20261003-101500.oar')
            ->and(Archives::oarName('alpha', 'sim1', 'Welcome', ['all' => true, 'noassets' => true], $stamp))
            ->toBe('alpha-sim1-noassets-20261003-101500.oar')
            ->and(Archives::oarName('alpha', 'sim1', 'Welcome', ['perm' => 'CT', 'publish' => true], $stamp))
            ->toBe('alpha-sim1-Welcome-permCT-publish-20261003-101500.oar');
    });

    test('have a stamp of year, month, day, hour, minute, second', function () {
        expect(Archives::stamp(mktime(9, 5, 3, 2, 7, 2026)))->toBe('20260207-090503');
    });

    test('keep a hyphen of a name out of the parts', function () {
        expect(Archives::part('Old-Grid name'))->toBe('Old_Grid_name');
    });

    test('finds the newest archive', function () {
        $dir = test_tmp() . '/archive-new-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach (['alpha-sim1-20261001-100000.oar', 'alpha-sim1-noassets-20261003-100000.oar', 'alpha-sim1-Welcome-20261009-100000.oar', 'alpha-sim10-20261009-100000.oar'] as $name) {
            touch("$dir/$name");
        }

        expect(basename((string) Archives::newest($dir, Archives::oarPrefix('alpha', 'sim1', null), 'oar')))
            ->toBe('alpha-sim1-noassets-20261003-100000.oar')
            ->and(basename((string) Archives::newest($dir, Archives::oarPrefix('alpha', 'sim1', 'Welcome'), 'oar')))
            ->toBe('alpha-sim1-Welcome-20261009-100000.oar')
            ->and(Archives::newest($dir, Archives::oarPrefix('alpha', 'other', null), 'oar'))->toBeNull();
    });
});

describe('the arguments of a command on an archive', function () {
    test('are the kind, the options and the other words', function () {
        $spec = Archives::parse('save', ['oar', 'alpha', 'sim1', '--noassets', '--perm=CMT', '--region', 'Welcome', 'copy.oar']);

        expect($spec['kind'])->toBe('oar')
            ->and($spec['options'])->toBe(['noassets' => true, 'perm' => 'CMT', 'region' => 'Welcome'])
            ->and($spec['words'])->toBe(['alpha', 'sim1', 'copy.oar']);
    });

    test('take a short option and its value', function () {
        $spec = Archives::parse('save', ['iar', '-h', 'https://home.example.org', '-v', 'Jane', 'Doe', '/Clothing', 'pw']);

        expect($spec['options'])->toBe(['home' => 'https://home.example.org', 'verbose' => true])
            ->and(Archives::positional('save', 'iar', $spec['words']))
            ->toBe(['first' => 'Jane', 'last' => 'Doe', 'path' => '/Clothing', 'password' => 'pw']);
    });

    test('are refused when they make no sense', function () {
        expect(fn() => Archives::parse('save', ['alpha']))->toThrow(InvalidArgumentException::class)
            ->and(fn() => Archives::parse('save', ['alpha', 'oar']))->toThrow(InvalidArgumentException::class)
            ->and(fn() => Archives::parse('save', ['oar', '-z']))->toThrow(InvalidArgumentException::class, 'unknown option')
            ->and(fn() => Archives::parse('load', ['iar', '--merge=1']))->toThrow(InvalidArgumentException::class, 'no value')
            ->and(fn() => Archives::positional('save', 'oar', ['a', 'b']))->toThrow(InvalidArgumentException::class, 'too many');
    });

    test('makes the console line', function () {
        expect(Archives::line('save', 'oar', ['noassets' => true, 'region' => 'Welcome', 'perm' => 'CMT'], ['/tmp/a b.oar']))
            ->toBe('save oar --noassets --perm=CMT "/tmp/a b.oar"')
            ->and(Archives::line('load', 'iar', ['merge' => true], ['Jane', 'Doe', '/My Stuff', 'pw', '/tmp/x.iar']))
            ->toBe('load iar --merge Jane Doe "/My Stuff" pw /tmp/x.iar');
    });
});

describe('the instance a command names', function () {
    test('is a grid, a simulator or a region', function () {
        $profile = archive_install();

        expect(Instances::locate($profile, []))->toBe(['alpha', null, null])
            ->and(Instances::locate($profile, ['alpha']))->toBe(['alpha', null, null])
            ->and(Instances::locate($profile, ['alpha', 'sim1']))->toBe(['alpha', 'alpha_sim1', null])
            ->and(Instances::locate($profile, ['alpha', '_sim2']))->toBe(['alpha', 'alpha_sim2', null])
            ->and(Instances::locate($profile, ['alpha_sim2']))->toBe(['alpha', 'alpha_sim2', null])
            ->and(Instances::locate($profile, ['alpha', 'south']))->toBe(['alpha', 'alpha_sim2', 'South'])
            ->and(Instances::locate($profile, ['Welcome']))->toBe(['alpha', 'alpha_sim1', 'Welcome'])
            ->and(Instances::resolve($profile, ['North']))->toBe(['alpha', 'alpha_sim2'])
            ->and(fn() => Instances::locate($profile, ['alpha', 'nope']))->toThrow(InvalidArgumentException::class);
    });
});

describe('opensim save and load', function () {
    /** A runner whose simulators answer through their log, the way a real one does. */
    function archive_runner(array $profile, array &$sent, string $answer): ArchiveRunner
    {
        return new ArchiveRunner(
            $profile,
            function (string $instance, string $lines) use (&$sent, $answer, $profile): bool {
                $sent[] = [$instance, $lines];
                $log = "{$profile['LogsRoot']}/$instance.log";
                // The file is made by the simulator, then it says so
                if (preg_match('/save (?:oar|iar).* (\S+\.(?:oar|iar))\n$/', $lines, $m)) {
                    file_put_contents($m[1], 'archive');
                }
                file_put_contents($log, "2026-10-03 10:00:00,000 INFO  [ARCHIVER]: $answer\n", FILE_APPEND);

                return true;
            },
            static fn(string $ini): bool => str_contains($ini, 'alpha_sim1') || str_contains($ini, 'alpha_sim2'),
            static function (string $message): void {},
            2,
        );
    }

    test('saves a region with a telling name', function () {
        $profile = archive_install();
        $sent = [];
        [$code, $file] = archive_runner($profile, $sent, 'Finished writing out OAR for Welcome')->run('save', ['oar', 'alpha', 'sim1', '--noassets']);

        expect($code)->toBe(0)
            ->and($file)->toStartWith("{$profile['DataRoot']}/alpha/backups/oar/alpha-sim1-Welcome-")
            ->and($file)->toContain('-noassets-2')
            ->and(is_file($file))->toBeTrue()
            ->and($sent[0][0])->toBe('alpha_sim1')
            ->and($sent[0][1])->toStartWith("change region Welcome\nsave oar --noassets /");
    });

    test('save the region that is named in place of the simulator', function () {
        $profile = archive_install();
        $sent = [];
        [$code, $file] = archive_runner($profile, $sent, 'Finished writing out OAR')->run('save', ['oar', 'alpha', 'South']);

        expect($code)->toBe(0)
            ->and(basename((string) $file))->toStartWith('alpha-sim2-South-2')
            ->and($sent[0][0])->toBe('alpha_sim2')
            ->and($sent[0][1])->toStartWith("change region South\nsave oar ");
    })->depends('saves a region with a telling name');

    test('asks which region, or takes all', function () {
        $profile = archive_install();
        $sent = [];
        $runner = archive_runner($profile, $sent, 'Finished writing out OAR');
        [$code] = $runner->run('save', ['oar', 'alpha', 'sim2']);
        [$all, $file] = $runner->run('save', ['oar', 'alpha', 'sim2', '--all']);

        expect($code)->toBe(2)
            ->and($all)->toBe(0)
            ->and(basename((string) $file))->toStartWith('alpha-sim2-2')
            ->and($sent[0][1])->toStartWith("change region root\nsave oar --all ");
    })->depends('saves a region with a telling name');

    test('load the newest archive of a region when no file is given', function () {
        $profile = archive_install();
        $dir = "{$profile['DataRoot']}/alpha/backups/oar";
        touch("$dir/alpha-sim1-Welcome-20261001-100000.oar");
        touch("$dir/alpha-sim1-Welcome-20261002-100000.oar");
        $sent = [];
        [$code, $file] = archive_runner($profile, $sent, 'Successfully loaded archive')->run('load', ['oar', 'alpha', 'sim1', '--merge']);

        expect($code)->toBe(0)
            ->and(basename((string) $file))->toBe('alpha-sim1-Welcome-20261002-100000.oar')
            ->and($sent[0][1])->toBe("change region Welcome\nload oar --merge $file\n");
    })->depends('saves a region with a telling name');

    test('saves an inventory', function () {
        $profile = archive_install();
        $sent = [];
        [$code, $file] = archive_runner($profile, $sent, 'Saved archive with 12 items for Jane Doe')->run('save', ['iar', 'Jane', 'Doe', '/', 'pw']);

        expect($code)->toBe(0)
            ->and(basename((string) $file))->toStartWith('alpha-Jane-Doe-2')
            ->and($sent[0][0])->toBe('alpha_sim1')
            ->and($sent[0][1])->toStartWith('save iar Jane Doe / pw /');
    })->depends('saves a region with a telling name');

    test('report what the simulator says went wrong', function () {
        $profile = archive_install();
        $sent = [];
        $messages = [];
        $runner = new ArchiveRunner(
            $profile,
            function (string $instance, string $lines) use ($profile): bool {
                file_put_contents("{$profile['LogsRoot']}/alpha_sim1.log", "2026-10-03 10:00:00,000 ERROR [INVENTORY ARCHIVER]: Password for user Jane Doe incorrect.  Please try again.\n", FILE_APPEND);

                return true;
            },
            static fn(string $ini): bool => true,
            function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
            2,
        );
        [$code] = $runner->run('save', ['iar', 'Jane', 'Doe', '/', 'wrong']);

        expect($code)->toBe(1)->and(implode("\n", $messages))->toContain('incorrect');
    })->depends('saves a region with a telling name');

    test('refuses a file, a stopped sim, no password', function () {
        $profile = archive_install();
        $sent = [];
        $runner = archive_runner($profile, $sent, 'Finished writing out OAR');
        $existing = "{$profile['DataRoot']}/alpha/backups/oar/old.oar";

        expect($runner->run('save', ['oar', 'alpha', 'sim1', 'old.oar'])[0])->toBe(1)
            ->and($existing)->toBeFile()
            ->and($sent)->toBe([]);

        $down = new ArchiveRunner($profile, fn() => true, fn() => false, static function (string $m): void {}, 1);
        expect($down->run('save', ['iar', 'Jane', 'Doe', '/', 'pw'])[0])->toBe(1);

        putenv('OPENSIM_IAR_PASSWORD');
        $noPassword = archive_runner($profile, $sent, 'x');
        expect($noPassword->run('save', ['iar', 'Jane', 'Doe', '/'])[0])->toBe(2);
    })->depends('saves a region with a telling name');
});

describe('opensim backup', function () {
    test('holds config, data and databases', function () {
        $profile = archive_install();
        $dumped = [];
        $backup = new Backup(
            $profile,
            function (array $db, string $file) use (&$dumped): void {
                $dumped[] = $db['name'];
                file_put_contents($file, 'dump');
            },
            static function (string $message): void {},
            '20261003-101500',
        );
        $files = $backup->run(['alpha']);

        expect($files)->toBe(["{$profile['DataRoot']}/backups/admin/alpha-20261003-101500.tar.gz"])
            ->and(substr(sprintf('%o', fileperms($files[0])), -3))->toBe('600')
            ->and($dumped)->toBe(['alpha_robust', 'alpha_sim1', 'alpha_sim2']);

        $list = shell_exec('tar -tzf ' . escapeshellarg($files[0]));
        expect($list)->toContain('manifest.json')
            ->and($list)->toContain('db/alpha_robust.sql.gz')
            ->and($list)->toContain('etc/grids/alpha/Robust.HG.ini')
            ->and($list)->toContain('etc/grids/alpha/sims/alpha_sim2/regions/South.ini')
            ->and($list)->toContain('etc/robust.d/alpha.ini')
            ->and($list)->toContain('etc/opensim.conf')
            ->and($list)->toContain('var/alpha/alpha_sim1/state')
            ->and($list)->not->toContain('old.oar');
        // The links keep their targets
        expect(shell_exec('tar -tvzf ' . escapeshellarg($files[0]) . ' | grep "robust.d/alpha.ini"'))->toContain("{$profile['EtcRoot']}/grids/alpha/Robust.HG.ini");
    });

    test('takes the archives of the users and the logs when asked', function () {
        $profile = archive_install();
        file_put_contents("{$profile['LogsRoot']}/alpha_sim1.log", 'log');
        $backup = new Backup($profile, fn(array $db, string $file) => file_put_contents($file, 'x'), static function (string $m): void {}, '20261003-101500');
        $files = $backup->run(['alpha'], ['logs' => true, 'archives' => true, 'output' => "{$profile['root']}/out"]);

        $list = shell_exec('tar -tzf ' . escapeshellarg($files[0]));
        expect($files[0])->toBe("{$profile['root']}/out/alpha-20261003-101500-logs-archives.tar.gz")
            ->and($list)->toContain('var/alpha/backups/oar/old.oar')
            ->and($list)->toContain('logs/alpha_sim1.log');
    })->depends('holds config, data and databases');

    test('is the one of a simulator, without the other ones', function () {
        $profile = archive_install();
        $dumped = [];
        $backup = new Backup(
            $profile,
            function (array $db, string $file) use (&$dumped): void {
                $dumped[] = $db['name'];
                file_put_contents($file, 'x');
            },
            static function (string $m): void {},
            '20261003-101500',
        );
        $files = $backup->run(['alpha', '_sim1']);

        $list = shell_exec('tar -tzf ' . escapeshellarg($files[0]));
        expect(basename($files[0]))->toBe('alpha-sim1-20261003-101500.tar.gz')
            ->and($dumped)->toBe(['alpha_sim1'])
            ->and($list)->toContain('etc/grids/alpha/sims/alpha_sim1.ini')
            ->and($list)->toContain('etc/grids/alpha/alpha.conf')
            ->and($list)->not->toContain('alpha_sim2')
            ->and($list)->not->toContain('Robust.HG.ini.bak');
    })->depends('holds config, data and databases');

    test('leaves nothing behind when a dump fails', function () {
        $profile = archive_install();
        $backup = new Backup($profile, function (array $db, string $file): void {
            throw new RuntimeException('dump failed');
        }, static function (string $m): void {}, '20261003-101500');

        expect(fn() => $backup->run(['alpha']))->toThrow(RuntimeException::class, 'dump failed');
        $left = glob("{$profile['DataRoot']}/backups/admin/{,.}*", GLOB_BRACE) ?: [];
        expect(array_filter($left, static fn(string $f): bool => !in_array(basename($f), ['.', '..'], true)))->toBe([]);
    })->depends('holds config, data and databases');
});
