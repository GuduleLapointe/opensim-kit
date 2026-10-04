<?php
/**
 * The configuration of a Robust that was not made by the kit, ported: what the setup asks is detected, the rest of
 * what differs from the defaults of the core is kept.
 */

use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Grid\GridPlan;
use OpenSim\Installer\Grid\SimPlan;
use OpenSim\Installer\Import\IniReader;
use OpenSim\Installer\Import\RobustImporter;
use OpenSim\Installer\Import\SimImporter;

/**
 * An install of the old way in a temporary tree: Robust.ini with an include, and a ConfigDirectory.
 *
 * @return string The path of Robust.ini.
 */
function import_tree(): string
{
    $root = test_tmp() . '/import-' . bin2hex(random_bytes(4));
    mkdir("$root/bin/robust-include", 0o755, true);
    mkdir("$root/bin/config-include", 0o755);
    file_put_contents(
        "$root/bin/Robust.HG.ini",
        <<<'INI'
        [Const]
            BaseHostname = "old.example.org"
            BaseURL = "http://${Const|BaseHostname}"
            PublicPort = "8002"
            PrivatePort = "8003"
            WebURL = "https://old.example.org"
        [Startup]
            ConfigDirectory = "robust-include"
        [DatabaseService]
            ConnectionString = "Data Source=localhost;Database=old_robust;User ID=oldrobust;Password=pw;Old Guids=true;"
        [GridInfoService]
            gridname = "Old World"
            gridnick = "oldworld"
        [AssetService]
            BaseDirectory = "./fsassets/data"
        [GridService]
            MaxRegionSize = 1024
            ExportSupported = true
        [Network]
            ConsoleUser = "admin"
            ConsolePass = "adminpw"
            ConsolePort = 8004
        INI,
    );
    file_put_contents("$root/bin/robust-include/extra.ini", "[Modules]\n    MyModule = \"on\"\n");

    return "$root/bin/Robust.HG.ini";
}

describe('IniReader', function () {
    test('reads a file and its folder', function () {
        $ini = IniReader::load(import_tree());

        expect($ini['Const']['BaseURL'])->toBe('http://${Const|BaseHostname}');
        expect($ini['Modules']['MyModule'])->toBe('on');
        expect(IniReader::expand($ini)['Const']['BaseURL'])->toBe('http://old.example.org');
    });

    test('follows Include-* and stops at a loop', function () {
        $root = test_tmp() . '/import-inc-' . bin2hex(random_bytes(4));
        mkdir("$root/bin/config-include", 0o755, true);
        file_put_contents("$root/bin/OpenSim.ini", "[Architecture]\n    Include-Architecture = \"config-include/Grid.ini\"\n[Startup]\n    a = 1\n");
        file_put_contents("$root/bin/config-include/Grid.ini", "[Modules]\n    Include-Loop = \"../OpenSim.ini\"\n    b = 2\n");

        $ini = IniReader::load("$root/bin/OpenSim.ini");

        expect($ini['Modules']['b'])->toBe('2');
        expect($ini['Startup']['a'])->toBe('1');
    });
});

describe('The plan of an imported Robust', function () {
    test('has what the setup asks, read from the original', function () {
        $path = import_tree();
        $plan = RobustImporter::plan(
            IniReader::load($path),
            $path,
            ['EtcRoot' => '/etc/opensim', 'DataRoot' => '/var/lib/opensim', 'CacheRoot' => '/var/cache/opensim', 'LogsRoot' => '/var/log/opensim'],
            null,
            '/usr/share/opensim/0.9.3.0',
        );

        expect($plan)->toBeInstanceOf(GridPlan::class);
        expect($plan->gridName)->toBe('Old World');
        expect($plan->gridNick)->toBe('oldworld');
        expect($plan->enableHypergrid)->toBeTrue();
        expect($plan->baseHostname)->toBe('old.example.org');
        expect($plan->publicPort)->toBe(8002);
        expect($plan->dbName)->toBe('old_robust');
        expect($plan->dbPass)->toBe('pw');
        expect($plan->consoleMode)->toBe('rest');
        expect($plan->consolePort)->toBe(8004);
        expect($plan->gridDir)->toBe('/etc/opensim/grids/oldworld');
        expect($plan->enable)->toBeFalse();
    });

    test('says what is missing rather than guess', function () {
        $plan = RobustImporter::plan(['GridInfoService' => ['gridname' => 'X']], '/x/Robust.ini', [], null, '/core');
        expect($plan)->toBeString();
        expect($plan)->toContain('database');
    });
});

