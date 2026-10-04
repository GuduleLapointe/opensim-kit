<?php
/**
 * What waits for an instance to restart, and the actions of the menus that leave it there.
 */

use OpenSim\Installer\Actions;
use OpenSim\Installer\PendingRestarts;

/** A profile with its own data folder. */
function pending_profile()
{
    $root = test_tmp() . '/pending-' . bin2hex(random_bytes(4));
    mkdir("$root/data", 0o755, true);
    mkdir("$root/etc/opensim.d", 0o755, true);

    return ['DataRoot' => "$root/data", 'EtcRoot' => "$root/etc"];
}

describe('Pending restarts', function () {
    test('are kept one line each, once, and read back', function () {
        $profile = pending_profile();
        PendingRestarts::add($profile, 'vonda_welcome', 'region Welcome added');
        PendingRestarts::add($profile, 'vonda_welcome', 'region  Welcome added');
        PendingRestarts::add($profile, 'vonda', 'grid config changed');

        expect(file_get_contents(PendingRestarts::path($profile)))->toBe(
            "vonda_welcome region Welcome added\nvonda grid config changed\n",
        );
        expect(PendingRestarts::read($profile))->toBe([
            ['instance' => 'vonda_welcome', 'reason' => 'region Welcome added'],
            ['instance' => 'vonda', 'reason' => 'grid config changed'],
        ]);

        PendingRestarts::clear($profile);
        expect(PendingRestarts::read($profile))->toBe([]);
    });

    test('are nothing for a profile without a data folder', function () {
        PendingRestarts::add([], 'a', 'b');

        expect(PendingRestarts::read([]))->toBe([]);
    });

    test('are left by a disabled region', function () {
        $profile = pending_profile();
        $file = "{$profile['EtcRoot']}/Far.ini";
        file_put_contents($file, "[Far]\n");
        $ui = new RecordingUi([]);

        $done = (new Actions($ui))->perform(
            'region-disable',
            ['file' => $file, 'name' => 'Far', 'instance' => 'vonda_sim1'],
            $profile,
        );

        expect($done)->toBeTrue();
        expect(is_file("$file.disabled"))->toBeTrue();
        expect(PendingRestarts::read($profile))->toBe([
            ['instance' => 'vonda_sim1', 'reason' => 'region Far disabled'],
        ]);
    });

    test('are not left by an action that failed', function () {
        $profile = pending_profile();
        $ui = new RecordingUi([]);
        // A region of that name is enabled already: the disabled one cannot take its place
        $file = "{$profile['EtcRoot']}/Far.ini";
        file_put_contents($file, "[Far]\n");
        file_put_contents("$file.disabled", "[Far]\n");

        $done = (new Actions($ui))->perform(
            'region-enable',
            ['file' => "$file.disabled", 'name' => 'Far', 'instance' => 'vonda_sim1'],
            $profile,
        );

        expect($done)->toBeFalse();
        expect(PendingRestarts::read($profile))->toBe([]);
    });
});
