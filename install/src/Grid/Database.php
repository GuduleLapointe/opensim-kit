<?php

declare(strict_types=1);

namespace OpenSim\Installer\Grid;

use OpenSim\Installer\System;
use OpenSim\Installer\Ui\InstallerUi;

/**
 * The database of a grid: OpenSim cannot run without it, so the setup checks
 * it can be used with the given credentials, and only goes on when it can.
 *
 * In this order, and only as far as needed:
 *  1. the account: does it log in with these credentials? If it does, nothing
 *     is created for it.
 *  2. the database: can this account use it?
 *  3. what is missing (the account, the database, the rights on it) is
 *     created one statement at a time after confirmation, with the
 *     administrator access to the server when there is one.
 *
 * A problem of access (wrong password, unreachable server, no administrator
 * access to check or fix) can be tried again. A creation that fails ends the
 * setup, with the commands to run from an administrator account.
 *
 * The administrator access is needed only for that. It is found silently when
 * the running user already has it (root, their own database account, e.g. in
 * their ~/.my.cnf, or sudo without a password); otherwise its credentials are
 * asked, once per session. They work for any server, on this machine or
 * another one. Someone with only a database they may use never needs it.
 *
 * The grid's account is always tried with its own credentials, and nothing
 * else, as OpenSim will: no option file of the running user is read for it.
 *
 * MySQL and MariaDB only: Robust, and many modules, do not support others.
 */
final class Database
{
    /** The database is usable. */
    public const OK = 0;
    /** An access problem: the settings can be corrected and tried again. */
    public const RETRY = 1;
    /** A creation failed: nothing more to try from here. */
    public const ABORT = 2;

    /** @var array<string,string> passwords entered or generated during this session, by account */
    private static array $passwords = [];

    /** @var array<string,array{0:string,1:string}> administrator accounts entered during this session, [user, password] by server */
    private static array $admins = [];

    /** @var array{command:list<string>,env:array<string,string>}|false|null how the administrator's statements run: null until searched, false when there is no way */
    private array|false|null $adminAccess = null;

    /** @var string why there is no administrator access */
    private string $adminReason = '';

    public function __construct(private InstallerUi $ui) {}

    /** Keep the password of an account for the rest of the session. */
    public static function remember(string $host, string $user, string $password): void
    {
        self::$passwords["$user@$host"] = $password;
    }

    /** The password entered or generated earlier in this session for this account, if any. */
    public static function recall(string $host, string $user): ?string
    {
        return self::$passwords["$user@$host"] ?? null;
    }

    /** @return int self::OK, self::RETRY or self::ABORT (with the reason and the way out shown) */
    public function ensure(GridPlan $plan): int
    {
        $this->adminAccess = null;
        $client = $this->client();
        if ($client === null) {
            $this->ui->error(_('The MySQL client is missing: install default-mysql-client (or mariadb-client).'));

            return self::ABORT;
        }
        if (!preg_match('/^[A-Za-z0-9_$]+$/', $plan->dbName)) {
            $this->ui->error(sprintf(_("Invalid database name '%s': letters, digits, _ and $ only."), $plan->dbName));

            return self::RETRY;
        }

        // 1. The account, before any database: valid credentials need no creation.
        [$code, , $err] = $this->run($client, $plan, null);
        if ($code !== 0) {
            // Without a database, any "Access denied for user" is a refused
            // login: 1045 for a wrong password, 1698 for an account that does
            // not exist (MariaDB), or that has another authentication
            if (
                !in_array($this->errno($err), [1045, 1698], true) &&
                stripos($err, 'Access denied for user') === false
            ) {
                $this->ui->error(sprintf(_('Cannot reach the database server %s: %s'), $plan->dbHost, trim($err)));
                $this->ui->error(
                    _('OpenSim needs MySQL or MariaDB, on this machine (install default-mysql-server) or another one.'),
                );

                return self::RETRY;
            }
            $result = $this->account($client, $plan);
            if ($result !== self::OK) {
                return $result;
            }
        }

        // 2. The database, with this account.
        [$code, , $err] = $this->run($client, $plan, $plan->dbName);
        if ($code === 0) {
            $this->ui->note(sprintf(_('Database %s on %s: connection OK.'), $plan->dbName, $plan->dbHost));

            return self::OK;
        }
        // 1049 unknown database; 1044 what a server answers, for an account it
        // knows, about a database the account has no rights on, existing or not
        if (!in_array($this->errno($err), [1044, 1049], true)) {
            $this->ui->error(sprintf(_('Cannot use database %s: %s'), $plan->dbName, trim($err)));

            return self::RETRY;
        }

        return $this->database($client, $plan);
    }

