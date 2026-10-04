<?php

declare(strict_types=1);

namespace OpenSim\Installer\Web;

/**
 * What a web server needs to serve the site of a grid: the document root of the site, where what exists is
 * served as it is and everything else goes to `index.php`, the router of the helpers (see the helpers), PHP run
 * for it, the grid named to the helpers (OPENSIM_GRID).
 *
 * Caddy, nginx and Apache are written, to include in the site of the grid, not to replace its config.
 */
final class Snippets
{
    public const SERVERS = ['caddy', 'nginx', 'apache'];

    /** The helpers, and the document root of the default site, whose index.php hands over to them. */
    public const WEBROOT = '/usr/share/opensim-helpers';
    public const DOCROOT = '/var/www/html';
    public const SOCKET = '/run/php/php-fpm.sock';

    /**
     * The file of a web server for the site of a grid: complete, to include from the config of the server or to use
     * alone. The site is the one of the web URL of the grid.
     *
     * @throws \InvalidArgumentException for a server that is not written
     */
    public static function render(
        string $server,
        string $nick,
        string $docroot = self::DOCROOT,
        string $socket = self::SOCKET,
        string $webUrl = '',
    ): string {
        $site = self::site($webUrl);

        return match ($server) {
            'caddy' => self::caddy($nick, $docroot, $socket, $site),
            'nginx' => self::nginx($nick, $docroot, $socket, $site),
            'apache' => self::apache($nick, $docroot, $site),
            default => throw new \InvalidArgumentException(
                "No file for $server (" . implode(', ', self::SERVERS) . ').',
            ),
        };
    }

    /**
     * Where the site answers, from its web URL: the host (none: any), the port when it is not the usual one of its scheme.
     *
     * @return array{host:string,scheme:string,port:?int}
     */
    private static function site(string $webUrl): array
    {
        $parts = parse_url(trim($webUrl)) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        return [
            'host' => (string) ($parts['host'] ?? ''),
            'scheme' => $scheme === 'http' ? 'http' : 'https',
            'port' => $port === ($scheme === 'http' ? 80 : 443) ? null : $port,
        ];
    }

    private static function header(string $server, string $nick, string $how): string
    {
        return "# Site of the grid $nick, written by `opensim web $nick snippet $server`.\n# $how\n" .
            "# What is not a file of the root goes to its index.php, the router of the helpers.\n";
    }

    private static function caddy(string $nick, string $docroot, string $socket, array $site): string
    {
        $address = match (true) {
            $site['host'] === '' => ':' . ($site['port'] ?? 80),
            $site['scheme'] === 'http' => 'http://' .
                $site['host'] .
                ($site['port'] !== null ? ':' . $site['port'] : ''),
            default => $site['host'] . ($site['port'] !== null ? ':' . $site['port'] : ''),
        };

        return self::header(
            'caddy',
            $nick,
            'A complete site: `import` it from your Caddyfile, or use it as the Caddyfile.',
        ) .
            "\n$address {\n\troot * $docroot\n\tphp_fastcgi unix/$socket {\n\t\ttry_files {path} /index.php\n\t\tenv OPENSIM_GRID $nick\n\t}\n\tfile_server\n}\n";
    }

    private static function nginx(string $nick, string $docroot, string $socket, array $site): string
    {
        $port = $site['scheme'] === 'http' ? $site['port'] ?? 80 : 80;
        $how =
            'A complete server: put it in /etc/nginx/conf.d/ or include it in the http block.' .
            ($site['scheme'] === 'https' ? "\n# Certificate: add it as usual (certbot --nginx)." : '');

        return self::header('nginx', $nick, $how) .
            "\nserver {\n\tlisten $port;\n\tlisten [::]:$port;\n" .
            ($site['host'] !== '' ? "\tserver_name {$site['host']};\n" : '') .
            "\troot $docroot;\n\tindex index.php index.html;\n\n\tlocation / {\n\t\ttry_files \$uri \$uri/ /index.php?\$query_string;\n\t}\n\n" .
            "\tlocation ~ \\.php\$ {\n\t\t# A script that is not a file of the root is the business of the router\n\t\ttry_files \$uri /index.php?\$query_string;\n\t\tinclude fastcgi_params;\n\t\tfastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n\t\tfastcgi_param OPENSIM_GRID $nick;\n\t\tfastcgi_pass unix:$socket;\n\t}\n}\n";
    }

    private static function apache(string $nick, string $docroot, array $site): string
    {
        $port = $site['scheme'] === 'http' ? $site['port'] ?? 80 : 80;
        $how =
            "A complete virtual host: put it in /etc/apache2/sites-available/ (a2ensite) or include it.\n" .
            '# PHP is what handles .php on your Apache, mod_php or PHP-FPM.' .
            ($site['scheme'] === 'https' ? "\n# Certificate: add it as usual (certbot --apache)." : '');

        return self::header('apache', $nick, $how) .
            "\n<VirtualHost *:$port>\n" .
            ($site['host'] !== '' ? "\tServerName {$site['host']}\n" : '') .
            "\tDocumentRoot $docroot\n\t<Directory $docroot>\n\t\tOptions -Indexes\n\t\tRequire all granted\n\t\tDirectoryIndex index.php index.html\n" .
            "\t\tFallbackResource /index.php\n\t\tSetEnv OPENSIM_GRID $nick\n\t</Directory>\n</VirtualHost>\n";
    }
}
