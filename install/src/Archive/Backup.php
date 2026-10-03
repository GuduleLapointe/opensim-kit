<?php

declare(strict_types=1);

namespace OpenSim\Installer\Archive;

use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Grid\GridState;
use OpenSim\Installer\Grid\SimState;

/**
 * The backup of the administrator: what it takes to install a grid again as it is, on another machine or after a crash.
 * One archive (gridnick[-sim]-stamp[-logs][-archives].tar.gz) per grid, or for one of its simulators, holds
 *
 *   manifest.json     what it is, when, of which host and install
 *   etc/              the configuration: the folder of the grid (its simulators, their regions, the web examples), the
 *                     links that enable them, opensim.conf
 *   var/              the persistent data: what the grid and its simulators keep in the data folder, without the archives
 *                     of the users (backups/iar and oar, with --archives) which are backups already
 *   db/               a dump of each database, of Robust and of the simulators
 *   logs/             the logs of the grid and its simulators (with --logs)
 *
 * The archive holds the passwords of the databases and of the consoles: it is for its owner only (mode 600).
 */
final class Backup
{
    /**
     * @param array<string,mixed> $profile the install profile
     * @param \Closure(array{host:string,name:string,user:string,pass:string},string):void $dump writes the dump of a
     *        database, compressed, to a file; throws \RuntimeException when it cannot
     * @param \Closure(string):void $say what the person is told
     */
    public function __construct(
        private array $profile,
        private \Closure $dump,
        private \Closure $say,
        private string $stamp = '',
    ) {
        $this->stamp = $stamp !== '' ? $stamp : Archives::stamp();
    }

    /** Where the backups go when nothing says: beside the data of the grids, not in it */
    public function defaultOutput(): string
    {
        return rtrim($this->profile['DataRoot'] ?? '', '/') . '/backups/admin';
    }

    /**
     * Back up what the words name: every grid of the install (none), a grid, or one simulator.
     *
     * @param list<string> $refs
     * @param array{output?:string,logs?:bool,archives?:bool} $options
     * @return list<string> the files made
     * @throws \RuntimeException
     */
    public function run(array $refs, array $options = []): array
    {
        $etc = $this->profile['EtcRoot'] ?? '';
        $targets = [];
        if ($refs === []) {
            foreach (Instances::grids($etc) as $nick) {
                $targets[] = [$nick, null];
            }
            if ($targets === []) {
                throw new \RuntimeException('no grid here');
            }
        } else {
            $targets[] = Instances::resolve($this->profile, $refs);
        }

        $files = [];
        foreach ($targets as [$nick, $slug]) {
            $files[] = $this->backup($nick, $slug, $options);
        }

        return $files;
    }

