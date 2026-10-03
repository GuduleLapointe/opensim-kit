<?php

declare(strict_types=1);

namespace OpenSim\Installer\Grid;

use OpenSim\Installer\Config;
use OpenSim\Installer\Elevated;
use OpenSim\Installer\Ports;
use OpenSim\Installer\Setup\SetupFile;
use OpenSim\Installer\SetupFailed;
use OpenSim\Installer\System;
use OpenSim\Installer\TextFile;
use OpenSim\Installer\Ui\InstallerUi;
use OpenSim\Installer\Web\HelpersConfig;
use OpenSim\Installer\Web\Services;
use OpenSim\Installer\Web\Snippets;

/**
 * Configure (or reconfigure) one grid on top of an installed framework.
 *
 * Reusable flow driven through an InstallerUi: the setup hub calls run() with a
 * grid nick to modify, or null to create a new one. Returns to the caller when
 * done (never exits the process). Phase 2a: gather only.
 */
final class NewGrid
{
    public function __construct(private InstallerUi $ui) {}

    /** @return ?string the nick of the grid configured, null when nothing was */
    public function run(?string $modifyNick = null): ?string
    {
        $profile = (new Config())->profile();
        if ($profile === [] || ($profile['EtcRoot'] ?? '') === '') {
            $this->ui->error(_('No installed framework found. Install an OpenSim core first.'));

            return null;
        }

        $plan = $this->gather($profile, $modifyNick);
        if ($plan === null) {
            return null; // abandoned
        }

        return $this->complete($plan, $profile, false);
    }

    /**
     * The questions of the setup, nothing is made: the plan, to show and to accept with the one of the first
     * simulator (the quick setup), then complete().
     *
     * @return ?array{0:GridPlan,1:array<string,mixed>} the plan and the install profile, null when abandoned
     */
    public function prepare(?string $modifyNick = null): ?array
    {
        $profile = (new Config())->profile();
        if ($profile === [] || ($profile['EtcRoot'] ?? '') === '') {
            $this->ui->error(_('No installed framework found. Install an OpenSim core first.'));

            return null;
        }
        $plan = $this->gather($profile, $modifyNick);

        return $plan === null ? null : [$plan, $profile];
    }

    /**
     * What the plan will do, as the setup shows it.
     */
    public function describe(GridPlan $plan): string
    {
        return $this->planText($plan);
    }

    /**
     * Make the grid of a plan: the database, then the files and the instance. The plan is shown and has to be
     * accepted, unless it was already ($accepted).
     *
     * @param array<string,mixed> $profile
     * @return ?string the nick of the grid configured, null when nothing was
     */
    public function complete(GridPlan $plan, array $profile, bool $accepted): ?string
    {
        // Nothing is written before the database is known to work. A problem of
        // access leaves the error on screen and asks the settings again; a creation
        // that failed ends the setup.
        $database = new Database($this->ui);
        $attempts = 0;
        while (($result = $database->ensure($plan)) !== Database::OK) {
            if ($result === Database::ABORT) {
                $this->ui->error(_('Stopped, nothing was changed: OpenSim cannot run without its database.'));

                throw new SetupFailed('database');
            }
            // The error is on screen: the settings are asked again at once, they may be what is wrong
            if (++$attempts > 10) {
                $this->ui->error(_('Stopped, nothing was changed: OpenSim cannot run without its database.'));

                throw new SetupFailed('database');
            }
            $this->askDatabase($plan, [
                'dbHost' => $plan->dbHost,
                'dbName' => $plan->dbName,
                'dbUser' => $plan->dbUser,
                'dbPass' => $plan->dbPass,
            ]);
        }

        if (!$accepted) {
            $this->showPlan($plan);
            if (!$this->ui->confirm(sprintf(_("Apply this configuration to grid '%s'?"), $plan->gridNick), true)) {
                $this->ui->note(_('Aborted — nothing changed.'));

                return null;
            }
        }

        // What to do once written is asked here, while the user is at the
        // keyboard: writing may be done by another process
        $plan->enable = $this->ui->confirm(sprintf(_("Enable grid '%s' (link into robust.d)?"), $plan->gridNick), true);
        $plan->start = $plan->enable && $this->ui->confirm(sprintf(_("Start grid '%s' now?"), $plan->gridNick), true);

        $this->write($plan, $profile);

        return $plan->gridNick;
    }

