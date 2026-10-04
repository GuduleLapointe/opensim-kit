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

    /** @throws \InvalidArgumentException for a server that is not written */
    public static function render(
        string $server,
        string $nick,
        string $docroot = self::DOCROOT,
        string $socket = self::SOCKET,
    ): string {
        return match ($server) {
            'caddy' => self::caddy($nick, $docroot, $socket),
            'nginx' => self::nginx($nick, $docroot, $socket),
            'apache' => self::apache($nick, $docroot),
            default => throw new \InvalidArgumentException(
                "No snippet for $server (" . implode(', ', self::SERVERS) . ').',
            ),
        };
    }

    private static function header(string $server, string $nick, string $how): string
    {
        return "# Site of the grid $nick, written by `opensim web $nick snippet $server`.\n# $how\n" .
            "# What is not a file of the root goes to its index.php, the router of the helpers.\n";
    }

    private static function caddy(string $nick, string $docroot, string $socket): string
    {
        return self::header('caddy', $nick, 'Put it in the site block of the web site.') .
            "\nroot * $docroot\nphp_fastcgi unix/$socket {\n\ttry_files {path} /index.php\n\tenv OPENSIM_GRID $nick\n}\nfile_server\n";
    }

    private static function nginx(string $nick, string $docroot, string $socket): string
    {
        return self::header(
            'nginx',
            $nick,
            'Put it in the server block of the web site. Adjust the socket of PHP-FPM to your version.',
        ) .
            "\nroot $docroot;\nindex index.php index.html;\n\nlocation / {\n\ttry_files \$uri \$uri/ /index.php?\$query_string;\n}\n\n" .
            "location ~ \\.php\$ {\n\tinclude snippets/fastcgi-php.conf;\n\tfastcgi_param OPENSIM_GRID $nick;\n\tfastcgi_pass unix:$socket;\n}\n";
    }

    private static function apache(string $nick, string $docroot): string
    {
        return self::header(
            'apache',
            $nick,
            'Put it in the virtual host of the web site (PHP through libapache2-mod-php or PHP-FPM handling .php).',
        ) .
            "\nDocumentRoot $docroot\n<Directory $docroot>\n\tOptions -Indexes\n\tRequire all granted\n\tDirectoryIndex index.php index.html\n" .
            "\tFallbackResource /index.php\n\tSetEnv OPENSIM_GRID $nick\n</Directory>\n";
    }
}