describe('The customizations of an imported Robust', function () {
    test('are what differs from the defaults', function () {
        $original = [
            'Const' => ['PublicPort' => '8002', 'BaseHostname' => 'old.example.org', 'Mine' => 'x'],
            'GridService' => ['MaxRegionSize' => '1024', 'ExportSupported' => 'true', 'Unknown' => 'x'],
            'Modules' => ['MyModule' => 'on'],
            'Network' => ['ConsolePort' => '8004'],
        ];
        $generated = [
            'Const' => ['PublicPort' => '8002'],
            'Network' => ['ConsolePort' => '8004'],
        ];
        $example = [
            'GridService' => ['MaxRegionSize' => '0', 'ExportSupported' => 'true'],
            'Network' => ['ConsolePort' => '0'],
        ];

        $found = RobustImporter::customizations($original, $generated, $example, '/old/bin', "MaxRegionSize = 0\n;ExportSupported = true\n");
        $by = array_column($found, null, 'key');

        // What the setup asked and wrote is its own (the console), the layout of the kit too, the defaults are no choice
        expect(array_keys($by))->toBe(['MaxRegionSize', 'Unknown', 'MyModule']);
        expect($by['MaxRegionSize']['value'])->toBe('1024');
        expect($by['MaxRegionSize']['unknown'])->toBeFalse();
        expect($by['Unknown']['unknown'])->toBeTrue();
    });

    test('keep the data places, made absolute', function () {
        $found = RobustImporter::customizations(
            ['AssetService' => ['BaseDirectory' => './fsassets/data', 'SpoolDirectory' => '${Const|CacheDirectory}/tmp', 'Name' => '../x']],
            ['AssetService' => ['BaseDirectory' => '${Const|DataDirectory}/fsassets/data']],
            ['AssetService' => ['BaseDirectory' => './fsassets/data']],
            '/old/bin',
            '',
            ['AssetService' => ['BaseDirectory' => './fsassets/data', 'SpoolDirectory' => '/old/cache/tmp', 'Name' => '../x']],
        );
        $by = array_column($found, null, 'key');

        expect($by['BaseDirectory']['value'])->toBe('/old/bin/fsassets/data');
        expect($by['BaseDirectory']['path'])->toBeTrue();
        expect($by['SpoolDirectory']['value'])->toBe('/old/cache/tmp');
        expect($by['Name']['value'])->toBe('../x');
    });

    test('are put into the config, quoted', function () {
        $text = "[GridService]\n    ;MaxRegionSize = 0\n[Other]\n    a = 1\n";

        $out = RobustImporter::inject($text, [
            ['section' => 'GridService', 'key' => 'MaxRegionSize', 'value' => '1024', 'unknown' => false],
            ['section' => 'Modules', 'key' => 'MyModule', 'value' => 'on', 'unknown' => false],
        ]);

        expect($out)->toContain('MaxRegionSize = 1024');
        expect($out)->toContain("[Modules]\nMyModule = \"on\"");
    });
});

/**
 * Run libexec/import.php as a user whose install is a temporary tree with a core that has the example of Robust.
 *
 * @param list<string> $arguments What follows `opensim import`.
 * @param string $original The Robust config to port.
 * @return array{0:int,1:string,2:string,3:string} Exit status, output, errors, the root of the tree.
 */