    /**
     * Write the grid as the user who owns the install. The questions, and the
     * database (with the rights of the user who started the setup, whatever
     * they are), are handled by this process; only the files and the
     * instances belong to the system user of the install (the packages). A
     * process of that user does the writing, unless this one already is that
     * user, or root, or the install has none.
     */
    private function write(GridPlan $plan, array $profile): void
    {
        if (!Elevated::needed($profile)) {
            $this->apply($plan, $profile);

            return;
        }

        Elevated::run(
            $this->ui,
            '--apply-grid',
            ['plan' => $plan->toArray(), 'profile' => $profile],
            $profile['SystemUser'],
        );
    }

    /** The writing itself: run as the system user of the install, root, or the user of an install without one. */
    public function apply(GridPlan $plan, array $profile): void
    {
        $etcRoot = $profile['EtcRoot'];
        $this->makeDirs($plan);
        $conf = (new GridConf())->write($plan);
        $this->ui->note(sprintf(_("Wrote %s"), $conf));
        $this->writeRobust($plan);
        $this->writeHelpers($plan);
        $this->copyConfigInclude($plan);

        $logConfig = (new LogConfig())->write($plan);
        if ($logConfig !== null) {
            $this->ui->note(sprintf(_("Wrote %s"), $logConfig));
        }

        // What was asked is kept, to make the same grid again from a file (opensim import)
        SetupFile::record($plan->gridDir, static fn(array $data): array => SetupFile::withGrid($data, $plan));

        $systemUser = $profile['SystemUser'] ?? '';
        $this->giveToSystemUser($systemUser, [$plan->etcDirectory, $plan->dataDirectory, $plan->cacheDirectory], true);

        if ($plan->enable) {
            if (GridState::enable($etcRoot, $plan->gridNick)) {
                $this->giveToSystemUser($systemUser, [GridState::link($etcRoot, $plan->gridNick)], false);
                $this->ui->note(sprintf(_('Enabled: %s'), GridState::link($etcRoot, $plan->gridNick)));
                if ($plan->start) {
                    $this->startGrid($plan);
                }
            } else {
                $this->ui->warn(_('Could not enable the grid (Robust config missing).'));
            }
        }

        $this->ui->note(sprintf(_("Grid '%s' configured."), $plan->gridName));
        if ($plan->helpers) {
            $this->ui->note(
                sprintf(
                    _("Web site: the helpers are in %s, the placeholder site in /usr/share/opensim-web/html (when the opensim-web package is installed). The configuration for your web server is in %s/web: %s.caddyfile, %s-nginx.conf, %s-apache.conf, to include in the site of the grid (opensim web %s snippet <caddy|nginx|apache> writes it again)."),
                    Snippets::WEBROOT,
                    $plan->gridDir,
                    $plan->gridNick,
                    $plan->gridNick,
                    $plan->gridNick,
                    $plan->gridNick,
                ),
            );
        }
    }

    /**
     * When the setup runs as root for an install with a system user (the
     * packages), what it creates belongs to that user, who runs the
     * instances. Nothing to do otherwise.
     *
     * @param list<string> $paths
     */
    private function giveToSystemUser(string $user, array $paths, bool $recursive): void
    {
        if (
            $user === '' ||
            !function_exists('posix_geteuid') ||
            posix_geteuid() !== 0 ||
            posix_getpwnam($user) === false
        ) {
            return;
        }
        foreach ($paths as $path) {
            if (is_link($path) || file_exists($path)) {
                System::run(
                    'chown -h' . ($recursive ? 'R' : '') . ' ' . System::arg($user) . ': ' . System::arg($path),
                );
            }
        }
    }