    /**
     * @param array{output?:string,logs?:bool,archives?:bool} $options
     */
    private function backup(string $nick, ?string $slug, array $options): string
    {
        $etc = rtrim($this->profile['EtcRoot'] ?? '', '/');
        $grid = GridInfo::load($this->profile, $nick) ?? throw new \RuntimeException("grid '$nick' is not known here");
        $output = rtrim($options['output'] ?? $this->defaultOutput(), '/');
        if (!is_dir($output) && !@mkdir($output, 0700, true) && !is_dir($output)) {
            throw new \RuntimeException("cannot make the folder $output");
        }

        $name = $nick . ($slug !== null ? '-' . $this->simName($nick, $slug) : '')
            . "-{$this->stamp}" . (($options['logs'] ?? false) ? '-logs' : '') . (($options['archives'] ?? false) ? '-archives' : '');
        $file = "$output/$name.tar.gz";
        if (file_exists($file)) {
            throw new \RuntimeException("$file exists already");
        }
        $tar = "$output/$name.tar";
        $stage = "$output/.$name.stage";
        @mkdir($stage, 0700, true);

        try {
            ($this->say)("backing up " . ($slug ?? "grid $nick") . '...');
            $databases = $this->databases($grid, $slug);
            @mkdir("$stage/db", 0700, true);
            foreach ($databases as $db) {
                ($this->say)("  database {$db['name']}");
                ($this->dump)($db, "$stage/db/{$db['name']}.sql.gz");
            }
            file_put_contents("$stage/manifest.json", json_encode([
                'kit' => 'opensim-kit',
                'date' => date('c'),
                'host' => gethostname(),
                'grid' => $nick,
                'simulator' => $slug,
                'databases' => array_map(static fn(array $db): array => ['host' => $db['host'], 'name' => $db['name'], 'user' => $db['user']], $databases),
                'etc' => $etc,
                'data' => $grid->dataDirectory,
                'logs' => (bool) ($options['logs'] ?? false),
                'archives' => (bool) ($options['archives'] ?? false),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            $this->tar(['-cf', $tar, '-C', $stage, 'manifest.json', 'db']);
            // The configuration
            $paths = $slug === null ? ["grids/$nick"] : [];
            $excludes = [];
            if ($slug !== null) {
                // The simulator, with what it shares with the others (the files of the grid, not their simulators)
                foreach (SimState::names($grid->dir) as $other) {
                    if ($other !== $slug) {
                        $excludes[] = "grids/$nick/sims/$other";
                        $excludes[] = "grids/$nick/sims/$other.ini";
                    }
                }
                $paths = ["grids/$nick"];
            }
            $links = $slug === null
                ? [GridState::link($etc, $nick), ...array_map(static fn(string $s): string => SimState::link($etc, $s), SimState::names($grid->dir))]
                : [SimState::link($etc, $slug)];
            foreach ($links as $link) {
                if (is_link($link) && str_starts_with($link, "$etc/")) {
                    $paths[] = substr($link, strlen($etc) + 1);
                }
            }
            if (is_file("$etc/opensim.conf")) {
                $paths[] = 'opensim.conf';
            }
            $this->append($tar, 'etc', $etc, $paths, $excludes);

            // The data: the folder of the grid, without the archives of the users unless asked
            $data = rtrim($grid->dataDirectory, '/');
            if (is_dir($data)) {
                $excludes = ($options['archives'] ?? false) ? [] : [basename($data) . '/backups'];
                $root = dirname($data);
                if ($slug !== null) {
                    $this->append($tar, 'var', $root, array_filter([basename($data) . "/$slug"], static fn(string $p): bool => is_dir("$root/$p")), []);
                } else {
                    $this->append($tar, 'var', $root, [basename($data)], $excludes);
                }
            }
            if ($options['logs'] ?? false) {
                $logs = rtrim($grid->logsDirectory, '/');
                $names = $slug !== null ? ["$slug.log*"] : array_merge(["{$grid->robustInstance()}.log*"], array_map(static fn(string $s): string => "$s.log*", SimState::names($grid->dir)));
                $found = [];
                foreach ($names as $pattern) {
                    foreach (glob("$logs/$pattern") ?: [] as $log) {
                        $found[] = basename($log);
                    }
                }
                $this->append($tar, 'logs', $logs, $found, []);
            }

            $this->run2(['gzip', '-f', $tar]);
            chmod($file, 0600);
        } catch (\Throwable $e) {
            foreach ([$tar, $file] as $partial) {
                is_file($partial) && unlink($partial);
            }
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        } finally {
            $this->remove($stage);
        }
        ($this->say)("  $file");

        return $file;
    }

    /** The name of a simulator without the grid's: the part of its instance after the nick */
    private function simName(string $nick, string $slug): string
    {
        $prefix = GridInfo::instanceName($nick . '_');

        return Archives::part(str_starts_with($slug, $prefix) ? substr($slug, strlen($prefix)) : $slug);
    }

    /**
     * The databases to dump: Robust and the simulators of the grid, or the one of a simulator.
     *
     * @return list<array{host:string,name:string,user:string,pass:string}>
     */
    private function databases(GridInfo $grid, ?string $slug): array
    {
        $found = [];
        if ($slug === null && !$grid->remote && $grid->dbName !== '') {
            $found["{$grid->dbHost}/{$grid->dbName}"] = ['host' => $grid->dbHost, 'name' => $grid->dbName, 'user' => $grid->dbUser, 'pass' => $grid->dbPass];
        }
        foreach ($slug !== null ? [$slug] : SimState::names($grid->dir) as $sim) {
            $db = GridInfo::parse("{$grid->dir}/sims/$sim.ini");
            if (($db['dbName'] ?? '') !== '') {
                $found[($db['dbHost'] ?? 'localhost') . '/' . $db['dbName']] = [
                    'host' => $db['dbHost'] ?? 'localhost',
                    'name' => $db['dbName'],
                    'user' => $db['dbUser'] ?? '',
                    'pass' => $db['dbPass'] ?? '',
                ];
            }
        }

        return array_values($found);
    }

    /**
     * Add paths of a folder to the archive, under a name of its own.
     *
     * @param list<string> $paths relative to $root
     * @param list<string> $excludes relative to $root
     */
    private function append(string $tar, string $under, string $root, array $paths, array $excludes): void
    {
        if ($paths === []) {
            return;
        }
        $command = ['-rf', $tar, '--transform', 's,^,' . $under . '/,S', '--ignore-failed-read'];
        foreach ($excludes as $exclude) {
            $command[] = '--exclude=' . $exclude;
        }
        $this->tar([...$command, '-C', $root, ...$paths]);
    }

    private function tar(array $arguments): void
    {
        $this->run2(['tar', ...$arguments]);
    }

    private function run2(array $command): void
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException("cannot run {$command[0]}");
        }
        stream_get_contents($pipes[1]);
        $error = trim((string) stream_get_contents($pipes[2]));
        if (proc_close($process) !== 0) {
            throw new \RuntimeException("{$command[0]} failed" . ($error !== '' ? ": $error" : ''));
        }
    }

    private function remove(string $directory): void
    {
        foreach (glob("$directory/{,.}*", GLOB_BRACE) ?: [] as $entry) {
            if (in_array(basename($entry), ['.', '..'], true)) {
                continue;
            }
            is_dir($entry) && !is_link($entry) ? $this->remove($entry) : @unlink($entry);
        }
        @rmdir($directory);
    }

    /**
     * The dump of a database through mysqldump (or mariadb-dump), the password given in a file of options that nobody but
     * the user reads, not in the arguments of a process. Compressed.
     *
     * @param array{host:string,name:string,user:string,pass:string} $db
     */
    public static function mysqldump(array $db, string $file): void
    {
        $client = null;
        foreach (['mysqldump', 'mariadb-dump'] as $candidate) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
            if ($path !== '') {
                $client = $path;
                break;
            }
        }
        if ($client === null) {
            throw new \RuntimeException('mysqldump not found (the package of the client of the database, mariadb-client)');
        }
        $host = $db['host'];
        $port = null;
        if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
            [$host, $port] = [$m[1], $m[2]];
        }
        $options = tempnam(sys_get_temp_dir(), 'osdump');
        chmod($options, 0600);
        $quote = static fn(string $v): string => '"' . addcslashes($v, '\\"') . '"';
        file_put_contents($options, "[client]\nhost=" . $quote($host) . ($port !== null ? "\nport=$port" : '') . "\nuser=" . $quote($db['user']) . "\npassword=" . $quote($db['pass']) . "\n");
        // MySQL 8 asks for a privilege the account of a grid does not have unless told it has no tablespaces to dump
        $tablespaces = str_contains((string) shell_exec(escapeshellarg($client) . ' --help 2>/dev/null'), 'no-tablespaces') ? ['--no-tablespaces'] : [];
        try {
            $process = proc_open(
                [$client, "--defaults-extra-file=$options", '--single-transaction', '--routines', '--events', ...$tablespaces, $db['name']],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                throw new \RuntimeException("cannot run $client");
            }
            $out = gzopen($file, 'wb6');
            // The errors are read as they come, not after: the dump must not wait for a full pipe
            stream_set_blocking($pipes[2], false);
            $error = '';
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk !== false && $chunk !== '') {
                    gzwrite($out, $chunk);
                }
                $error .= (string) stream_get_contents($pipes[2]);
            }
            $error .= (string) stream_get_contents($pipes[2]);
            gzclose($out);
            if (proc_close($process) !== 0) {
                throw new \RuntimeException("dump of {$db['name']} failed: " . trim($error));
            }
        } finally {
            @unlink($options);
        }
        chmod($file, 0600);
    }
}