function import_run(array $arguments, string $original): array
{
    $root = test_tmp() . '/import-run-' . bin2hex(random_bytes(4));
    foreach (['home', 'etc', 'var', 'cache', 'logs', 'core/bin', 'old/bin'] as $dir) {
        mkdir("$root/$dir", 0o755, true);
    }
    file_put_contents(
        "$root/home/.opensim.conf",
        "[Defaults]\nDefaultProfile = 0.9.3.0\n\n[0.9.3.0]\nEtcRoot = $root/etc\nDataRoot = $root/var\nCacheRoot = $root/cache\nLogsRoot = $root/logs\nCoreDirectory = $root/core\nCoreRoot = $root\n",
    );
    file_put_contents(
        "$root/core/bin/Robust.HG.ini.example",
        "[Const]\n    BaseHostname = \"127.0.0.1\"\n[GridService]\n    MaxRegionSize = 0\n[AssetService]\n    BaseDirectory = \"./fsassets/data\"\n[GridInfoService]\n    gridname = \"x\"\n",
    );
    file_put_contents("$root/old/bin/Robust.HG.ini", $original);

    $code = dirname(__DIR__, 2);
    $process = proc_open(
        [PHP_BINARY, "$code/libexec/import.php", ...array_map(static fn(string $a): string => str_replace('@OLD@', "$root/old/bin", $a), $arguments)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $code,
        ['HOME' => "$root/home", 'PATH' => getenv('PATH')],
    );
    $output = (string) stream_get_contents($pipes[1]);
    $errors = trim((string) stream_get_contents($pipes[2]));

    return [proc_close($process), $output, $errors, $root];
}

describe('opensim import, the config of a grid', function () {
    $original = "[Const]\n BaseHostname = \"old.example.org\"\n PublicPort = 8002\n PrivatePort = 8003\n[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=old_robust;User ID=oldrobust;Password=pw;\"\n[GridInfoService]\n gridname = \"Old World\"\n gridnick = \"oldworld\"\n[GridService]\n MaxRegionSize = 1024\n[AssetService]\n BaseDirectory = \"./fsassets/data\"\n SpoolDirectory = \"./tmp\"\n";

    test('tells the plan, writes nothing', function () use ($original) {
        [$status, $output, , $root] = import_run(['@OLD@/Robust.HG.ini'], $original);

        expect($status)->toBe(0);
        expect($output)->toContain('Grid Old World (oldworld)');
        expect($output)->toContain('[GridService] MaxRegionSize = 1024');
        expect($output)->toContain("[AssetService] SpoolDirectory = $root/old/bin/tmp");
        expect($output)->toContain("[AssetService] BaseDirectory = $root/old/bin/fsassets/data");
        expect($output)->toContain('Nothing was written');
        expect(is_dir("$root/etc/grids/oldworld"))->toBeFalse();
    });

    test('writes the grid, leaves the original', function () use ($original) {
        [$status, $output, $errors, $root] = import_run(['@OLD@/Robust.HG.ini', '--apply'], $original);

        expect($errors)->toBe('');
        expect($status)->toBe(0);
        $ini = (string) file_get_contents("$root/etc/grids/oldworld/Robust.HG.ini");
        expect($ini)->toContain('MaxRegionSize = 1024');
        expect($ini)->toContain('Database=old_robust;User ID=oldrobust');
        expect($ini)->toContain('gridname = "Old World"');
        expect(file_get_contents("$root/etc/grids/oldworld/import-report.txt"))->toContain('MaxRegionSize = 1024');
        expect(file_get_contents("$root/old/bin/Robust.HG.ini"))->toBe($original);
        expect(is_link("$root/etc/robust.d/oldworld.ini"))->toBeFalse();
    });

    test('refuses a grid that is already configured', function () use ($original) {
        [, , , $root] = import_run(['@OLD@/Robust.HG.ini', '--apply'], $original);
        $again = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/libexec/import.php', "$root/old/bin/Robust.HG.ini"],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            ['HOME' => "$root/home", 'PATH' => getenv('PATH')],
        );
        $errors = (string) stream_get_contents($pipes[2]);

        expect(proc_close($again))->toBe(2);
        expect($errors)->toContain('already configured');
    });
});