    /**
     * Start the grid and report whether it came up, by the result of the
     * launcher (a screen session is per user: the setup may not be the one
     * running the instances). A grid that does not start ends the setup.
     */
    private function startGrid(GridPlan $plan): void
    {
        $opensim = dirname(__DIR__, 3) . '/bin/opensim';
        $nick = $plan->gridNick;

        [$code, $said] = System::runShown(System::arg($opensim) . ' restart ' . System::arg($nick));
        // The launcher waits for the ready signal in the console. After its
        // delay it says the instance is still starting, and succeeds: a
        // healthy Robust is ready within a minute, so that is a failure too.
        $pending = $code === 0 && str_contains($said, 'still starting');
        if ($code === 0 && !$pending) {
            $this->ui->note(sprintf(_("Grid '%s' is running."), $nick));

            return;
        }

        $this->ui->error(
            $pending
                ? sprintf(_("Grid '%s' is configured but was not ready after two minutes. Try: %s -v start %s"), $nick, $opensim, $nick)
                : sprintf(_("Grid '%s' is configured but did not start. Try: %s -v start %s"), $nick, $opensim, $nick),
        );
        $log = $plan->logsDirectory . '/' . $plan->gridSlug . '_robust.log';
        if (is_file($log)) {
            $this->ui->note(_('Recent log:'));
            System::run('tail -n 30 ' . System::arg($log));
        }

        throw new SetupFailed("Grid '$nick' did not start.");
    }

