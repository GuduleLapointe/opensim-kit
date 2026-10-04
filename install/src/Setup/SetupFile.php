<?php

declare(strict_types=1);

namespace OpenSim\Installer\Setup;

use OpenSim\Installer\Grid\AccountList;
use OpenSim\Installer\Grid\AccountWriter;
use OpenSim\Installer\Grid\GridAccounts;
use OpenSim\Installer\Grid\GridPlan;
use OpenSim\Installer\Grid\SimPlan;
use Symfony\Component\Yaml\Yaml;

/**
 * A setup in a file (JSON or YAML): the grid, its simulators and regions, the owner, and the accounts
 * to make, in the format of the bulk import (`opensim import FILE --users` reads the `users` of this file).
 *
 *   grid:        name, nick, hostname, web_url, hypergrid, helpers, helpers_path, public_port, private_port,
 *                console (rest|screen), console_port, enable, start,
 *                database: host, name, user, password, admin_user, admin_password
 *   owner:       name (First Last), password, or password_hash and password_salt (as Robust keeps them),
 *                email (optional): the account that owns the estate
 *   simulators:  a list of: name, http_port, console, estate: name, owner, database: (as the grid's),
 *                regions: a list of: name, location (x,y), port, roles (DefaultRegion, DefaultHGRegion, FallbackRegion,
 *                or default, default_hg, fallback)
 *   users:       a list of: first, last, email (optional), password (made when empty) or password_hash
 *                and password_salt (written as they are)
 *
 * Only the grid name, and the database user and password, are required to make a grid: the rest has the default
 * of the setup. Without a grid name, the simulators join the grid of the `nick` (or the only one there is), and
 * need the user and password of their database. The file is run by the questions of the setup, answered from it (see Ui\PresetUi), so
 * it does what the wizard does, nothing else.
 *
 * The file is also written by the setup, from what it did (the plans), in `setup.json` of the grid.
 */
final class SetupFile
{
    /** The questions of the grid setup, as written in the source, and where their answer is in the file */
    private const GRID = [
        'Grid name' => 'grid.name',
        'Grid nick' => 'grid.nick',
        'Base hostname' => 'grid.hostname',
        'Web URL' => 'grid.web_url',
        'Enable Hypergrid?' => 'grid.hypergrid',
        'Serve the economy and the search' => 'grid.helpers',
        'Helpers path' => 'grid.helpers_path',
        'Public port' => 'grid.public_port',
        'Private port' => 'grid.private_port',
        'Center of the grid' => 'grid.center',
        'Console of the grid' => 'grid.console',
        'Console port' => 'grid.console_port',
        'Database host' => 'grid.database.host',
        'Database name' => 'grid.database.name',
        'Database user' => 'grid.database.user',
        'Database password' => 'grid.database.password',
        'Administrator user' => 'grid.database.admin_user',
        'Password of' => 'grid.database.admin_password',
        'Enable grid' => 'grid.enable',
        'Start grid' => 'grid.start',
    ];

    /** The same for a simulator ($i: its place in `simulators`) */
    private const SIM = [
        'Simulator name' => 'simulators.$i.name',
        'Simulator HTTP port' => 'simulators.$i.http_port',
        'Console of the simulator' => 'simulators.$i.console',
        'Console port' => 'simulators.$i.console_port',
        'Database host' => 'simulators.$i.database.host',
        'Database name' => 'simulators.$i.database.name',
        'Database user' => 'simulators.$i.database.user',
        'Database password' => 'simulators.$i.database.password',
        'Administrator user' => 'simulators.$i.database.admin_user',
        'Password of' => 'simulators.$i.database.admin_password',
        'Estate name' => 'simulators.$i.estate.name',
        'Estate owner (an account' => 'simulators.$i.estate.owner',
        'Enable simulator' => 'simulators.$i.enable',
        'Start simulator' => 'simulators.$i.start',
    ];