describe('The plan of an imported simulator', function () {
    /** The config of a simulator of the old way, with its database in an included file. */
    function import_sim_tree(string $architecture = 'config-include/GridHypergrid.ini'): string
    {
        $root = test_tmp() . '/import-sim-' . bin2hex(random_bytes(4));
        mkdir("$root/sim1/bin/config-include", 0o755, true);
        mkdir("$root/sim1/bin/Regions", 0o755);
        file_put_contents(
            "$root/sim1/bin/OpenSim.ini",
            "[Startup]\n    regionload_regionsdir = \"Regions\"\n[Network]\n    http_listener_port = 9100\n    ConsoleUser = \"u\"\n    ConsolePass = \"p\"\n    console_port = 9104\n" .
                "[Estates]\n    DefaultEstateName = \"Old Estate\"\n    DefaultEstateOwnerName = \"Jane Doe\"\n[Architecture]\n    Include-Architecture = \"$architecture\"\n",
        );
        file_put_contents(
            "$root/sim1/bin/config-include/GridHypergrid.ini",
            "[Modules]\n    X = 1\n[DatabaseService]\n    ConnectionString = \"Data Source=localhost;Database=old_sim1;User ID=oldsim;Password=pw2;\"\n",
        );
        file_put_contents("$root/sim1/bin/Regions/Welcome.ini", "[Welcome]\nRegionUUID = 607c44e9-3d01-45eb-a07e-937dc72dbadb\nLocation = 1000,1000\nExternalHostName = old.example.org\n");

        return "$root/sim1/bin/OpenSim.ini";
    }

    test('has what the setup asks', function () {
        $path = import_sim_tree();
        $grid = new GridInfo();
        $grid->nick = 'oldworld';
        $grid->name = 'Old World';
        $grid->dir = '/etc/opensim/grids/oldworld';
        $grid->baseHostname = 'old.example.org';
        $grid->dataDirectory = '/var/lib/opensim/oldworld';
        $grid->cacheDirectory = '/var/cache/opensim/oldworld';

        $plan = SimImporter::plan(IniReader::load($path), $path, $grid, [], null, '/core');

        expect($plan)->toBeInstanceOf(SimPlan::class);
        expect($plan->simName)->toBe('sim1');
        expect($plan->slug)->toBe('oldworld_sim1');
        expect($plan->httpPort)->toBe(9100);
        expect($plan->dbName)->toBe('old_sim1');
        expect($plan->consolePort)->toBe(9104);
        expect($plan->estateName)->toBe('Old Estate');
        expect($plan->estateOwner)->toBe('Jane Doe');
        expect($plan->createRegion)->toBeFalse();
        expect(array_map('basename', SimImporter::regionFiles(IniReader::load($path), $path)))->toBe(['Welcome.ini']);
        expect(SimImporter::externalHost(SimImporter::regionFiles(IniReader::load($path), $path)))->toBe('old.example.org');
    });

    test('is refused for a standalone sim', function () {
        $path = import_sim_tree('config-include/StandaloneHypergrid.ini');

        $plan = SimImporter::plan(IniReader::load($path), $path, new GridInfo(), [], null, '/core');

        expect($plan)->toBeString();
        expect($plan)->toContain('standalone');
    });
});

