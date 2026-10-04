<?php

/**
 * Files from the OpenSim sources, some shipped with Windows line endings, are
 * brought to Unix ones wherever the kit reads or copies them.
 */

use OpenSim\Installer\Grid\GridPlan;
use OpenSim\Installer\Grid\RobustConfig;
use OpenSim\Installer\TextFile;

/**
 * A folder of its own for a test.
 *
 * @return string The path of a new folder.
 */
function textfile_dir(): string
{
    $dir = sys_get_temp_dir() . '/textfile-' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

describe('TextFile', function () {
    test('brings Windows and old Mac line endings to Unix ones', function () {
        expect(TextFile::unix("a\r\nb\rc\nd"))->toBe("a\nb\nc\nd");
        expect(TextFile::unix("already\nunix\n"))->toBe("already\nunix\n");
    });

    test('copies a file with Unix line endings', function () {
        $dir = textfile_dir();
        file_put_contents("$dir/source.ini", "[Const]\r\nBaseURL = x\r\n");

        expect(TextFile::copy("$dir/source.ini", "$dir/copy.ini"))->toBeTrue();
        expect(file_get_contents("$dir/copy.ini"))->toBe("[Const]\nBaseURL = x\n");
        expect(file_get_contents("$dir/source.ini"))->toContain("\r");
    });

    test('cleans a file in place, and says whether it changed', function () {
        $dir = textfile_dir();
        file_put_contents("$dir/a.ini", "[A]\r\nk = v\r\n");
        file_put_contents("$dir/b.ini", "[B]\nk = v\n");

        expect(TextFile::clean("$dir/a.ini"))->toBeTrue();
        expect(file_get_contents("$dir/a.ini"))->toBe("[A]\nk = v\n");
        expect(TextFile::clean("$dir/b.ini"))->toBeFalse();
        expect(TextFile::clean("$dir/missing.ini"))->toBeFalse();
    });
});

describe('Robust config with CRLF', function () {
    test('has none left', function () {
        $bin = textfile_dir();
        file_put_contents(
            "$bin/Robust.HG.ini.example",
            "[Const]\r\n    BaseURL = \"http://127.0.0.1\"\r\n[GridService]\r\n    ; Region_Welcome_Area = \"DefaultRegion\"\r\n",
        );
        $plan = new GridPlan();
        $plan->baseHostname = 'localhost';
        $plan->binDir = $bin;

        $ini = (new RobustConfig())->generate($plan);

        expect($ini)->not->toContain("\r");
        expect($ini)->toContain('MapTileDirectory = ');
    });
});
