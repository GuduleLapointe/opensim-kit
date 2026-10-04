<?php
/**
 * The config.php of the helpers: the profile, the Robust config of a grid, its helpers.ini, and the constants they give.
 */

define('OPENSIM_KIT_CONFIG_CLASS_ONLY', true);
require_once dirname(__DIR__, 2) . '/packaging/opensim-helpers.config.php';

/**
 * A setup of the kit in a temporary tree: a profile, one grid.
 *
 * @param string $helpers The content of helpers.ini of the grid, none when empty.
 * @return array{0:string,1:string} The opensim.conf, the grid folder.
 */
function helpers_config_tree(string $helpers = ''): array
{
    $root = sys_get_temp_dir() . '/kit-' . bin2hex(random_bytes(4));
    $grid = "$root/etc/grids/Alpha";
    mkdir($grid, 0o755, true);
    file_put_contents(
        "$root/opensim.conf",
        "[Defaults]\nDefaultProfile = 0.9.3.0\nSystemUser = opensim\n\n[0.9.3.0]\nEtcRoot = $root/etc\n",
    );
    file_put_contents(
        "$grid/Robust.HG.ini",
        <<<'INI'
        ; Robust of the grid
        [Const]
            BaseHostname = "play.example.org"
            BaseURL = "http://${Const|BaseHostname}"
            WebURL = "https://${Const|BaseHostname}"
            PublicPort = 8002
        [DatabaseService]
            ConnectionString = "Data Source=localhost;Database=alpha_robust;User ID=opensim;Password=s3cret;Old Guids=true;"
        [Hypergrid]
            GatekeeperURI = "${Const|BaseURL}:${Const|PublicPort}"
        [GridInfoService]
            gridname = "Alpha World"
        INI
        ,
    );
    if ($helpers !== '') {
        file_put_contents("$grid/helpers.ini", $helpers);
    }

    return ["$root/opensim.conf", $grid];
}

describe('the config of the helpers', function () {
    test('reads an ini the way OpenSimulator writes it, constants expanded', function () {
        [, $grid] = helpers_config_tree();

        $ini = OpenSimKit_HelpersConfig::read_ini("$grid/Robust.HG.ini");

        expect($ini['Const']['BaseURL'])->toBe('http://play.example.org');
        expect($ini['Hypergrid']['GatekeeperURI'])->toBe('http://play.example.org:8002');
        expect($ini['GridInfoService']['gridname'])->toBe('Alpha World');
        expect(OpenSimKit_HelpersConfig::read_ini('/nonexistent.ini'))->toBe([]);
    });

    test('gives the default profile and the grids it has', function () {
        [$conf] = helpers_config_tree();

        $profile = OpenSimKit_HelpersConfig::profile($conf);

        expect($profile['SystemUser'])->toBe('opensim');
        expect(array_keys(OpenSimKit_HelpersConfig::grids($profile)))->toBe(['Alpha']);
        expect(OpenSimKit_HelpersConfig::grid_nick(null, $conf))->toBe('Alpha');
        expect(OpenSimKit_HelpersConfig::grid_nick('Beta', $conf))->toBeNull();
    });

    test('takes the settings of the helpers from the Robust config of the grid', function () {
        [$conf] = helpers_config_tree();

        $settings = OpenSimKit_HelpersConfig::settings(null, $conf);

        expect($settings['grid_name'])->toBe('Alpha World');
        expect($settings['login_uri'])->toBe('http://play.example.org:8002');
        expect($settings['web_url'])->toBe('https://play.example.org');
        expect($settings['hypergrid'])->toBeTrue();
        expect($settings['databases']['robust_db'])->toBe([
            'hostname' => 'localhost',
            'prefix' => 'alpha_robust',
            'user' => 'opensim',
            'password' => 's3cret',
        ]);
        // Without anything in helpers.ini every database is the one of Robust
        expect($settings['databases']['search_db'])->toBe($settings['databases']['robust_db']);
    });

    test('lets helpers.ini give another database, other urls and other options', function () {
        [$conf] = helpers_config_tree(<<<'INI'
        [Helpers]
        path = "/helper"
        mail_sender = "no-reply@example.org"
        currency_provider = "gloebit"
        [search_db]
        hostname = "db2"
        prefix = "ossearch"
        user = "search"
        password = "pw"
        [Urls]
        search = "/search"
        INI);

        $settings = OpenSimKit_HelpersConfig::settings('Alpha', $conf);
        $constants = OpenSimKit_HelpersConfig::constants($settings);

        expect($constants['SEARCH_DB_HOST'])->toBe('db2');
        expect($constants['SEARCH_DB_NAME'])->toBe('ossearch');
        expect($constants['OPENSIM_DB_NAME'])->toBe('alpha_robust');
        expect($constants['OPENSIM_MAIL_SENDER'])->toBe('no-reply@example.org');
        expect($constants['CURRENCY_PROVIDER'])->toBe('gloebit');
        expect(OpenSimKit_HelpersConfig::script_path($settings, 'query.php'))->toBe('/search');
        expect(OpenSimKit_HelpersConfig::script_path($settings, 'guide.php'))->toBe('/helper/guide.php');
        expect($constants['CURRENCY_HELPER_URL'])->toBe('https://play.example.org/helper/currency.php');
    });

    test('gives the message of the day of helpers.ini', function () {
        [$conf] = helpers_config_tree("[Helpers]\nmotd = \"Hello\\nthere\"\n");

        expect(OpenSimKit_HelpersConfig::constants(OpenSimKit_HelpersConfig::settings(null, $conf))['OPENSIM_MOTD'])->toBe('Hello\\nthere');
    });

    test('does not need the Robust config when helpers.ini has what the helpers need', function () {
        [$conf, $grid] = helpers_config_tree(<<<'INI'
        [Helpers]
        grid_name = "Alpha"
        login_uri = "http://play.example.org:8002"
        web_url = "https://play.example.org"
        [robust_db]
        hostname = "localhost"
        prefix = "alpha_robust"
        user = "helpers"
        password = "pw"
        INI);
        chmod("$grid/Robust.HG.ini", 0o000);

        $settings = OpenSimKit_HelpersConfig::settings(null, $conf);
        $unreadable = !is_readable("$grid/Robust.HG.ini");
        chmod("$grid/Robust.HG.ini", 0o644);

        expect($settings['web_url'])->toBe('https://play.example.org');
        expect($settings['databases']['robust_db']['user'])->toBe('helpers');
        expect($settings['login_uri'])->toBe('http://play.example.org:8002');
        // (as root, a file nobody can read is read anyway, and the test proves nothing more)
        expect($unreadable || posix_geteuid() === 0)->toBeTrue();
    });

    test('has the constants the helper scripts expect', function () {
        [$conf] = helpers_config_tree();

        $constants = OpenSimKit_HelpersConfig::constants(OpenSimKit_HelpersConfig::settings(null, $conf));

        expect($constants)->toHaveKeys([
            'OPENSIM_GRID_NAME',
            'OPENSIM_LOGIN_URI',
            'OPENSIM_DB_HOST',
            'SEARCH_DB_NAME',
            'CURRENCY_DB_USER',
            'OFFLINE_DB_PASS',
            'ROBUST_DB_HOST',
            'CURRENCY_HELPER_URL',
        ]);
        expect($constants['OPENSIM_USE_UTC_TIME'])->toBeTrue();
        expect($constants['CURRENCY_HELPER_URL'])->toBe('https://play.example.org/helpers/currency.php');
    });

    test('finds nothing for a grid that does not exist, or when there are several without a choice', function () {
        [$conf, $grid] = helpers_config_tree();
        expect(OpenSimKit_HelpersConfig::settings('Nowhere', $conf))->toBeNull();

        mkdir(dirname($grid) . '/Beta');
        copy("$grid/Robust.HG.ini", dirname($grid) . '/Beta/Robust.ini');
        expect(OpenSimKit_HelpersConfig::grid_nick(null, $conf))->toBeNull();
        expect(OpenSimKit_HelpersConfig::grid_nick('Beta', $conf))->toBe('Beta');
    });

    test('reads a connection string', function () {
        expect(OpenSimKit_HelpersConfig::connection('Data Source=db;Database=x;User ID=u;Password=p;Old Guids=true;'))->toBe([
            'hostname' => 'db',
            'prefix' => 'x',
            'user' => 'u',
            'password' => 'p',
        ]);
    });
});