describe('opensim import, the config of a simulator', function () {
    test('writes the sim and its regions', function () {
        $robust = "[Const]\n BaseHostname = \"old.example.org\"\n PublicPort = 8002\n PrivatePort = 8003\n[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=old_robust;User ID=oldrobust;Password=pw;\"\n[GridInfoService]\n gridname = \"Old World\"\n gridnick = \"oldworld\"\n";
        [$status, , $errors, $root] = import_run(['@OLD@/Robust.HG.ini', '--apply'], $robust);
        expect($status)->toBe(0, $errors);

        $sim = import_sim_tree();
        $code = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, "$code/libexec/import.php", 'oldworld', $sim, '--apply'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $code,
            ['HOME' => "$root/home", 'PATH' => getenv('PATH')],
        );
        $output = (string) stream_get_contents($pipes[1]);
        $errors = trim((string) stream_get_contents($pipes[2]));

        expect(proc_close($process))->toBe(0, $errors);
        $ini = (string) file_get_contents("$root/etc/grids/oldworld/sims/oldworld_sim1.ini");
        expect($ini)->toContain('Database=old_sim1;User ID=oldsim');
        expect($ini)->toContain('http_listener_port = 9100');
        expect($ini)->toContain('DefaultEstateName = "Old Estate"');
        expect($ini)->toContain("[Modules]\nX = 1");
        expect(file_get_contents("$root/etc/grids/oldworld/sims/oldworld_sim1/regions/Welcome.ini"))->toContain('607c44e9-3d01-45eb-a07e-937dc72dbadb');
        expect(file_exists("$root/etc/grids/oldworld/sims/oldworld_sim1.import-report.txt"))->toBeTrue();
        expect(is_link("$root/etc/opensim.d/oldworld_sim1.ini"))->toBeFalse();
        expect($output)->toContain('Written:');
    });
});

/** Run `opensim import` with a file of the given content. */
function import_file(string $name, string $content, array $arguments = []): array
{
    $dir = test_tmp() . '/import-file-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("$dir/$name", $content);
    $code = dirname(__DIR__, 2);
    $process = proc_open(
        [PHP_BINARY, "$code/libexec/import.php", ...array_map(static fn(string $a): string => str_replace('@FILE@', "$dir/$name", $a), $arguments)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $code,
        ['HOME' => "$dir/home", 'PATH' => getenv('PATH')],
    );
    $output = (string) stream_get_contents($pipes[1]);
    $errors = trim((string) stream_get_contents($pipes[2]));

    return [proc_close($process), $output, $errors];
}

describe('opensim import, a setup file', function () {
    $setup = "grid: { name: My Grid, database: { user: a, password: b } }\nowner: { name: Jane Doe, password: hunter22 }\nsimulators:\n  - regions: [ { name: One }, { name: Two } ]\nusers:\n  - { first: Bob, last: Roe, password: pw123456 }\n";

    test('tells what it holds, makes nothing', function () use ($setup) {
        [$status, $output] = import_file('setup.yaml', $setup, ['@FILE@']);

        expect($status)->toBe(0)
            ->and($output)->toContain('the grid My Grid')->toContain('1 simulator(s) with 2 region(s)')
            ->toContain('1 account(s)')->toContain('Nothing was made');
    });

    test('is restricted to what is asked: --users', function () use ($setup) {
        [, $output] = import_file('setup.yaml', $setup, ['@FILE@', '--users']);

        expect($output)->toContain('1 account(s)')->not->toContain('the grid')->not->toContain('simulator');
    });

    test('is restricted by --grid, --simulator', function () use ($setup) {
        [, $output] = import_file('setup.yaml', $setup, ['@FILE@', '--grid', '--simulator']);

        expect($output)->toContain('the grid My Grid')->toContain('simulator(s)')->not->toContain('account(s)');
    });

    test('says when what is asked is not in the file', function () {
        [$status, , $errors] = import_file('accounts.json', '[{"first":"Bob","last":"Roe"}]', ['@FILE@', '--grid']);

        expect($status)->toBe(2)->and($errors)->toContain('nothing to import');
    });

    test('says what is wrong in the setup', function () {
        [$status, , $errors] = import_file('setup.yaml', "grid:\n  name: X\n", ['@FILE@']);

        expect($status)->toBe(2)->and($errors)->toContain('grid.database.user is required');
    });
});
