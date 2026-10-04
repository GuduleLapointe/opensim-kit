<?php
/**
 * config.php of the helpers installed with the OpenSim kit (the opensim-helpers package installs it as
 * includes/config.php).
 *
 * The helpers know nothing of the kit: they read the constants a config.php defines (see
 * config.example.php). This one defines them from what the kit knows of the grid: the profile in
 * opensim.conf, the Robust config of the grid and the settings of its web side in helpers.ini. Read
 * only, nothing is created. Nothing to edit here.
 *
 * Which grid: the one named by OPENSIM_GRID (constant, environment or web server variable, the
 * virtual host of a grid sets it), else the only grid of the profile. A constant defined before this
 * file wins over the settings.
 */

class OpenSimKit_HelpersConfig
{
    const DEFAULT_CONF = '/etc/opensim/opensim.conf';

    /**
     * The services of the helpers and the script that answers each, the way helpers.ini names them
     * ([Urls] search = "/search" serves query.php there).
     */
    const SERVICES = [
        'search' => 'query.php',
        'register' => 'register.php',
        'offline' => 'offline.php',
        'currency' => 'currency.php',
        'guide' => 'guide.php',
        'motd' => 'motd.php',
        'landtool' => 'landtool.php',
        'parser' => 'parser.php',
        'eventsparser' => 'eventsparser.php',
        'textgen' => 'textgen.php',
        'directory_info' => 'directory_info.php',
    ];

    /**
     * The opensim.conf to read: OPENSIM_CONF (constant or environment), else the one of the system.
     *
     * @return string|null
     */
    public static function conf_path()
    {
        $path = defined('OPENSIM_CONF') ? OPENSIM_CONF : getenv('OPENSIM_CONF');
        if (empty($path)) {
            $path = $_SERVER['OPENSIM_CONF'] ?? self::DEFAULT_CONF;
        }

        return is_file($path) ? $path : null;
    }

    /**
     * Read an ini file the way OpenSimulator writes them: sections, `key = value`, quotes around a
     * value optional, `;` and `#` comments. `${Const|Name}` is replaced by the value of [Const].
     *
     * @param  string $path
     * @return array<string,array<string,string>> section => key => value, empty when unreadable
     */
    public static function read_ini($path)
    {
        $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) {
            return [];
        }

        $ini = [];
        $section = '';
        foreach ($lines as $line) {
            $line = trim(str_replace("\r", '', $line));
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
                $section = trim($m[1]);
                $ini[$section] ??= [];
                continue;
            }
            if (!preg_match('/^([^=]+?)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }
            $value = trim($m[2]);
            if (preg_match('/^"(.*)"\s*(?:[;#].*)?$/', $value, $quoted)) {
                $value = $quoted[1];
            }
            $ini[$section][trim($m[1])] = $value;
        }

        return self::expand($ini);
    }

    /**
     * Replace `${Const|Name}` by the value of Name in [Const], itself expanded (a few levels).
     *
     * @param  array<string,array<string,string>> $ini
     * @return array<string,array<string,string>>
     */
    private static function expand($ini)
    {
        $const = $ini['Const'] ?? [];
        $replace = function ($value) use (&$const) {
            for ($i = 0; $i < 5 && strpos($value, '${Const|') !== false; $i++) {
                $value = preg_replace_callback(
                    '/\$\{Const\|([^}]+)\}/',
                    fn($m) => $const[$m[1]] ?? $m[0],
                    $value,
                );
            }

            return $value;
        };
        foreach ($const as $key => $value) {
            $const[$key] = $replace($value);
        }
        foreach ($ini as $section => $values) {
            foreach ($values as $key => $value) {
                $ini[$section][$key] = $replace($value);
            }
        }

        return $ini;
    }

    /**
     * The profile of opensim.conf: [Defaults] completed by the section of the default profile.
     *
     * @param  string|null $conf
     * @return array<string,string> empty when there is no opensim.conf
     */
    public static function profile($conf = null)
    {
        $conf ??= self::conf_path();
        $ini = $conf === null ? [] : self::read_ini($conf);
        $defaults = $ini['Defaults'] ?? [];
        $profile = $defaults;
        if (!empty($defaults['DefaultProfile']) && isset($ini[$defaults['DefaultProfile']])) {
            $profile = array_merge($defaults, $ini[$defaults['DefaultProfile']]);
        }

        return $profile;
    }

