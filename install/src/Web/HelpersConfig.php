<?php

declare(strict_types=1);

namespace OpenSim\Installer\Web;

use OpenSim\Installer\Ini;
use OpenSim\Installer\TextFile;

/**
 * The web side of a grid, in the `helpers.ini` of its folder: what opensim-helpers needs, read by the
 * engine (`OpenSim_Kit`), so the web server user does not have to read the Robust config.
 *
 * The setup writes the keys it knows (the name of the grid, its login address, its web address, the
 * database); the rest of the file is the operator's and is kept: the path, the URLs of the services,
 * other databases, the options of the currency.
 */
final class HelpersConfig
{
    public const FILE = 'helpers.ini';

    /** Where the helpers are, when the file says nothing. */
    public const DEFAULT_PATH = '/helpers';

    public static function path(string $gridDir): string
    {
        return rtrim($gridDir, '/') . '/' . self::FILE;
    }

    /**
     * What the file has: section => key => value (the same reader as the engine's, see OpenSim_Kit).
     *
     * @return array<string,array<string,string>>
     */
    public static function read(string $gridDir): array
    {
        $ini = [];
        $section = '';
        foreach (is_file(self::path($gridDir)) ? explode("\n", TextFile::read(self::path($gridDir))) : [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
                $section = trim($m[1]);
                $ini[$section] ??= [];
            } elseif ($section !== '' && preg_match('/^([^=]+?)\s*=\s*"?(.*?)"?\s*$/', $line, $m)) {
                $ini[$section][trim($m[1])] = $m[2];
            }
        }

        return $ini;
    }

    /** The services as the grid has them. */
    public static function services(string $gridDir): Services
    {
        $ini = self::read($gridDir);

        return new Services($ini['Helpers']['path'] ?? self::DEFAULT_PATH, $ini['Urls'] ?? []);
    }

    /**
     * The text of a new file, or the file as it is with the keys of the setup set.
     *
     * @param array{gridName:string,loginUri:string,webUrl:string,mailSender:string,dbHost:string,dbName:string,dbUser:string,dbPass:string} $values
     */
    public static function render(string $current, array $values, string $path = self::DEFAULT_PATH): string
    {
        $ini = new Ini($current !== '' ? $current : self::template($path));
        foreach (
            [
                'grid_name' => $values['gridName'],
                'login_uri' => $values['loginUri'],
                'web_url' => $values['webUrl'],
            ]
            as $key => $value
        ) {
            $ini->set('Helpers', $key, '"' . $value . '"');
        }
        if ($values['mailSender'] !== '') {
            $ini->set('Helpers', 'mail_sender', '"' . $values['mailSender'] . '"');
        }
        foreach (
            [
                'hostname' => $values['dbHost'],
                'prefix' => $values['dbName'],
                'user' => $values['dbUser'],
                'password' => $values['dbPass'],
            ]
            as $key => $value
        ) {
            $ini->set('robust_db', $key, '"' . $value . '"');
        }

        return rtrim($ini->toString()) . "\n";
    }

    /** The file of a grid that has none yet: what the setup writes is set after it, the rest is the operator's. */
    private static function template(string $path): string
    {
        return <<<INI
        ;; Web side of the grid, read by opensim-helpers (through the engine).
        ;; opensim setup writes grid_name, login_uri, web_url and [robust_db]; the rest is yours.

        [Helpers]
        ;; Where the helpers are on the web site of the grid. Change it if your users, your viewers or your
        ;; grid already use another one (e.g. /helper). The web server needs the same path (opensim web snippet).
        path = "$path"
        ;; mail_sender = "no-reply@example.org"
        ;; The message of the day shown at login (motd.php, read by Robust when it starts; <USERNAME> is the avatar)
        ;; motd = "Welcome to My Grid, <USERNAME>!"
        ;; events_url = "https://2do.directory/events"
        ;; currency_provider = "gloebit"
        ;; currency_use_moneyserver = false

        [Urls]
        ;; The URL of a service or a page, when it is not <path>/<script>.php: the helpers route it.
        ;; welcome = "/welcome"
        ;; search = "/search"
        ;; guide = "/guide"
        ;; motd = "/motd"
        ;; currency = "/helper/currency.php"
        ;; register = "/helper/register.php"
        ;; offline = "/helper/offline.php"

        [robust_db]
        ;; The databases of the helpers are the one of Robust. Another one, for a service: a section
        ;; [search_db], [currency_db], [offline_db] or [opensim_db] with hostname, prefix (the name of the
        ;; database), user and password.

        INI;
    }
}
