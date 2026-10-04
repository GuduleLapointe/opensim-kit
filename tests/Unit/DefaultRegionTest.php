<?php
/**
 * The roles of the regions of a grid (default, default for Hypergrid, fallback): given to a
 * region when its simulator is created, written in the Robust config as the key Robust looks for,
 * read back from it.
 */

use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Grid\GridPlan;
use OpenSim\Installer\Grid\RegionFlags;
use OpenSim\Installer\Grid\RegionName;
use OpenSim\Installer\Grid\RobustConfig;

/**
 * The Robust config a plan generates, from a minimal example.
 *
 * @param GridPlan $plan The plan.
 * @return string Generated Robust.HG.ini.
 */
function robust_generated(GridPlan $plan)
{
    $bin = sys_get_temp_dir() . '/default-region-' . bin2hex(random_bytes(4));
    mkdir($bin);
    file_put_contents(
        "$bin/Robust.HG.ini.example",
        "[Const]\n[GridService]\n    ; Region_Welcome_Area = \"DefaultRegion, DefaultHGRegion\"\n",
    );
    $plan->binDir = $bin;
    $ini = (new RobustConfig())->generate($plan);
    unlink("$bin/Robust.HG.ini.example");
    rmdir($bin);

    return $ini;
}

describe('RegionName', function () {
    test('accepts what a region can be called and refuses the rest', function () {
        expect(RegionName::problem('Welcome'))->toBeNull();
        expect(RegionName::problem('Welcome Area 2'))->toBeNull();
        expect(RegionName::problem(''))->not->toBeNull();
        expect(RegionName::problem('Bad"name'))->not->toBeNull();
    });

    test('is the key of the flags', function () {
        expect(RegionName::configKey('Welcome'))->toBe('Region_Welcome');
        expect(RegionName::configKey(' Welcome Area '))->toBe('Region_Welcome_Area');
        expect(RegionName::fromConfigKey('Region_Welcome_Area'))->toBe('Welcome Area');
    });

    test('is known among names the way Robust compares them', function () {
        $known = ['welcome area' => true, 'sandbox' => true];

        expect(RegionName::isIn('Welcome Area', $known))->toBeTrue();
        expect(RegionName::isIn('Welcome_Area', $known))->toBeTrue();
        expect(RegionName::isIn('Welcome', $known))->toBeFalse();
    });
});

/** A Robust config as the distribution ships it: examples, and the comments of the next section before its header. */
function robust_example()
{
    return implode("\n", [
        '[GridService]',
        '    StorageProvider = "OpenSim.Data.MySQL.dll:MySqlRegionData"',
        '    ;; Region_Welcome_Area = "DefaultRegion, DefaultHGRegion"',
        '    MapTileDirectory = "x"',
        '',
        '[Other]',
        '    Setting = 1',
        '',
        ';; Documentation of the next section',
        ';; on two lines',
        '[FreeswitchService]',
        '    LocalServiceModule = "y"',
        '',
    ]);
}

/** A file holding a text, to give to something that reads files. */
function robust_file(string $text)
{
    $file = tempnam(sys_get_temp_dir(), 'robust');
    file_put_contents($file, $text);

    return $file;
}

describe('Grid setup', function () {
    test('gives no region the roles: that is for the first simulator', function () {
        $plan = new GridPlan();
        $plan->baseHostname = 'localhost';

        expect(robust_generated($plan))->not->toMatch('/^\s*Region_\w+\s*=/m');
    });
});