    /**
     * The grids of the profile: those with a Robust config in EtcRoot/grids/<nick>/.
     *
     * @param  array<string,string> $profile
     * @return array<string,string> nick => path of its Robust config
     */
    public static function grids($profile)
    {
        $grids = [];
        foreach (glob(rtrim($profile['EtcRoot'] ?? '', '/') . '/grids/*', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (['Robust.HG.ini', 'Robust.ini'] as $file) {
                if (is_file("$dir/$file")) {
                    $grids[basename($dir)] = "$dir/$file";
                    break;
                }
            }
        }

        return $grids;
    }

    /**
     * The grid asked, or the one the environment names, or the only one.
     *
     * @param  string|null $nick
     * @param  string|null $conf
     * @return string|null its nick, null when it cannot be told
     */
    public static function grid_nick($nick = null, $conf = null)
    {
        $grids = self::grids(self::profile($conf));
        $nick = $nick ?: (defined('OPENSIM_GRID') ? OPENSIM_GRID : (getenv('OPENSIM_GRID') ?: ($_SERVER['OPENSIM_GRID'] ?? '')));
        if ($nick !== '') {
            return isset($grids[$nick]) ? $nick : null;
        }

        return count($grids) === 1 ? (string) array_key_first($grids) : null;
    }

    /**
     * What the helpers of a grid need, from its Robust config and its helpers.ini.
     *
     * helpers.ini can carry everything the helpers need (grid_name, login_uri, web_url, [robust_db]), so the
     * web server user does not have to read the Robust config, which holds more than the helpers need.
     *
     * The databases are the one of Robust unless helpers.ini gives another ([search_db], [currency_db],
     * [offline_db], [opensim_db] with hostname, prefix (the database), user, password).
     *
     * @param  string|null $nick
     * @param  string|null $conf
     * @return array|null null when the grid is not found
     */
    public static function settings($nick = null, $conf = null)
    {
        $profile = self::profile($conf);
        $nick = self::grid_nick($nick, $conf);
        $grids = self::grids($profile);
        if ($nick === null || !isset($grids[$nick])) {
            return null;
        }

        $robust = self::read_ini($grids[$nick]);
        $helpers = self::read_ini(dirname($grids[$nick]) . '/helpers.ini');
        $options = $helpers['Helpers'] ?? [];
        $weburl = rtrim($options['web_url'] ?? ($robust['Const']['WebURL'] ?? ''), '/');

        $database = self::connection($robust['DatabaseService']['ConnectionString'] ?? '');
        foreach (['hostname', 'prefix', 'user', 'password'] as $key) {
            $database[$key] = $helpers['robust_db'][$key] ?? $database[$key];
        }
        $databases = ['robust_db' => $database];
        foreach (['opensim_db', 'search_db', 'currency_db', 'offline_db'] as $name) {
            $given = $helpers[$name] ?? [];
            $databases[$name] = [
                'hostname' => $given['hostname'] ?? $database['hostname'],
                'prefix' => $given['prefix'] ?? $database['prefix'],
                'user' => $given['user'] ?? $database['user'],
                'password' => $given['password'] ?? $database['password'],
            ];
        }

        $base = '/' . trim($options['path'] ?? 'helpers', '/');

        return [
            'nick' => $nick,
            'dir' => dirname($grids[$nick]),
            'profile' => $profile,
            'hypergrid' => str_contains(basename($grids[$nick]), '.HG.'),
            'grid_name' => $options['grid_name'] ?? ($robust['GridInfoService']['gridname'] ?? ucfirst($nick)),
            'login_uri' => $options['login_uri'] ?? self::login_uri($robust),
            'web_url' => $weburl,
            'mail_sender' => $options['mail_sender'] ?? null,
            'options' => $options,
            'databases' => $databases,
            'base_path' => $base,
            'urls' => $helpers['Urls'] ?? [],
        ];
    }

    /**
     * The public path of a service of the helpers: the one helpers.ini gives in [Urls], else the
     * script under the base path (`/helpers/query.php`).
     *
     * @param  array  $settings what settings() gave
     * @param  string $service  the name of the service (search) or its script (query.php)
     * @return string
     */
    public static function script_path($settings, $service)
    {
        $script = self::SERVICES[$service] ?? $service;
        $name = array_search($script, self::SERVICES, true);

        return ($name !== false ? $settings['urls'][$name] ?? null : null) ?? $settings['base_path'] . '/' . $script;
    }

    /**
     * The constants the helper scripts expect, as the config.php of the helpers defines them.
     *
     * @param  array $settings what settings() gave
     * @return array<string,mixed> name => value
     */
    public static function constants($settings)
    {
        $o = $settings['options'];
        $db = $settings['databases'];
        $constants = [
            'OPENSIM_USE_UTC_TIME' => self::flag($o['use_utc_time'] ?? true),
            'OPENSIM_GRID_NAME' => $settings['grid_name'],
            'OPENSIM_LOGIN_URI' => $settings['login_uri'],
            'OPENSIM_MAIL_SENDER' => $settings['mail_sender'],
            'HYPEVENTS_URL' => rtrim($o['events_url'] ?? 'https://2do.directory/events', '/'),
            'SEARCH_TABLE_EVENTS' => 'events',
            'SEARCH_REGISTRARS' => [],
            'ROBUST_DB' => true,
            'OPENSIM_DB' => true,
            'CURRENCY_MONEY_TBL' => 'balances',
            'CURRENCY_TRANSACTION_TBL' => 'transactions',
            'CURRENCY_USE_MONEYSERVER' => self::flag($o['currency_use_moneyserver'] ?? false),
            'CURRENCY_SCRIPT_KEY' => $o['currency_script_key'] ?? null,
            'CURRENCY_RATE' => $o['currency_rate'] ?? null,
            'CURRENCY_RATE_PER' => $o['currency_rate_per'] ?? null,
            'CURRENCY_PROVIDER' => $o['currency_provider'] ?? null,
            'CURRENCY_HELPER_URL' =>
                $o['currency_helper_url'] ??
                ($settings['web_url'] === ''
                    ? null
                    : $settings['web_url'] . self::script_path($settings, 'currency.php')),
            'OFFLINE_MESSAGE_TBL' => 'im_offline',
            'OPENSIM_MOTD' => $o['motd'] ?? null,
        ];
        if (!empty($o['grid_logo_url'])) {
            $constants['OPENSIM_GRID_LOGO_URL'] = $o['grid_logo_url'];
        }
        foreach (
            [
                'ROBUST' => 'robust_db',
                'OPENSIM' => 'opensim_db',
                'SEARCH' => 'search_db',
                'CURRENCY' => 'currency_db',
                'OFFLINE' => 'offline_db',
            ]
            as $prefix => $name
        ) {
            $constants["{$prefix}_DB_HOST"] = $db[$name]['hostname'];
            $constants["{$prefix}_DB_NAME"] = $db[$name]['prefix'];
            $constants["{$prefix}_DB_USER"] = $db[$name]['user'];
            $constants["{$prefix}_DB_PASS"] = $db[$name]['password'];
        }

        return $constants;
    }

    /**
     * Define the constants of constants(), those that are not defined already (a constant the
     * application defined before wins).
     *
     * @param  array $settings
     * @return void
     */
    public static function define_constants($settings)
    {
        foreach (self::constants($settings) as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
    }

    /**
     * The settings of a database from a connection string of OpenSimulator
     * ("Data Source=host;Database=name;User ID=user;Password=pass;Old Guids=true;").
     *
     * @param  string $string
     * @return array{hostname:?string,prefix:?string,user:?string,password:?string}
     */
    public static function connection($string)
    {
        $values = [];
        foreach (explode(';', $string) as $pair) {
            if (strpos($pair, '=') !== false) {
                [$key, $value] = explode('=', $pair, 2);
                $values[strtolower(trim($key))] = trim($value);
            }
        }

        return [
            'hostname' => $values['data source'] ?? ($values['server'] ?? null),
            'prefix' => $values['database'] ?? null,
            'user' => $values['user id'] ?? ($values['uid'] ?? null),
            'password' => $values['password'] ?? ($values['pwd'] ?? null),
        ];
    }

    /** The address viewers log in to: the gatekeeper of a Hypergrid grid, else its public address. */
    private static function login_uri($robust)
    {
        $uri = $robust['Hypergrid']['GatekeeperURI'] ?? '';
        if ($uri === '' && isset($robust['Const']['BaseURL'], $robust['Const']['PublicPort'])) {
            $uri = $robust['Const']['BaseURL'] . ':' . $robust['Const']['PublicPort'];
        }

        return $uri === '' ? null : $uri;
    }

    private static function flag($value)
    {
        return !in_array(strtolower((string) $value), ['', '0', 'false', 'no', 'off'], true);
    }
}


if (!defined('OPENSIM_KIT_CONFIG_CLASS_ONLY')) {
    if (!defined('OPENSIM_ENGINE')) {
        define('OPENSIM_ENGINE', true);
    }
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $kit_settings = OpenSimKit_HelpersConfig::settings();
    if ($kit_settings === null) {
        error_log('opensim-helpers: no grid found in opensim.conf, give its nick in OPENSIM_GRID');
        http_response_code(503);
        die('Not properly configured');
    }
    // helpers.ini holds the password of the database: without it the helpers would try the placeholder of the Robust config
    $kit_ini = $kit_settings['dir'] . '/helpers.ini';
    if (is_file($kit_ini) && !is_readable($kit_ini)) {
        error_log("opensim-helpers: $kit_ini is not readable by the web server user (it belongs to the group of the web server, mode 640: chgrp www-data)");
        http_response_code(503);
        die('Not properly configured');
    }
    unset($kit_ini);
    OpenSimKit_HelpersConfig::define_constants($kit_settings);
    unset($kit_settings);

    if (OPENSIM_USE_UTC_TIME) {
        date_default_timezone_set('UTC');
    }

    require_once __DIR__ . '/databases.php';
    require_once __DIR__ . '/functions.php';

    $currency_addon = dirname(__DIR__) . '/addons/' . CURRENCY_PROVIDER . '.php';
    if (is_file($currency_addon)) {
        require_once $currency_addon;
    }
}
