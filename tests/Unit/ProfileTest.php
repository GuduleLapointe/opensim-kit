<?php

declare(strict_types=1);

use OpenSim\Installer\Config;

function profileConf(): string
{
    $dir = test_tmp() . '/profile-' . uniqid();
    mkdir($dir);

    return "$dir/opensim.conf";
}

it('registers an install with its base directories', function () {
    $file = profileConf();
    $config = new Config($file);
    $config->addProfile('osgrid', '/opt/osgrid/bin');

    expect($config->profiles())->toBe(['osgrid'])
        ->and($config->defaultProfile())->toBe('osgrid')
        ->and($config->profile('osgrid'))->toMatchArray([
            'CoreDirectory' => '/opt/osgrid/bin', 'EtcRoot' => '/opt/osgrid/bin', 'DataRoot' => '/opt/osgrid/bin',
        ]);
});

it('keeps the default profile unless asked', function () {
    $config = new Config(profileConf());
    $config->addProfile('one', '/a');
    $config->addProfile('two', '/b', '/b/etc', '/b/data');

    expect($config->defaultProfile())->toBe('one')
        ->and($config->profile('two')['DataRoot'])->toBe('/b/data');

    $config->addProfile('three', '/c', null, null, true);
    expect($config->defaultProfile())->toBe('three');
});

it('removes a profile but not the default one', function () {
    $config = new Config(profileConf());
    $config->addProfile('one', '/a');
    $config->addProfile('two', '/b');

    expect($config->removeProfile('two'))->toBeTrue()
        ->and($config->removeProfile('nope'))->toBeFalse()
        ->and(fn() => $config->removeProfile('one'))->toThrow(RuntimeException::class);
});

it('refuses a bad name', function () {
    expect(fn() => (new Config(profileConf()))->addProfile('Defaults', '/a'))->toThrow(InvalidArgumentException::class);
});

it('runs from the command line', function () {
    $file = profileConf();
    $env = 'OPENSIM_CONF=' . escapeshellarg($file);
    $php = 'php ' . escapeshellarg(dirname(__DIR__, 2) . '/libexec/profile.php');
    $core = test_tmp();
    shell_exec("$env $php add hand --core " . escapeshellarg($core));

    expect(shell_exec("$env $php list"))->toContain('hand (default)');
});