    private function makeDirs(GridPlan $plan): void
    {
        $dirs = [
            $plan->dataDirectory,
            "{$plan->dataDirectory}/fsassets",
            "{$plan->dataDirectory}/fsassets/data",
            "{$plan->dataDirectory}/maptiles",
            "{$plan->dataDirectory}/registry",
            $plan->cacheDirectory,
            "{$plan->cacheDirectory}/bakes",
            "{$plan->cacheDirectory}/fsassets",
            "{$plan->cacheDirectory}/fsassets/tmp",
            "{$plan->cacheDirectory}/maptiles",
            $plan->etcDirectory,
            "{$plan->etcDirectory}/assets",
            "{$plan->etcDirectory}/config-include",
            "{$plan->etcDirectory}/sims",
            "{$plan->etcDirectory}/inventory",
            "{$plan->etcDirectory}/robust-include",
            $plan->logsDirectory,
        ];
        foreach ($dirs as $dir) {
            if ($dir !== '' && !is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
        }
    }

    private function writeRobust(GridPlan $plan): void
    {
        $path = $plan->robustIni();
        if (is_file($path)) {
            @copy($path, "$path~"); // backup
        }
        file_put_contents($path, (new RobustConfig())->generate($plan));
        $this->ui->note(sprintf(_("Wrote %s"), $path));
    }

    /**
     * The helpers.ini of the grid: what opensim-helpers needs, so the web server does not have to read the Robust
     * config. What the operator changed in it is kept. Readable by the group of the web server when this process
     * can give it, else by everybody who can reach the folder of the grid.
     */
    private function writeHelpers(GridPlan $plan): void
    {
        if (!$plan->helpers) {
            return;
        }
        $path = HelpersConfig::path($plan->gridDir);
        $text = HelpersConfig::render(
            is_file($path) ? TextFile::read($path) : '',
            [
                'gridName' => $plan->gridName,
                'loginUri' => "http://{$plan->baseHostname}:{$plan->publicPort}",
                'webUrl' => $plan->webUrl,
                'mailSender' => '',
                'dbHost' => $plan->dbHost,
                'dbName' => $plan->dbName,
                'dbUser' => $plan->dbUser,
                'dbPass' => $plan->dbPass,
            ],
            $plan->helpersPath,
        );
        file_put_contents($path, $text);
        $group = function_exists('posix_getgrnam') ? posix_getgrnam('www-data') : false;
        if ($group !== false && @chgrp($path, $group['gid'])) {
            chmod($path, 0o640);
        } else {
            chmod($path, 0o644);
            $this->ui->warn(sprintf(_("%s holds the database password and is readable by every user of this machine: give it to the group of your web server (chgrp, then chmod 640)."), $path));
        }
        $this->ui->note(sprintf(_("Wrote %s"), $path));

        // The configuration of the web server for this grid, one file for each server, to include in its site
        $webDir = "{$plan->gridDir}/web";
        is_dir($webDir) || mkdir($webDir, 0o755, true);
        $services = new Services($plan->helpersPath, $plan->helpersUrls);
        // Named for the grid and the server, with the extension the editors know
        $names = ['caddy' => "{$plan->gridNick}.caddyfile", 'nginx' => "{$plan->gridNick}-nginx.conf", 'apache' => "{$plan->gridNick}-apache.conf"];
        foreach (Snippets::SERVERS as $server) {
            $file = "$webDir/{$names[$server]}";
            file_put_contents($file, Snippets::render($server, $plan->gridNick, $services));
            chmod($file, 0o644);
        }
        $this->ui->note(sprintf(_('Wrote the web server examples in %s'), $webDir));
    }

    private function copyConfigInclude(GridPlan $plan): void
    {
        $files = [
            'OpenSimDefaults.ini',
            'OpenSim.ini',
            'config-include/GridHypergrid.ini',
            'config-include/GridCommon.ini',
            'config-include/FlotsamCache.ini',
            'config-include/osslDefaultEnable.ini',
            'config-include/osslEnable.ini',
        ];
        foreach ($files as $file) {
            $dest = "{$plan->etcDirectory}/$file";
            if (is_file($dest)) {
                TextFile::clean($dest); // local changes are kept, only the line endings are made Unix ones
                continue;
            }
            $src = is_file("{$plan->binDir}/{$file}.example")
                ? "{$plan->binDir}/{$file}.example"
                : "{$plan->binDir}/$file";
            if (!is_file($src)) {
                continue;
            }
            @mkdir(dirname($dest), 0o755, true);
            TextFile::copy($src, $dest);
        }
        // Ready for the simulators that will join the grid
        (new GridShared())->prepare($plan->etcDirectory, $plan->binDir, $plan->enableHypergrid);
        $this->ui->note(_('Copied config-include defaults.'));
    }

    private function gather(array $profile, ?string $modifyNick): ?GridPlan
    {
        $required = static fn(string $v): ?string => trim($v) === '' ? 'This field is required.' : null;
        $numeric = static fn(string $v): ?string => ctype_digit(trim($v)) ? null : _('Enter a port number.');

        $etcRoot = $profile['EtcRoot'];

        // Identify the grid and load its existing config, if any.
        if ($modifyNick !== null) {
            $nick = $modifyNick;
            $gridDir = "$etcRoot/grids/$nick";
            $existing = $this->findExisting($gridDir);
            $current = $existing !== null ? $this->parseExisting($existing) : [];
            $name = $this->ui->text(_('Grid name'), $current['gridName'] ?? ucfirst($nick), $required);
        } else {
            $defaultName = self::defaultName();
            $v = $this->ui->form([
                ['key' => 'name', 'label' => _('Grid name'), 'default' => $defaultName, 'validate' => $required],
                [
                    'key' => 'nick',
                    'label' => _('Grid nick (snake_case)'),
                    'default' => Slug::nick($defaultName),
                    'hint' => _('Names its folder, its database and its instances'),
                    'validate' => $required,
                ],
            ], _('Grid'));
            $name = $v['name'];
            // A nick left as proposed follows the name
            $nick = $v['nick'] === Slug::nick($defaultName) ? Slug::nick($name) : $v['nick'];
            $gridDir = "$etcRoot/grids/$nick";
            $existing = $this->findExisting($gridDir);
            $current = [];
            if ($existing !== null) {
                $action = $this->ui->choose(
                    sprintf(_("Grid '%s' is already configured (%s)."), $nick, $existing),
                    ['modify' => _('Modify its settings'), 'abandon' => _('Abandon, keep it unchanged')],
                    'modify',
                );
                if ($action === 'abandon') {
                    $this->ui->note(_('Left unchanged.'));

                    return null;
                }
                $current = $this->parseExisting($existing);
            }
        }

        $plan = new GridPlan();
        $plan->gridName = $name;
        $plan->gridNick = $nick;
        $plan->gridSlug = Slug::slug($name);
        $plan->gridDir = $gridDir;
        $plan->etcDirectory = $gridDir;
        $plan->dataDirectory = "{$profile['DataRoot']}/$nick";
        $plan->cacheDirectory = "{$profile['CacheRoot']}/$nick";
        $plan->logsDirectory = $profile['LogsRoot'] ?? '';

        // Hypergrid — after the existence check; default from the existing file.
        $hgDefault = $existing !== null ? str_contains(basename($existing), '.HG.') : true;
        $plan->enableHypergrid = $this->ui->confirm(_('Enable Hypergrid?'), $hgDefault);

        // The rule that places the regions: free blocks between them
        $plan->regionSpacing = (int) $this->ui->text(
            _('Free blocks between regions (0: side by side)'),
            '0',
            static fn(string $v): ?string => ctype_digit(trim($v)) && (int) $v <= 50
                ? null
                : _('A number of blocks, 0 to 50.'),
        );

        // Core selection (multi-version aware).
        $coreRoot = $profile['CoreRoot'] ?? '';
        $cores = Cores::list($coreRoot);
        if ($cores === []) {
            $this->ui->error(sprintf(_("No OpenSim core found under %s."), $coreRoot));

            return null;
        }
        $plan->coreDirectory = $this->ui->choose(
            _('OpenSim core to run this grid'),
            $cores,
            $profile['CoreDirectory'] ?? null,
        );
        $plan->binDir = $plan->coreDirectory . '/bin';

        $defaultHost = $current['baseHostname'] ?? self::defaultHost();
        $defaultPublic = (int) ($current['publicPort'] ?? self::defaultPublicPort());
        // The ports of an instance are a block of ten, the first free one (see Ports):
        // public ends with 2, private with 3, the console with 4
        $defaultPrivate = (int) ($current['privatePort'] ??
            ($defaultPublic % 10 === 2 ? $defaultPublic + 1 : Ports::next($defaultPublic + 1)));
        $defaultWeb = $current['webUrl'] ?? "https://$defaultHost";
        $v = $this->ui->form([
            ['key' => 'host', 'label' => _('Base hostname'), 'default' => $defaultHost, 'validate' => $required],
            ['key' => 'public', 'label' => _('Public port'), 'default' => (string) $defaultPublic, 'validate' => $numeric],
            ['key' => 'private', 'label' => _('Private port'), 'default' => (string) $defaultPrivate, 'validate' => $numeric],
            ['key' => 'web', 'label' => _('Web URL'), 'default' => $defaultWeb, 'validate' => $required],
        ], _('Network'));
        $plan->baseHostname = $v['host'];
        $plan->publicPort = (int) $v['public'];
        // What was left as proposed follows what was changed
        $plan->privatePort = (int) $v['private'] === $defaultPrivate && (int) $v['public'] !== $defaultPublic
            ? ($plan->publicPort % 10 === 2 ? $plan->publicPort + 1 : Ports::next($plan->publicPort + 1))
            : (int) $v['private'];
        $plan->webUrl = $v['web'] === $defaultWeb && $v['host'] !== $defaultHost ? "https://{$plan->baseHostname}" : $v['web'];
        $this->askHelpers($plan, $gridDir);

        $this->askConsole($plan, $current, $numeric);

        // Database: reuse a found password, otherwise generate one (never changeme).
        $foundPass = $current['dbPass'] ?? '';
        $this->askDatabase($plan, [
            'dbHost' => $current['dbHost'] ?? ($profile['DataSource'] ?? 'localhost'),
            'dbName' => $current['dbName'] ?? strtolower($nick) . '_robust',
            'dbUser' => $current['dbUser'] ?? 'opensim',
            'dbPass' => $foundPass !== '' && $foundPass !== 'changeme' ? $foundPass : $this->randomPassword(),
        ]);

        return $plan;
    }

    /**
     * The helpers of the grid: the economy, the search and the offline messages the viewers use, served by the web
     * site with opensim-helpers (see `opensim web`). Where they are on the web site is the operator's choice, the
     * paths of each service too (helpers.ini).
     */
    private function askHelpers(GridPlan $plan, string $gridDir): void
    {
        $existing = HelpersConfig::read($gridDir);
        $plan->helpers = $this->ui->confirm(
            _('Serve the economy and the search of the grid with opensim-helpers?'),
            $existing !== [] || is_dir(Snippets::WEBROOT),
        );
        if (!$plan->helpers) {
            return;
        }
        $plan->helpersUrls = $existing['Urls'] ?? [];
        // The URL of the web site is given, the path after it is the choice of the operator
        $plan->helpersPath = Services::normalize(
            $this->ui->text(
                sprintf(_('Helpers path, after %s'), rtrim($plan->webUrl, '/')),
                $existing['Helpers']['path'] ?? HelpersConfig::DEFAULT_PATH,
                static fn(string $v): ?string => preg_match('#^/?[A-Za-z0-9._/-]*$#', trim($v))
                    ? null
                    : _('A path such as /helpers.'),
            ),
        );
        $this->ui->note(
            sprintf(_('Helpers URL: %s (the viewers add the name of the script)'), rtrim($plan->webUrl, '/') . $plan->helpersPath),
        );
    }

    /**
     * The console of the grid: remote (REST, through its port x4: reachable from
     * another machine or a container) or a screen session to attach here. What an
     * existing config has is kept, password included.
     *
     * @param array<string,string|int> $current
     */
    private function askConsole(GridPlan $plan, array $current, \Closure $numeric): void
    {
        $plan->consoleMode = $this->ui->choose(
            _('Console of the grid'),
            [
                'rest' => _('Remote REST console (recommended)'),
                'screen' => _('Screen session (on this machine)'),
            ],
            isset($current['consoleUser']) || $current === [] ? 'rest' : 'screen',
        );
        if ($plan->consoleMode !== 'rest') {
            return;
        }

        $base = $plan->publicPort % 10 === 2 ? $plan->publicPort - 2 : 0;
        $plan->consolePort = (int) $this->ui->text(
            _('Console port'),
            (string) ($current['consolePort'] ?? ($base > 0 ? $base + 4 : Ports::next($plan->privatePort + 1))),
            $numeric,
        );
        // As the helpers make theirs: 12 lower case letters, 32 letters and digits
        $plan->consoleHost = (string) ($current['consoleHost'] ?? $plan->baseHostname);
        $plan->consoleUser = (string) ($current['consoleUser'] ?? $this->randomLetters(12));
        $plan->consolePass = (string) ($current['consolePass'] ?? $this->randomPassword(32));
    }

    /**
     * The database settings, with these defaults.
     *
     * @param array{dbHost:string,dbName:string,dbUser:string,dbPass:string} $defaults
     */
    private function askDatabase(GridPlan $plan, array $defaults): void
    {
        $required = static fn(string $v): ?string => trim($v) === '' ? 'This field is required.' : null;

        // An account keeps its password for the whole session, entered or
        // generated once: an attempt started again proposes the same one
        $password = Database::recall($defaults['dbHost'], $defaults['dbUser']) ?? $defaults['dbPass'];
        $v = $this->ui->form([
            ['key' => 'host', 'label' => _('Database host'), 'default' => $defaults['dbHost'], 'validate' => $required],
            ['key' => 'name', 'label' => _('Database name'), 'default' => $defaults['dbName'], 'validate' => $required],
            ['key' => 'user', 'label' => _('Database user'), 'default' => $defaults['dbUser'], 'validate' => $required],
            ['key' => 'pass', 'label' => _('Database password'), 'default' => $password, 'validate' => $required],
        ], _('Database'));
        [$plan->dbHost, $plan->dbName, $plan->dbUser, $plan->dbPass] = [$v['host'], $v['name'], $v['user'], $v['pass']];
        Database::remember($plan->dbHost, $plan->dbUser, $plan->dbPass);
    }

    /** Existing Robust config for a grid (HG preferred), or null. */
    private function findExisting(string $gridDir): ?string
    {
        foreach (['Robust.HG.ini', 'Robust.ini'] as $file) {
            if (is_file("$gridDir/$file")) {
                return "$gridDir/$file";
            }
        }

        return null;
    }

    /** Default grid name: the short hostname, capitalised (amy.magiiic.com -> Amy). */
    /** The host name a grid is proposed: the one of the machine. */
    public static function defaultHost(): string
    {
        return trim(System::capture('hostname -f')[1]) ?: 'localhost';
    }

    /** The public port a grid is proposed: the one of the first block of ten ports that is free, ending with 2. */
    public static function defaultPublicPort(): int
    {
        return Ports::nextBlock(8000) + 2;
    }

    /** The name a grid is proposed: the one of the machine. */
    public static function defaultName(): string
    {
        [, $host] = System::capture('hostname -s');
        $short = trim($host);
        if ($short === '') {
            [, $fqdn] = System::capture('hostname -f');
            $short = strtok(trim($fqdn), '.') ?: 'My';
        }

        return ucfirst($short);
    }

    /** Extract current settings from an existing Robust config. */
    private function parseExisting(string $path): array
    {
        return GridInfo::parse($path);
    }

    private function randomLetters(int $length): string
    {
        $letters = '';
        for ($i = 0; $i < $length; $i++) {
            $letters .= chr(random_int(97, 122));
        }

        return $letters;
    }

    private function randomPassword(int $length = 20): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    private function showPlan(GridPlan $plan): void
    {
        $this->ui->note($this->planText($plan));
    }

    private function planText(GridPlan $plan): string
    {
        $lines = [
            "  Grid:        {$plan->gridName}  ({$plan->gridNick})",
            '  Hypergrid:   ' . ($plan->enableHypergrid ? 'yes' : 'no'),
            '  Regions:     ' .
            ($plan->regionSpacing === 0 ? 'side by side' : "{$plan->regionSpacing} free block(s) between them"),
            "  Core:        {$plan->coreDirectory}",
            "  Hostname:    {$plan->baseHostname}",
            "  Ports:       public {$plan->publicPort} / private {$plan->privatePort}",
            '  Console:     ' .
            ($plan->consoleMode === 'rest'
                ? "remote, port {$plan->consolePort}, user {$plan->consoleUser}"
                : 'screen session'),
            "  Web URL:     {$plan->webUrl}",
            '  Helpers:     ' . ($plan->helpers ? "served at {$plan->webUrl}{$plan->helpersPath}" : 'not served by this web site'),
            "  Database:    {$plan->dbName} @ {$plan->dbHost} (user {$plan->dbUser})",
            "  Robust ini:  {$plan->robustIni()}",
            "  Grid dir:    {$plan->etcDirectory}",
        ];
        return _('Grid plan:') . "\n" . implode("\n", $lines);
    }
}
