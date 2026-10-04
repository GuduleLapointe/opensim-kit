<?php
/**
 * The answers of the grid service of a Robust to the requests about regions.
 */

use OpenSim\Installer\Grid\RobustGrid;

describe('RobustGrid answers', function () {
    test('give the place of each region', function () {
        $xml =
            '<?xml version="1.0"?><ServerResponse>' .
            '<region0 type="List"><uuid>a</uuid><locX>256000</locX><locY>256000</locY><sizeX>256</sizeX><sizeY>256</sizeY><regionName>Sim1</regionName></region0>' .
            '<region1 type="List"><uuid>b</uuid><locX>257024</locX><locY>256000</locY><sizeX>512</sizeX><sizeY>512</sizeY><regionName>Big</regionName></region1>' .
            '</ServerResponse>';

        expect(RobustGrid::regions($xml))->toBe([[1000, 1000, 1, 1], [1004, 1000, 2, 2]]);
    });

    test('say no region when the grid has none or does not answer', function () {
        expect(
            RobustGrid::regions('<?xml version="1.0"?><ServerResponse><result>null</result></ServerResponse>'),
        )->toBe([]);
        expect(RobustGrid::regions(null))->toBe([]);
        expect(RobustGrid::regions('not xml'))->toBe([]);
    });
});

describe('RobustGrid region by name', function () {
    test('tells a region that exists from one that does not', function () {
        expect(
            RobustGrid::named(
                '<?xml version="1.0"?><ServerResponse><result type="List"><uuid>a</uuid><regionName>Sim1</regionName></result></ServerResponse>',
            ),
        )->toBeTrue();
        expect(
            RobustGrid::named('<?xml version="1.0"?><ServerResponse><result>null</result></ServerResponse>'),
        )->toBeFalse();
        expect(RobustGrid::named('not xml'))->toBeFalse();
    });
});