    /** The questions of a region ($i: its simulator, $j: its place in `regions`) */
    private const REGION = [
        'Region name' => 'simulators.$i.regions.$j.name',
        'Region location' => 'simulators.$i.regions.$j.location',
        'Region port' => 'simulators.$i.regions.$j.port',
        'Role of this region' => 'simulators.$i.regions.$j.roles',
    ];

    private const OWNER = [
        'Name of the new account' => 'owner.name',
        'Password of the new account' => 'owner.password',
        'Email of the new account' => 'owner.email',
    ];

    /**
     * Read a setup, JSON or YAML (told from its first character, or by $format).
     *
     * @return array<string,mixed>
     * @throws \InvalidArgumentException  when it cannot be read or something required is missing or wrong
     */
    public static function parse(string $text, ?string $format = null): array
    {
        $text = ltrim($text, "\xEF\xBB\xBF");
        $format ??= in_array(substr(ltrim($text), 0, 1), ['{', '['], true) ? 'json' : 'yaml';
        try {
            $data = $format === 'json' ? json_decode($text, true, 512, JSON_THROW_ON_ERROR) : Yaml::parse($text);
        } catch (\JsonException | \Symfony\Component\Yaml\Exception\ParseException $e) {
            throw new \InvalidArgumentException('The setup file cannot be read: ' . $e->getMessage());
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException(
                'The setup file is a list of settings (grid, owner, simulators, users).',
            );
        }
        $problems = self::problems($data);
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }

        return $data;
    }

    public static function load(string $path): array
    {
        $text = is_readable($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new \InvalidArgumentException("Cannot read $path");
        }

        return self::parse(
            $text,
            preg_match('/\.json$/i', $path) ? 'json' : (preg_match('/\.ya?ml$/i', $path) ? 'yaml' : null),
        );
    }

    /**
     * What is missing or wrong in a setup.
     *
     * @param array<string,mixed> $data
     * @return list<string>
     */
    public static function problems(array $data): array
    {
        $problems = [];
        $grid = $data['grid'] ?? [];
        $creates = is_array($grid) && trim((string) ($grid['name'] ?? '')) !== '';
        if ($creates) {
            // A grid with a name is a grid to make; with only a nick, it is the one the simulators join
            foreach (['user', 'password'] as $key) {
                if (trim((string) ($grid['database'][$key] ?? '')) === '') {
                    $problems[] = "grid.database.$key is required";
                }
            }
        }
        foreach ($data['simulators'] ?? [] as $i => $sim) {
            foreach (['user', 'password'] as $key) {
                if (!$creates && trim((string) ($sim['database'][$key] ?? '')) === '') {
                    $problems[] = "simulators.$i.database.$key is required (the simulators of a grid that is not in the file)";
                }
            }
        }

        $owner = $data['owner'] ?? null;
        if (is_array($owner)) {
            if (!GridAccounts::validName(trim((string) ($owner['name'] ?? '')))) {
                $problems[] = 'owner.name is a first and a last name, e.g. Jane Doe';
            }
            // The password, or its hash and salt as Robust keeps them (what the setup writes in its own file)
            $hashed =
                preg_match('/^[0-9a-f]{32}$/i', (string) ($owner['password_hash'] ?? '')) === 1 &&
                preg_match('/^[0-9a-zA-Z]{1,64}$/', (string) ($owner['password_salt'] ?? '')) === 1;
            if (!$hashed && strlen((string) ($owner['password'] ?? '')) < 6) {
                $problems[] =
                    'owner.password has at least 6 characters (or owner.password_hash and owner.password_salt)';
            }
            $email = trim((string) ($owner['email'] ?? ''));
            if ($email !== '' && !preg_match('/^[^\s"\'\\\\]+@[^\s"\'\\\\]+$/', $email)) {
                $problems[] = 'owner.email is an address, or nothing';
            }
        }

        if (isset($data['users'])) {
            $list = AccountList::parse((string) json_encode(['accounts' => $data['users']]), 'json');
            foreach ($list['errors'] as $error) {
                $problems[] = "users: $error";
            }
        }

        return $problems;
    }

    /**
     * The answers to the questions of the grid setup.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function gridAnswers(array $data): array
    {
        $answers = self::answers(self::GRID, $data, []);
        $answers += ['Enable grid' => true, 'Start grid' => true, 'Apply this configuration' => true];
        // The question is the one of the first language of the setup; a file does not have to say it
        if (isset($answers['Enable grid']) && !$answers['Enable grid']) {
            $answers['Start grid'] = false;
        }

        return $answers;
    }

    /**
     * The answers to the questions of a simulator and of its first region, and of the owner of its estate.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function simAnswers(array $data, int $i): array
    {
        // The owner first: "Password of the new account" is not the password of an administrator ("Password of")
        $answers = self::answers(self::OWNER, $data, []);
        $answers += self::answers(self::SIM, $data, ['$i' => (string) $i]);
        $answers += self::answers(self::REGION, $data, ['$i' => (string) $i, '$j' => '0']);

        // The database of a simulator is the one of the grid, but its name (a simulator has its own)
        foreach (['host', 'user', 'password', 'admin_user', 'admin_password'] as $key) {
            $own = $data['simulators'][$i]['database'][$key] ?? null;
            $grid = $data['grid']['database'][$key] ?? null;
            $target = [
                'host' => 'Database host',
                'user' => 'Database user',
                'password' => 'Database password',
                'admin_user' => 'Administrator user',
                'admin_password' => 'Password of',
            ][$key];
            if ($own === null && $grid !== null) {
                $answers[$target] = (string) $grid;
            }
        }

        // The owner of the estate is one the grid has, else the one of the file, to make
        $owner = (string) ($answers['Estate owner (an account'] ?? ($data['owner']['name'] ?? ''));
        if ($owner !== '') {
            $answers['Estate owner (an account'] = $owner;
            $answers['Estate owner'] = [$owner, '+'];
        }
        $answers += [
            'Create the first region' => true,
            'Apply this configuration' => true,
            'Enable simulator' => true,
            'Start simulator' => true,
            'does not exist yet, create it' => true,
        ];

        return $answers;
    }

    /**
     * The answers to the questions of one more region of a simulator.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function regionAnswers(array $data, int $i, int $j): array
    {
        return self::answers(self::REGION, $data, ['$i' => (string) $i, '$j' => (string) $j]) + [
            'Add region' => true,
            'Load it now' => true,
        ];
    }

    /**
     * Put what the setup of a grid did in the setup file of the grid (created, or completed).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function withGrid(array $data, GridPlan $plan): array
    {
        $grid = [
            'name' => $plan->gridName,
            'nick' => $plan->gridNick,
            'hostname' => $plan->baseHostname,
            'web_url' => $plan->webUrl,
            'hypergrid' => $plan->enableHypergrid,
            'helpers' => $plan->helpers,
            'helpers_path' => $plan->helpersPath,
            'public_port' => $plan->publicPort,
            'private_port' => $plan->privatePort,
            'console' => $plan->consoleMode,
            'database' => [
                'host' => $plan->dbHost,
                'name' => $plan->dbName,
                'user' => $plan->dbUser,
                'password' => $plan->dbPass,
            ],
        ];
        $data['version'] = 1;
        $data['grid'] = $grid;

        return $data;
    }

    /**
     * The same for a simulator and its first region, the owner when it was made.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function withSim(array $data, SimPlan $plan): array
    {
        $sim = [
            'name' => $plan->simName,
            'http_port' => $plan->httpPort,
            'console' => $plan->consoleMode,
            'database' => [
                'host' => $plan->dbHost,
                'name' => $plan->dbName,
                'user' => $plan->dbUser,
                'password' => $plan->dbPass,
            ],
            'estate' => ['name' => $plan->estateName, 'owner' => $plan->estateOwner],
            'regions' => [],
        ];
        if ($plan->regionName !== '') {
            $sim['regions'][] = array_filter(
                [
                    'name' => $plan->regionName,
                    'location' => $plan->regionLocation,
                    'port' => $plan->regionPort,
                    'roles' => $plan->regionRoles ?: null,
                ],
                static fn($v): bool => $v !== null,
            );
        }

        // What the plan does not say (a region added later knows nothing of the database) is not erased
        $sim = self::compact($sim);
        $sims = $data['simulators'] ?? [];
        $found = false;
        foreach ($sims as $k => $existing) {
            if (($existing['name'] ?? '') === $plan->simName) {
                // A region added later is kept with the ones this simulator already had
                $regions = $existing['regions'] ?? [];
                foreach ($sim['regions'] ?? [] as $region) {
                    $regions = array_values(
                        array_filter($regions, static fn(array $r): bool => ($r['name'] ?? '') !== $region['name']),
                    );
                    $regions[] = $region;
                }
                $sims[$k] = ['regions' => $regions] + array_replace_recursive($existing, $sim);
                $found = true;
            }
        }
        if (!$found) {
            $sims[] = $sim;
        }
        $data['simulators'] = array_values($sims);
        if ($plan->createOwner && $plan->estateOwner !== '') {
            // The password is kept hashed, as Robust keeps it: the hash and its salt are what makes the account
            $salt = md5(random_bytes(16));
            $data['owner'] = array_filter(
                [
                    'name' => $plan->estateOwner,
                    'password_hash' => AccountWriter::passwordHash($plan->ownerPassword, $salt),
                    'password_salt' => $salt,
                    'email' => $plan->ownerEmail,
                ],
                static fn(string $v): bool => $v !== '',
            );
        }
        $data['version'] = 1;

        return $data;
    }

    /**
     * Keep what a setup did in the setup file of the grid (`setup.json`, only for its owner: it holds the
     * passwords), to make the same grid again: `opensim import setup.json --apply`.
     *
     * @param \Closure(array<string,mixed>):array<string,mixed> $update  the file as it is, the file it becomes
     */
    public static function record(string $gridDir, \Closure $update): void
    {
        $path = "$gridDir/setup.json";
        $data = [];
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true) ?: [];
        }
        $umask = umask(0o077);
        $written = @file_put_contents($path, self::toJson($update($data)));
        umask($umask);
        if ($written !== false) {
            chmod($path, 0o600);
        }
    }

    /**
     * Without what is empty, at any depth.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function compact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value) && !array_is_list($value)) {
                $values[$key] = self::compact($value);
            }
            if ($values[$key] === '' || $values[$key] === []) {
                unset($values[$key]);
            }
        }

        return $values;
    }

    /** @param array<string,mixed> $data */
    public static function toJson(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /** @param array<string,mixed> $data */
    public static function toYaml(array $data): string
    {
        return Yaml::dump($data, 6, 2);
    }

    /**
     * @param array<string,string> $questions question => path of its answer in the file ($i, $j: places)
     * @param array<string,mixed> $data
     * @param array<string,string> $places
     * @return array<string,mixed>
     */
    private static function answers(array $questions, array $data, array $places): array
    {
        $answers = [];
        foreach ($questions as $question => $path) {
            $value = self::get($data, strtr($path, $places));
            if ($value !== null && $value !== '') {
                $answers[$question] = is_bool($value)
                    ? $value
                    : (is_array($value)
                        ? self::roles($value)
                        : (string) $value);
                if (is_array($value)) {
                    // The roles are a yes or no each in the setup
                    foreach (
                        [
                            'DefaultRegion' => 'Default Region',
                            'DefaultHGRegion' => 'Default HG Region',
                            'FallbackRegion' => 'Fallback Region',
                        ]
                        as $role => $label
                    ) {
                        $answers[$label] = in_array($role, $answers[$question], true);
                    }
                }
            }
        }

        return $answers;
    }

    /**
     * The roles of a region: the names of the flags, or default, default_hg and fallback.
     *
     * @param array<int,mixed> $roles
     * @return list<string>
     */
    private static function roles(array $roles): array
    {
        $names = ['default' => 'DefaultRegion', 'default_hg' => 'DefaultHGRegion', 'fallback' => 'FallbackRegion'];

        return array_values(array_map(static fn($r): string => $names[strtolower((string) $r)] ?? (string) $r, $roles));
    }

    /** @param array<string,mixed> $data */
    private static function get(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }
}
