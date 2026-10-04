<?php
/**
 * The placeholder site of a grid (share/web/index.php): what it shows of the grid, read from the OpenSim kit.
 */

/**
 * Run the page for a grid of a temporary tree, with the engine of the kit as the one of the helpers.
 *
 * @param string $gridName The name of the grid, as its Robust config has it.
 * @param bool $configured Whether the grid exists (else the page has nothing to show).
 * @return string The page.
 */
function placeholder_page(string $gridName, bool $configured = true): string
{
    $root = dirname(__DIR__, 2);
    $tree = sys_get_temp_dir() . '/placeholder-' . bin2hex(random_bytes(4));
    mkdir("$tree/helpers/vendor", 0o755, true);
    file_put_contents("$tree/helpers/vendor/autoload.php", "<?php require '$root/vendor/autoload.php';");
    mkdir("$tree/etc/grids/alpha", 0o755, true);
    file_put_contents("$tree/opensim.conf", "[Defaults]\nDefaultProfile = p\n[p]\nEtcRoot = $tree/etc\n");
    if ($configured) {
        file_put_contents(
            "$tree/etc/grids/alpha/Robust.HG.ini",
            "[Const]\nBaseURL = \"http://play.example.org\"\nWebURL = \"https://play.example.org\"\nPublicPort = 8002\n" .
                "[Hypergrid]\nGatekeeperURI = \"\${Const|BaseURL}:\${Const|PublicPort}\"\n[GridInfoService]\ngridname = \"$gridName\"\n",
        );
        file_put_contents("$tree/etc/grids/alpha/helpers.ini", "[Helpers]\npath = \"/helper\"\n[Urls]\nsearch = \"/search\"\n");
    }

    $process = proc_open(
        [PHP_BINARY, "$root/share/web/index.php"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        ['OPENSIM_CONF' => "$tree/opensim.conf", 'OPENSIM_HELPERS_ROOT' => "$tree/helpers", 'PATH' => getenv('PATH')],
    );
    $page = (string) stream_get_contents($pipes[1]);
    proc_close($process);

    return $page;
}

describe('The placeholder site', function () {
    test('shows the grid and its services', function () {
        $page = placeholder_page('Alpha World');

        expect($page)->toContain('<title>Alpha World</title>');
        expect($page)->toContain('http://play.example.org:8002');
        expect($page)->toContain('https://play.example.org/helper/currency.php');
        expect($page)->toContain('https://play.example.org/search');
        expect($page)->toContain('play.example.org:8002:Welcome');
    });

    test('escapes what comes from the config', function () {
        $page = placeholder_page('<script>alert(1)</script>');

        expect($page)->not->toContain('<script>alert');
        expect($page)->toContain('&lt;script&gt;');
    });

    test('has nothing to show for a grid that is not set up', function () {
        $page = placeholder_page('x', false);

        expect($page)->toContain('Not configured yet');
        expect($page)->not->toContain('play.example.org');
    });
});
