<?php

declare(strict_types=1);

use OpenSim\Installer\Grid\SimConfig;
use OpenSim\Installer\Grid\SimPlan;

it('enables OSSL for the estate owner', function () {
    $plan = new SimPlan();
    $plan->simName = 'Alpha';
    $plan->gridName = 'Test';
    $config = (new SimConfig())->render($plan);
    $ini = parse_ini_string($config, true, INI_SCANNER_RAW);

    expect($ini['OSSL']['Include-osslDefaultEnable'])->toContain('/config-include/osslDefaultEnable.ini');
});

it('has an initialization script', function () {
    $script = file_get_contents(
        dirname(__DIR__, 2) .
            '/share/ossl-scripts/fix-parcel-name-src/assets/14280bc4-6d8f-4d8f-839c-da2ab87e918b_script.lsl',
    );

    expect($script)->toContain('osSetParcelDetails')->toContain('PARCEL_DETAILS_NAME');
});

it('enables OSSL in a grid', function () {
    $bin = test_tmp() . '/ossl-' . uniqid();
    mkdir("$bin/config-include", 0777, true);
    file_put_contents(
        "$bin/config-include/osslDefaultEnable.ini",
        "[OSSL]\n    Include-osslEnable = \"config-include/osslEnable.ini\"\n",
    );
    file_put_contents(
        "$bin/config-include/osslEnable.ini",
        "[OSSL]\n    AllowOSFunctions = false\n    ; Allow_osSetParcelDetails = true\n",
    );
    $grid = "$bin/grid";
    (new OpenSim\Installer\Grid\GridShared())->prepare($grid, $bin, false);

    $default = file_get_contents("$grid/config-include/osslDefaultEnable.ini");
    $enable = OpenSim\Installer\Ini::load("$grid/config-include/osslEnable.ini");
    expect($default)
        ->toContain("\"$grid/config-include/osslEnable.ini\"")
        ->and($enable->get('OSSL', 'AllowOSFunctions'))
        ->toBe('true')
        ->and($enable->get('OSSL', 'Allow_osSetParcelDetails'))
        ->toBeNull();
});

it('tells a sim the helpers URLs', function () {
    $plan = new SimPlan();
    $plan->simName = 'Alpha';
    $plan->gridName = 'Test';
    $plan->offlineUrl = 'https://play.example.org/helpers/offline.php';
    $plan->registerUrl = 'https://play.example.org/helpers/register.php';
    $ini = parse_ini_string((new SimConfig())->render($plan), true, INI_SCANNER_RAW);

    expect($ini['Messaging']['OfflineMessageURL'])
        ->toBe('https://play.example.org/helpers/offline.php')
        ->and($ini['Messaging']['OfflineMessageModule'])
        ->toBe('OfflineMessageModule')
        ->and($ini['DataSnapshot']['DATA_SRV_MISearch'])
        ->toBe('https://play.example.org/helpers/register.php')
        ->and($ini['DataSnapshot']['index_sims'])
        ->toBe('true')
        ->and($ini['DataSnapshot']['gridname'])
        ->toBe('Test');
});

it('writes nothing of them for a grid without helpers', function () {
    $plan = new SimPlan();
    $plan->simName = 'Alpha';
    $plan->gridName = 'Test';
    $ini = parse_ini_string((new SimConfig())->render($plan), true, INI_SCANNER_RAW);

    expect($ini)->not->toHaveKey('Messaging')->and($ini['DataSnapshot'])->not->toHaveKey('DATA_SRV_MISearch');
});
