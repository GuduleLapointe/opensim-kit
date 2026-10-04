<?php
/**
 * The web side of a grid: where its services are, its helpers.ini, what the web server needs.
 */

use OpenSim\Installer\Web\HelpersConfig;
use OpenSim\Installer\Web\Services;
use OpenSim\Installer\Web\Snippets;

describe('Services', function () {
    test('are under /helpers by default', function () {
        $services = new Services();

        expect($services->path('search'))->toBe('/helpers/query.php');
        expect($services->path('currency'))->toBe('/helpers/currency.php');
        expect($services->url('https://play.example.org/', 'offline'))->toBe('https://play.example.org/helpers/offline.php');
        expect($services->aliases())->toBe([]);
    });

    test('follow the path chosen', function () {
        $services = new Services('helper/', ['search' => '/search', 'guide' => 'guide']);

        expect($services->path('currency'))->toBe('/helper/currency.php');
        expect($services->path('query.php'))->toBe('/search');
        expect($services->path('guide'))->toBe('/guide');
        expect($services->aliases())->toBe(['search' => '/search', 'guide' => '/guide']);
    });

    test('keep the default path out of aliases', function () {
        expect((new Services('/helper', ['currency' => '/helper/currency.php']))->aliases())->toBe([]);
    });
});

describe('helpers.ini', function () {
    test('is written with what the setup knows', function () {
        $text = HelpersConfig::render('', [
            'gridName' => 'Alpha World',
            'loginUri' => 'http://play.example.org:8002',
            'webUrl' => 'https://play.example.org',
            'mailSender' => '',
            'dbHost' => 'localhost',
            'dbName' => 'alpha_robust',
            'dbUser' => 'opensim',
            'dbPass' => 'pw',
        ]);

        expect($text)->toContain('path = "/helpers"');
        expect($text)->toContain('grid_name = "Alpha World"');
        expect($text)->toContain('web_url = "https://play.example.org"');
        expect($text)->toContain('prefix = "alpha_robust"');
        expect($text)->toContain(';; search = "/search"');
        expect($text)->not->toMatch('/^mail_sender/m');
    });

    test('keeps what the operator changed when the setup runs again', function () {
        $dir = sys_get_temp_dir() . '/helpers-ini-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $first = HelpersConfig::render('', [
            'gridName' => 'Alpha',
            'loginUri' => 'http://a:8002',
            'webUrl' => 'https://a',
            'mailSender' => '',
            'dbHost' => 'localhost',
            'dbName' => 'alpha',
            'dbUser' => 'u',
            'dbPass' => 'p',
        ]);
        file_put_contents(
            HelpersConfig::path($dir),
            str_replace(["path = \"/helpers\"", ';; search = "/search"'], ['path = "/helper"', 'search = "/search"'], $first),
        );

        $again = HelpersConfig::render((string) file_get_contents(HelpersConfig::path($dir)), [
            'gridName' => 'Alpha Renamed',
            'loginUri' => 'http://a:8002',
            'webUrl' => 'https://a',
            'mailSender' => '',
            'dbHost' => 'localhost',
            'dbName' => 'alpha',
            'dbUser' => 'u',
            'dbPass' => 'new',
        ]);
        file_put_contents(HelpersConfig::path($dir), $again);
        $services = HelpersConfig::services($dir);

        expect($again)->toContain('grid_name = "Alpha Renamed"');
        expect($again)->toContain('password = "new"');
        expect($services->base())->toBe('/helper');
        expect($services->path('search'))->toBe('/search');
        expect(HelpersConfig::read($dir)['robust_db']['password'])->toBe('new');
    });
});

describe('Snippets for the web server', function () {
    test('serve the helpers, deny the rest', function () {
        foreach (Snippets::SERVERS as $server) {
            $text = Snippets::render($server, 'alpha', new Services('/helpers'));

            expect($text)->toContain('/usr/share/opensim-helpers');
            expect($text)->toContain('/helpers');
            expect($text)->toContain('alpha');
            expect($text)->toContain('includes');
            expect($text)->toContain('vendor');
        }
    });

    test('have the grid in the PHP environment', function () {
        $services = new Services();

        expect(Snippets::render('caddy', 'alpha', $services))->toContain('env OPENSIM_GRID alpha');
        expect(Snippets::render('nginx', 'alpha', $services))->toContain('fastcgi_param OPENSIM_GRID alpha;');
        expect(Snippets::render('apache', 'alpha', $services))->toContain('SetEnv OPENSIM_GRID alpha');
    });

    test('alias a service that has its own path to its script', function () {
        $services = new Services('/helper', ['search' => '/search', 'guide' => '/guide']);

        $caddy = Snippets::render('caddy', 'alpha', $services);
        expect($caddy)->toContain("handle /search {\n\troot * /usr/share/opensim-helpers\n\trewrite * /query.php");
        expect($caddy)->toContain('rewrite * /guide.php');
        expect(Snippets::render('nginx', 'alpha', $services))->toContain(
            "location = /search {\n\tinclude snippets/fastcgi-php.conf;\n\tfastcgi_param SCRIPT_FILENAME /usr/share/opensim-helpers/query.php;",
        );
        expect(Snippets::render('apache', 'alpha', $services))->toContain('AliasMatch ^/search$ /usr/share/opensim-helpers/query.php');
        expect(Snippets::render('apache', 'alpha', $services))->toContain("Alias /helper /usr/share/opensim-helpers\n");
    });

    test('refuse a server that is not written', function () {
        expect(fn() => Snippets::render('lighttpd', 'alpha', new Services()))->toThrow(InvalidArgumentException::class);
    });
});