describe('Roles of a region', function () {
    test('are default, default HG and fallback', function () {
        expect(RegionFlags::roles(true))->toBe(['DefaultRegion', 'DefaultHGRegion', 'FallbackRegion']);
        expect(RegionFlags::roles(false))->toBe(['DefaultRegion', 'FallbackRegion']);
    });

    test('are found in the config, the commented example left out', function () {
        $text = "[GridService]\n    ; Region_Welcome_Area = \"DefaultRegion\"\n    Region_Home = \"DefaultRegion, Persistent\"\n    Region_Two = \"FallbackRegion\"\n";

        expect(RegionFlags::found($text))->toBe(['DefaultRegion', 'Persistent', 'FallbackRegion']);
        expect(RegionFlags::found("[GridService]\n    ; Region_Welcome_Area = \"DefaultRegion\"\n"))->toBe([]);
    });

    test('are written under the key of Robust', function () {
        $file = robust_file(robust_example());
        RegionFlags::give($file, 'Welcome Home', ['DefaultRegion', 'DefaultHGRegion']);
        $lines = explode("\n", (string) file_get_contents($file));
        unlink($file);

        $at = array_search('Region_Welcome_Home = "DefaultRegion, DefaultHGRegion, Persistent"', $lines, true);
        expect($at)->not->toBeFalse();
        expect($lines[$at - 1])->toContain('; Region_Welcome_Area');
    });

    test('are written before the next section', function () {
        $file = robust_file(str_replace('    ;; Region_Welcome_Area = "DefaultRegion, DefaultHGRegion"' . "\n", '', robust_example()));
        RegionFlags::give($file, 'Welcome', ['DefaultRegion']);
        $text = (string) file_get_contents($file);
        unlink($file);

        expect($text)->toContain('Region_Welcome = "DefaultRegion, Persistent"');
        expect(strpos($text, 'Region_Welcome ='))->toBeLessThan(strpos($text, '[Other]'));
        expect($text)->toContain(";; Documentation of the next section\n;; on two lines\n[FreeswitchService]");
    });

    test('are kept when a region gets more', function () {
        $file = robust_file("[GridService]\nRegion_Welcome = \"DefaultRegion, Persistent\"\n");
        RegionFlags::give($file, 'Welcome', ['FallbackRegion']);
        $text = (string) file_get_contents($file);
        unlink($file);

        expect($text)->toContain('Region_Welcome = "DefaultRegion, Persistent, FallbackRegion"');
        expect(substr_count($text, 'Region_Welcome'))->toBe(1);
    });

    test('are read back from the config of the grid', function () {
        $file = robust_file("[GridService]\nRegion_Welcome = \"DefaultRegion, Persistent\"\n");
        $read = GridInfo::parse($file);
        unlink($file);

        expect($read['regionFlags'])->toBe(['DefaultRegion', 'Persistent']);
    });
});

describe('The landing region of a grid', function () {
    test('is needed until a region is default', function () {
        $grid = new GridInfo();

        expect($grid->needsLandingRegion())->toBeTrue();
        expect($grid->missingRoles())->toBe(['DefaultRegion', 'DefaultHGRegion', 'FallbackRegion']);

        $grid->regionFlags = ['DefaultRegion'];
        expect($grid->needsLandingRegion())->toBeTrue(); // the Hypergrid visitors have none yet
        expect($grid->missingRoles())->toBe(['DefaultHGRegion', 'FallbackRegion']);

        $grid->regionFlags = ['DefaultRegion', 'DefaultHGRegion'];
        expect($grid->needsLandingRegion())->toBeFalse();
        expect($grid->missingRoles())->toBe(['FallbackRegion']);
    });

    test('skips the HG role without Hypergrid', function () {
        $grid = new GridInfo();
        $grid->hypergrid = false;

        expect($grid->missingRoles())->toBe(['DefaultRegion', 'FallbackRegion']);
        $grid->regionFlags = ['DefaultRegion'];
        expect($grid->needsLandingRegion())->toBeFalse();
    });

    test('is the business of the grid run elsewhere', function () {
        $remote = new GridInfo();
        $remote->remote = true;

        expect($remote->needsLandingRegion())->toBeFalse();
        expect($remote->missingRoles())->toBe([]);
    });
});

describe('A name to propose', function () {
    test('is an alliteration the caller accepts', function () {
        $name = OpenSim\Installer\Grid\RandomName::make(static fn(string $v): ?string => RegionName::problem($v));

        expect($name)->toMatch('/^(\S+) (\S+)$/');
        expect(RegionName::problem($name))->toBeNull();
        expect(strtolower($name[0]))->toBe(strtolower(explode(' ', $name)[1][0]));
    });

    test('is transliterated', function () {
        expect(OpenSim\Installer\Grid\Slug::ascii('Joyeux Noël'))->toBe('Joyeux Noel');

        $name = OpenSim\Installer\Grid\RandomName::make(static fn(string $v): ?string => null);
        expect($name)->toMatch('/^[\x20-\x7e]+$/');
    });

    test('is empty when no name is accepted', function () {
        expect(OpenSim\Installer\Grid\RandomName::make(static fn(string $v): ?string => 'no'))->toBe('');
    });
});