    /**
     * The lines a query gives on the database of the settings, with its own
     * account.
     *
     * @return list<string>|null null when the query cannot be run
     */
    public function select(GridPlan $plan, string $sql): ?array
    {
        $client = $this->client();
        if ($client === null) {
            return null;
        }
        [$code, $out] = $this->run($client, $plan, $plan->dbName, $sql);

        return $code === 0
            ? array_values(array_filter(explode("\n", $out), static fn(string $line): bool => $line !== ''))
            : null;
    }

    /** Login refused: wrong password, or no such account for this host. */
    private function account(string $client, GridPlan $plan): int
    {
        $host = $this->accountHost($plan);
        $hosts = $this->accountHosts($client, $plan);

        if ($hosts === null) {
            $this->ui->error(
                sprintf(
                    _(
                        "Login refused for user %s: the password is wrong, or the account does not exist for '%s'. The administrator access to the server, to check, is not available (%s).%s",
                    ),
                    $plan->dbUser,
                    $host,
                    $this->adminReason,
                    $this->adminHint(),
                ),
            );
            $this->showCommands($plan, $host, true, true);

            return self::RETRY;
        }
        if (in_array($host, $hosts, true)) {
            $this->ui->error(
                sprintf(
                    _("User '%s'@'%s' exists but the password is rejected: give the right password."),
                    $plan->dbUser,
                    $host,
                ),
            );

            return self::RETRY;
        }

        $this->ui->warn(
            $hosts === []
                ? sprintf(_('User %s does not exist on %s.'), $plan->dbUser, $plan->dbHost)
                : sprintf(
                    _("User %s exists for %s, not for '%s' (the host it connects from)."),
                    $plan->dbUser,
                    implode(', ', $hosts),
                    $host,
                ),
        );
        if (
            !$this->ui->confirm(sprintf(_("Create user '%s'@'%s' on %s?"), $plan->dbUser, $host, $plan->dbHost), true)
        ) {
            return self::RETRY;
        }

        $account = $this->quote($plan->dbUser) . '@' . $this->quote($host);
        [$code, , $err] = $this->admin(
            $client,
            $plan,
            "CREATE USER $account IDENTIFIED BY " . $this->quote($plan->dbPass),
        );
        if ($code !== 0) {
            return $this->failed('create the user', $err, $plan, $host, true, true);
        }
        [$code, , $err] = $this->run($client, $plan, null);
        if ($code !== 0) {
            $this->ui->error(sprintf(_('The user was created but still cannot log in: %s'), trim($err)));

            return self::ABORT;
        }

        return self::OK;
    }

    /** The account works, the database does not: missing, or without rights. */
    private function database(string $client, GridPlan $plan): int
    {
        $host = $this->accountHost($plan);
        $this->ui->warn(
            sprintf(
                _(
                    'User %s can connect to %s, but has no access to database %s: it does not exist, or the user has no rights on it.',
                ),
                $plan->dbUser,
                $plan->dbHost,
                $plan->dbName,
            ),
        );

        $exists = $this->databaseExists($client, $plan);
        if ($exists === null) {
            $this->ui->error(
                sprintf(
                    _('The administrator access to the server, to check or create it, is not available (%s).%s'),
                    $this->adminReason,
                    $this->adminHint(),
                ),
            );
            $this->showCommands($plan, $host, false, true);

            return self::RETRY;
        }

        $question = $exists
            ? "Give user {$plan->dbUser} access to database {$plan->dbName} on {$plan->dbHost}?"
            : "Create database {$plan->dbName} on {$plan->dbHost} and give user {$plan->dbUser} access to it?";
        if (!$this->ui->confirm($question, true)) {
            return self::RETRY;
        }

        if (!$exists) {
            [$code, , $err] = $this->admin($client, $plan, "CREATE DATABASE `{$plan->dbName}` CHARACTER SET utf8");
            if ($code !== 0) {
                return $this->failed('create the database', $err, $plan, $host, false, true);
            }
        }
        $account = $this->quote($plan->dbUser) . '@' . $this->quote($host);
        [$code, , $err] = $this->admin($client, $plan, "GRANT ALL ON `{$plan->dbName}`.* TO $account");
        if ($code !== 0) {
            return $this->failed('give the user access to the database', $err, $plan, $host, false, !$exists);
        }

        [$code, , $err] = $this->run($client, $plan, $plan->dbName);
        if ($code !== 0) {
            $this->ui->error(sprintf(_('Still cannot use database %s: %s'), $plan->dbName, trim($err)));

            return self::ABORT;
        }
        $this->ui->note(sprintf(_('Database %s ready on %s.'), $plan->dbName, $plan->dbHost));

        return self::OK;
    }

