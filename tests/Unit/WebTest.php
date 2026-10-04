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
        expect($services->url('https://play.example.org/', 'offline'))->toBe(
            'https://play.example.org/helpers/offline.php',
        );
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
        $dir = test_tmp() . '/helpers-ini-' . bin2hex(random_bytes(4));
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
            str_replace(
                ["path = \"/helpers\"", ';; search = "/search"'],
                ['path = "/helper"', 'search = "/search"'],
                $first,
            ),
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
    test('send what is not a file to index.php', function () {
        $caddy = Snippets::render('caddy', 'alpha');
        $nginx = Snippets::render('nginx', 'alpha');
        $apache = Snippets::render('apache', 'alpha');

        expect($caddy)
            ->toContain('root * /var/www/html')
            ->and($caddy)
            ->toContain('try_files {path} /index.php')
            ->and($nginx)
            ->toContain('try_files $uri $uri/ /index.php?$query_string')
            ->and($apache)
            ->toContain('FallbackResource /index.php');
    });

    test('hand a script that is not a file to the router, nginx included', function () {
        expect(Snippets::render('nginx', 'alpha'))
            ->toContain('try_files $uri /index.php?$query_string;')
            ->toContain('include fastcgi_params;')
            ->not->toContain('snippets/fastcgi-php.conf');
    });

    test('have the grid in the PHP environment', function () {
        expect(Snippets::render('caddy', 'alpha'))
            ->toContain('env OPENSIM_GRID alpha')
            ->and(Snippets::render('nginx', 'alpha'))
            ->toContain('fastcgi_param OPENSIM_GRID alpha;')
            ->and(Snippets::render('apache', 'alpha'))
            ->toContain('SetEnv OPENSIM_GRID alpha');
    });

    test('are complete, for the site of the web URL', function () {
        $url = 'https://play.example.org';

        expect(Snippets::render('caddy', 'alpha', webUrl: $url))
            ->toContain("play.example.org {\n\troot * /var/www/html")
            ->and(Snippets::render('nginx', 'alpha', webUrl: $url))
            ->toContain("server {\n\tlisten 80;")
            ->toContain('server_name play.example.org;')
            ->and(Snippets::render('apache', 'alpha', webUrl: $url))
            ->toContain("<VirtualHost *:80>\n\tServerName play.example.org")
            ->toContain('</VirtualHost>');
    });

    test('follow the scheme and the port of the web URL', function () {
        expect(Snippets::render('caddy', 'alpha', webUrl: 'http://play.example.org:8080'))
            ->toContain('http://play.example.org:8080 {')
            ->and(Snippets::render('caddy', 'alpha', webUrl: 'https://play.example.org:8443'))
            ->toContain('play.example.org:8443 {')
            ->and(Snippets::render('caddy', 'alpha', webUrl: 'https://play.example.org:443'))
            ->toContain("play.example.org {\n")
            ->and(Snippets::render('nginx', 'alpha', webUrl: 'http://play.example.org:8080'))
            ->toContain('listen 8080;')
            ->and(Snippets::render('apache', 'alpha', webUrl: 'http://play.example.org:8080'))
            ->toContain('<VirtualHost *:8080>');
    });

    test('answer any host when the grid has no web URL', function () {
        expect(Snippets::render('caddy', 'alpha'))
            ->toContain("\n:80 {\n")
            ->and(Snippets::render('nginx', 'alpha'))
            ->not->toContain('server_name')
            ->and(Snippets::render('apache', 'alpha'))
            ->not->toContain('ServerName');
    });

    test('take another root and socket', function () {
        expect(Snippets::render('nginx', 'alpha', '/srv/site', '/run/php/php8.3-fpm.sock'))
            ->toContain('root /srv/site;')
            ->toContain('unix:/run/php/php8.3-fpm.sock');
    });

    test('refuse an unknown server', function () {
        expect(fn() => Snippets::render('lighttpd', 'alpha'))->toThrow(InvalidArgumentException::class);
    });
});

describe('Pages', function () {
    test('have a path of their own, or the default one', function () {
        expect((new Services())->path('home'))
            ->toBe('/')
            ->and((new Services())->path('welcome'))
            ->toBe('/welcome')
            ->and((new Services('/helpers', ['welcome' => 'hello']))->path('welcome'))
            ->toBe('/hello');
    });
});
