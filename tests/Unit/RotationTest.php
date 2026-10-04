<?php

/**
 * `--keep N`: the N newest files of the series of a new archive or backup, the older ones removed.
 */

use OpenSim\Installer\Archive\Rotation;

/** A folder with these files. */
function rotation_folder(array $names): string
{
    $folder = test_tmp() . '/rotation-' . bin2hex(random_bytes(4));
    mkdir($folder);
    foreach ($names as $name) {
        touch("$folder/$name");
    }

    return $folder;
}

function rotation_left(string $folder): array
{
    return array_values(array_diff(scandir($folder), ['.', '..']));
}

describe('Rotation', function () {
    test('wants a number of 1 or more', function () {
        expect(Rotation::count('3'))->toBe(3);
        expect(fn() => Rotation::count('0'))->toThrow(InvalidArgumentException::class);
        expect(fn() => Rotation::count('many'))->toThrow(InvalidArgumentException::class);
        expect(fn() => Rotation::count(true))->toThrow(InvalidArgumentException::class);
    });

    test('keeps the newest of a series and removes the others', function () {
        $folder = rotation_folder([
            'alpha-20261001-100000.tar.gz',
            'alpha-20261002-100000.tar.gz',
            'alpha-20261003-100000.tar.gz',
        ]);

        $removed = Rotation::keep("$folder/alpha-20261003-100000.tar.gz", 2);

        expect($removed)->toBe(["$folder/alpha-20261001-100000.tar.gz"]);
        expect(rotation_left($folder))->toBe(['alpha-20261002-100000.tar.gz', 'alpha-20261003-100000.tar.gz']);
    });

    test('leaves the other series alone', function () {
        $folder = rotation_folder([
            'alpha-20261001-100000.oar',
            'alpha-20261002-100000.oar',
            'alpha-noassets-20261001-100000.oar',
            'beta-20261001-100000.oar',
            'alpha-20261001-100000-logs.oar',
        ]);

        Rotation::keep("$folder/alpha-20261002-100000.oar", 1);

        expect(rotation_left($folder))->toBe([
            'alpha-20261001-100000-logs.oar',
            'alpha-20261002-100000.oar',
            'alpha-noassets-20261001-100000.oar',
            'beta-20261001-100000.oar',
        ]);
    });

    test('keeps the file it is given, whatever its stamp', function () {
        $folder = rotation_folder(['alpha-20261001-100000.oar', 'alpha-20261005-100000.oar']);

        Rotation::keep("$folder/alpha-20261001-100000.oar", 1);

        expect(rotation_left($folder))->toBe(['alpha-20261001-100000.oar', 'alpha-20261005-100000.oar']);
    });

    test('does nothing for a name without a stamp', function () {
        $folder = rotation_folder(['notes.txt']);

        expect(Rotation::keep("$folder/notes.txt", 1))->toBe([]);
    });
});
