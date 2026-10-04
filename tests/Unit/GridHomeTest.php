<?php
/**
 * The home of the owner of the first estate: Robust gives a home to an account from its default
 * region when it is made, which does not exist yet for that account, so the setup writes it.
 */

use OpenSim\Installer\Grid\Database;
use OpenSim\Installer\Grid\GridAccounts;
use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Ui\QuietUi;

describe('The home of an account', function () {
    test('is a row of GridUser', function () {
        $sql = GridAccounts::homeSql('11111111-2222-3333-4444-555555555555', '607c44e9-3d01-45eb-a07e-937dc72dbadb');

        expect($sql)->toStartWith('INSERT INTO GridUser (UserID, HomeRegionID, HomePosition, HomeLookAt) VALUES (');
        expect($sql)->toContain("'11111111-2222-3333-4444-555555555555', '607c44e9-3d01-45eb-a07e-937dc72dbadb'");
        expect($sql)->toContain("'<128,128,0>', '<0,1,0>'");
        expect($sql)->toContain('ON DUPLICATE KEY UPDATE HomeRegionID = VALUES(HomeRegionID)');
    });

    test('is not written for a wrong name', function () {
        $accounts = new GridAccounts(new Database(new QuietUi()), new QuietUi());
        $grid = new GridInfo();

        expect($accounts->setHome($grid, "Bad'; DROP TABLE x", '607c44e9-3d01-45eb-a07e-937dc72dbadb'))->toBeFalse();
        expect($accounts->setHome($grid, 'Jane Doe', "x'; DROP TABLE x"))->toBeFalse();
    });
});