    /** A creation failed: the setup ends here, with what to run from an administrator account. */
    private function failed(
        string $what,
        string $err,
        GridPlan $plan,
        string $host,
        bool $withUser,
        bool $withDatabase,
    ): int {
        $this->ui->error(sprintf(_('Could not %s: %s'), $what, $this->errorLine($err)));
        $this->showCommands($plan, $host, $withUser, $withDatabase);

        return self::ABORT;
    }

    /** The commands the administrator can run by hand, then the setup started again. */
    private function showCommands(GridPlan $plan, string $host, bool $withUser, bool $withDatabase): void
    {
        $account = $this->quote($plan->dbUser) . '@' . $this->quote($host);
        $lines = [];
        if ($withUser) {
            $lines[] = "CREATE USER $account IDENTIFIED BY '<password>';";
        }
        if ($withDatabase) {
            $lines[] = "CREATE DATABASE `{$plan->dbName}` CHARACTER SET utf8;";
        }
        $lines[] = "GRANT ALL ON `{$plan->dbName}`.* TO $account;";
        $this->ui->note(
            _('From an account with administrator rights on the database server, run what is missing:') .
                "\n  " .
                implode("\n  ", $lines),
        );
    }

    /**
     * The hosts the account exists for, or null when it cannot be told (no
     * administrator access).
     *
     * @return list<string>|null
     */
    private function accountHosts(string $client, GridPlan $plan): ?array
    {
        [$code, $out] = $this->admin(
            $client,
            $plan,
            'SELECT Host FROM mysql.user WHERE User=' . $this->quote($plan->dbUser),
        );

        return $code === 0 ? array_values(array_filter(array_map('trim', explode("\n", $out)))) : null;
    }

    /** Whether the database exists, or null when it cannot be told. */
    private function databaseExists(string $client, GridPlan $plan): ?bool
    {
        [$code, $out] = $this->admin(
            $client,
            $plan,
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=' . $this->quote($plan->dbName),
        );

        return $code === 0 ? trim($out) !== '' : null;
    }

    /** How to get the administrator access, for a user who is not root. */
    private function adminHint(): string
    {
        return function_exists('posix_geteuid') && posix_geteuid() !== 0 && System::commandExists('sudo')
            ? ' Run as root (e.g. sudo opensim setup), the setup has that access.'
            : '';
    }

    /** The host part of the account: the server sees local connections as coming from localhost. */
    private function accountHost(GridPlan $plan): string
    {
        return $this->isLocal($plan->dbHost) ? 'localhost' : '%';
    }