/**
 * Run the config in a PHP of its own (it defines constants) in a tree like the one the package installs, with a given
 * environment, and tell the constants.
 *
 * @param array<string,string> $environment Variables of the environment (OPENSIM_CONF, OPENSIM_GRID).
 * @param list<string> $names Constants to give back.
 * @return array{0:int,1:array<string,mixed>,2:string} Exit status, the constants, the errors.
 */
function helpers_config_run(array $environment, array $names): array
{
    $root = sys_get_temp_dir() . '/helpers-config-' . bin2hex(random_bytes(4));
    mkdir("$root/includes", 0o755, true);
    mkdir("$root/vendor", 0o755, true);
    copy(dirname(__DIR__, 2) . '/packaging/opensim-helpers.config.php', "$root/includes/config.php");
    foreach (['vendor/autoload.php', 'includes/databases.php', 'includes/functions.php'] as $stub) {
        file_put_contents("$root/$stub", "<?php\n");
    }
    $code = 'require ' . var_export("$root/includes/config.php", true) . '; echo json_encode(array_combine('
        . var_export($names, true) . ', array_map("constant", ' . var_export($names, true) . ')));';
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $environment + ['PATH' => getenv('PATH')],
    );
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);

    return [proc_close($process), json_decode((string) $output, true) ?? [], trim((string) $errors)];
}

describe('config.php of the packaged helpers', function () {
    test('defines the constants of the helpers from the grid', function () {
        [$conf] = helpers_config_tree();
        [$status, $constants] = helpers_config_run(
            ['OPENSIM_CONF' => $conf],
            ['OPENSIM_GRID_NAME', 'OPENSIM_DB_NAME', 'SEARCH_DB_USER', 'CURRENCY_HELPER_URL'],
        );

        expect($status)->toBe(0)->and($constants)->toBe([
            'OPENSIM_GRID_NAME' => 'Alpha World',
            'OPENSIM_DB_NAME' => 'alpha_robust',
            'SEARCH_DB_USER' => 'opensim',
            'CURRENCY_HELPER_URL' => 'https://play.example.org/helpers/currency.php',
        ]);
    });

    test('refuses to run when the grid cannot be found', function () {
        [$conf] = helpers_config_tree();
        [, $constants, $errors] = helpers_config_run(['OPENSIM_CONF' => $conf, 'OPENSIM_GRID' => 'nowhere'], ['OPENSIM_GRID_NAME']);

        expect($constants)->toBe([])->and($errors)->toContain('no grid found in opensim.conf');
    });
});
