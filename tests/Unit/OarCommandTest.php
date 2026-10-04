<?php

declare(strict_types=1);

function oarCommand(string $args): array
{
    exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/libexec/oar.php') . " $args 2>&1", $out, $code);

    return [implode("\n", $out), $code];
}

it('makes an archive that checks out', function () {
    $oar = test_tmp() . '/oar-cmd-' . uniqid() . '.oar';
    $src = escapeshellarg(dirname(__DIR__, 2) . '/share/ossl-scripts/fix-parcel-name-src');

    [$out, $code] = oarCommand("pack $src " . escapeshellarg($oar));
    expect($code)->toBe(0)->and($out)->toBe($oar);

    [$out, $code] = oarCommand('check ' . escapeshellarg($oar));
    expect($code)->toBe(0)->and($out)->toBe('ok');

    [$out] = oarCommand('info ' . escapeshellarg($oar));
    expect($out)->toContain('objects          1')->and($out)->toContain('size             256x256');
});

it('says what is wrong with a file that is not an archive', function () {
    $file = tempnam(test_tmp(), 'oar');
    file_put_contents($file, 'text');

    [$out, $code] = oarCommand('check ' . escapeshellarg($file));
    expect($code)->toBe(1)->and($out)->toContain('not an OAR');
});