    private function isLocal(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function client(): ?string
    {
        foreach (['mysql', 'mariadb'] as $command) {
            if (System::commandExists($command)) {
                return $command;
            }
        }

        return null;
    }

    /** The client prints "ERROR <number> (<state>): <message>". */
    private function errno(string $err): int
    {
        return preg_match('/ERROR (\d+)/', $err, $m) ? (int) $m[1] : 0;
    }

    /**
     * What went wrong, from the client's output: it prints the failed
     * statement, password included, before its "ERROR <number>" line, so only
     * that line is shown.
     */
    private function errorLine(string $err): string
    {
        if (preg_match('/^ERROR .*$/m', $err, $m)) {
            return $m[0];
        }

        return trim($err) !== '' && !str_contains($err, 'IDENTIFIED') ? trim($err) : 'failed.';
    }

    private function quote(string $value): string
    {
        return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'"]) . "'";
    }

    /**
     * A statement as the grid's account, with or without a database: its
     * credentials only (the password goes through the environment, not the
     * command line), no option file.
     *
     * @return array{0:int,1:string,2:string} [exit code, stdout, stderr]
     */
    private function run(string $client, GridPlan $plan, ?string $database, string $sql = 'SELECT 1'): array
    {
        $command = [
            $client,
            '--no-defaults',
            '-h',
            $plan->dbHost,
            '-u',
            $plan->dbUser,
            '--connect-timeout=5',
            '-BN',
            '-e',
            $sql,
        ];
        if ($database !== null) {
            $command[] = $database;
        }

        return $this->exec($command, ['MYSQL_PWD' => $plan->dbPass]);
    }

    /**
     * A statement as the administrator of the server.
     *
     * @return array{0:int,1:string,2:string}
     */
    private function admin(string $client, GridPlan $plan, string $sql): array
    {
        $access = $this->adminAccess($client, $plan);
        if ($access === false) {
            return [1, '', $this->adminReason];
        }

        return $this->exec(array_merge($access['command'], ['-BN', '-e', $sql]), $access['env']);
    }

    /**
     * How to run statements as an administrator, found once per check, in
     * this order: the account entered earlier in the session; on a local
     * server, what the running user already has (root through the socket,
     * their own database account, sudo without a password), tried silently;
     * an account asked to the user.
     *
     * @return array{command:list<string>,env:array<string,string>}|false
     */
    private function adminAccess(string $client, GridPlan $plan): array|false
    {
        if ($this->adminAccess !== null) {
            return $this->adminAccess;
        }
        $this->adminReason = 'no administrator account was given';

        if (isset(self::$admins[$plan->dbHost])) {
            $access = $this->explicitAccess($client, $plan->dbHost, ...self::$admins[$plan->dbHost]);
            if ($this->works($access, 'SELECT 1')) {
                return $this->adminAccess = $access;
            }
        }

        if ($this->isLocal($plan->dbHost)) {
            $candidates = [[$client]];
            if (System::commandExists('sudo')) {
                $candidates[] = ['sudo', '-n', $client];
            }
            foreach ($candidates as $candidate) {
                $access = ['command' => $candidate, 'env' => []];
                // Reading the accounts tells an administrator from a user
                // who has only a database of their own
                if ($this->works($access, 'SELECT COUNT(*) FROM mysql.user')) {
                    return $this->adminAccess = $access;
                }
            }
        }

        return $this->adminAccess = $this->askAdmin($client, $plan);
    }

    /** Ask for an administrator account until one logs in, or the user gives up. */
    private function askAdmin(string $client, GridPlan $plan): array|false
    {
        $this->ui->note(
            sprintf(
                _(
                    'Creating what is missing needs an administrator account of the database server %s, one that may create users and databases.',
                ),
                $plan->dbHost,
            ) .
                ' ' .
                _('Its password is used for this setup only, kept in memory, never written.'),
        );
        if (
            !$this->ui->confirm(
                sprintf(_('Enter the credentials of an administrator account of %s?'), $plan->dbHost),
                true,
            )
        ) {
            return false;
        }

        do {
            $user = $this->ui->text(
                _('Administrator user'),
                'root',
                fn(string $value) => trim($value) === '' ? 'Required.' : null,
            );
            $access = $this->explicitAccess(
                $client,
                $plan->dbHost,
                $user,
                $this->ui->secret(sprintf(_('Password of %s'), $user)),
            );
            [$code, , $err] = $this->exec(array_merge($access['command'], ['-BN', '-e', 'SELECT 1']), $access['env']);
            if ($code === 0) {
                self::$admins[$plan->dbHost] = [$user, $access['env']['MYSQL_PWD']];

                return $access;
            }
            $this->ui->error(
                sprintf(_('Login refused for %s on %s: %s'), $user, $plan->dbHost, $this->errorLine($err)),
            );
        } while ($this->ui->confirm(_('Try again with other credentials?'), false));

        return false;
    }

    /**
     * The client's arguments for an account given explicitly: no option file
     * of the running user, the password through the environment.
     *
     * @return array{command:list<string>,env:array<string,string>}
     */
    private function explicitAccess(string $client, string $host, string $user, string $password): array
    {
        return [
            'command' => [$client, '--no-defaults', '-h', $host, '-u', $user, '--connect-timeout=5'],
            'env' => ['MYSQL_PWD' => $password],
        ];
    }

    /** @param array{command:list<string>,env:array<string,string>} $access */
    private function works(array $access, string $sql): bool
    {
        return $this->exec(array_merge($access['command'], ['-BN', '-e', $sql]), $access['env'])[0] === 0;
    }

    /** @return array{0:int,1:string,2:string} */
    private function exec(array $command, array $env): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, null, $env === [] ? null : array_merge(getenv(), $env));
        if (!is_resource($process)) {
            return [1, '', 'could not run ' . $command[0]];
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
