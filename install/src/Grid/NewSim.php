<?php

declare(strict_types=1);

namespace OpenSim\Installer\Grid;

use OpenSim\Installer\Config;
use OpenSim\Installer\Console;
use OpenSim\Installer\Elevated;
use OpenSim\Installer\PendingRestarts;
use OpenSim\Installer\Ports;
use OpenSim\Installer\Setup\SetupFile;
use OpenSim\Installer\SetupFailed;
use OpenSim\Installer\System;
use OpenSim\Installer\Ui\InstallerUi;

/**
 * Create (or reconfigure) one simulator of a grid, with its first region.
 *
 * Same shape as NewGrid: the questions, and the database with the rights of
 * the user who started the setup, are handled here; the files and the
 * instances belong to the system user of the install (see Elevated).
 *
 * A simulator has its own database (its regions, its estates) and an estate
 * whose owner is an account of the grid: OpenSimulator cannot create it, so
 * the account is chosen among the grid's, or created in the grid first.
 */
final class NewSim
{
    private const LANDING = 'At least a default landing region should be created.';
    private const FIRST = 'Welcome';

    public function __construct(private InstallerUi $ui) {}

    /**
     * @param ?string $gridNick the grid it joins, asked when there are several and none is given
     * @param ?string $simName  the simulator to modify, asked when none is given
     * @return ?array{0:string,1:string} the grid and the instance of the simulator configured, null when nothing was
     */
    public function run(?string $gridNick = null, ?string $simName = null, bool $external = false): ?array
    {
        $profile = (new Config())->profile();
        $etcRoot = $profile['EtcRoot'] ?? '';
        if ($etcRoot === '') {
            $this->ui->error(_('No installed framework found. Install an OpenSim core first.'));

            return null;
        }

        $grid = $this->grid($profile, $gridNick, $external);
        if ($grid === null) {
            return null;
        }

        $plan = $this->gather($grid, $profile, new Database($this->ui), $simName);
        if ($plan === null) {
            return null;
        }

        return $this->complete($plan, $grid, $profile, false);
    }

    /**
     * The questions of a simulator and of its first region, nothing is made. The grid can be one that is only planned
     * (GridInfo::fromPlan): the plan of the simulator is then shown with the one of the grid (the quick setup).
     */
    public function prepare(GridInfo $grid, array $profile, ?string $simName = null): ?SimPlan
    {
        return $this->gather($grid, $profile, new Database($this->ui), $simName);
    }

    /** What the plan will do, as the setup shows it. */
    public function describe(SimPlan $plan): string
    {
        return $this->planText($plan);
    }

    /**
     * Make the simulator of a plan: its database, then the files and the instance. The plan is shown and has to be
     * accepted, unless it was already ($accepted).
     *
     * @param array<string,mixed> $profile
     * @return ?array{0:string,1:string} the grid and the instance of the simulator configured, null when nothing was
     */
    public function complete(SimPlan $plan, GridInfo $grid, array $profile, bool $accepted): ?array
    {
        $etcRoot = $profile['EtcRoot'] ?? '';
        $database = new Database($this->ui);

        // Nothing is written before the database of the simulator works
        $check = $plan->databasePlan();
        $attempts = 0;
        while (($result = $database->ensure($check)) !== Database::OK) {
            if ($result === Database::ABORT) {
                $this->ui->error(_('Stopped, nothing was changed: OpenSim cannot run without its database.'));

                throw new SetupFailed('database');
            }
            // The error is on screen: the settings are asked again at once, they may be what is wrong
            if (++$attempts > 10) {
                $this->ui->error(_('Stopped, nothing was changed: OpenSim cannot run without its database.'));

                throw new SetupFailed('database');
            }
            $this->askDatabase($plan, $grid);
            $check = $plan->databasePlan();
        }

        if (!$accepted) {
            $this->showPlan($plan);
            if (!$this->ui->confirm(sprintf(_("Apply this configuration to simulator '%s'?"), $plan->simName), true)) {
                $this->ui->note(_('Aborted — nothing changed.'));

                return null;
            }
        }

        // A simulator already linked may run, with users in it: restarting it warns them, and takes two minutes
        $wasEnabled = SimState::isEnabled($etcRoot, $plan->slug);
        $plan->enable = $this->ui->confirm(sprintf(_("Enable simulator '%s' (link into opensim.d)?"), $plan->simName), true);
        $plan->start = $plan->enable && $this->ui->confirm(sprintf(_("Start simulator '%s' now?"), $plan->simName), true);
        $plan->warnUsers =
            $plan->start &&
            $wasEnabled &&
            Console::running(SimState::link($etcRoot, $plan->slug)) &&
            $this->ui->confirm(
                _('The simulator is running: warn its users and wait two minutes before restarting it?'),
                false,
            );

        $this->write($plan, $profile);

        return [$grid->nick, $plan->slug];
    }

    /** Write as the user who owns the install (see Elevated). */
    private function write(SimPlan $plan, array $profile): void
    {
        if (!Elevated::needed($profile)) {
            $this->apply($plan, $profile);

            return;
        }
        Elevated::run(
            $this->ui,
            '--apply-sim',
            ['plan' => $plan->toArray(), 'profile' => $profile],
            $profile['SystemUser'],
        );
    }

    /**
     * Add a region to a simulator already configured: loaded at once when the
     * simulator runs, else the simulator is started.
     */
    public function addRegion(string $gridNick, string $simName): void
    {
        $profile = (new Config())->profile();
        $grid = $this->grid($profile, $gridNick);
        $plan = $grid === null ? null : $this->regionPlan($grid, $simName);
        if ($plan === null) {
            return;
        }

        $this->askRegion($plan, $grid, new Database($this->ui));
        if (is_file($plan->regionIni())) {
            $this->ui->error(sprintf(_("A region is already described in %s."), $plan->regionIni()));

            return;
        }
        $this->ui->note(
            sprintf(_("Region %s at %s, port %s, for simulator '%s'."), $plan->regionName, $plan->regionLocation, $plan->regionPort, $simName),
        );
        if (!$this->ui->confirm(sprintf(_("Add region '%s'?"), $plan->regionName), true)) {
            $this->ui->note(_('Aborted — nothing changed.'));

            return;
        }
        $plan->start = $this->ui->confirm(_('Load it now (starting the simulator when it is not running)?'), true);
        $this->write($plan, $profile);
    }

    /** The plan of a region of a simulator already configured, null when it is not. */
    private function regionPlan(GridInfo $grid, string $simName): ?SimPlan
    {
        $plan = new SimPlan();
        $plan->gridNick = $grid->nick;
        $plan->gridName = $grid->name;
        $plan->gridDir = $grid->dir;
        $plan->hypergrid = $grid->hypergrid;
        $plan->logsDirectory = $grid->logsDirectory;
        $plan->simName = $simName;
        $plan->slug = GridInfo::instanceName($grid->nick . '_' . $simName);
        $plan->regionOnly = true;
        $plan->createRegion = true;
        if (!is_file($plan->iniPath())) {
            $this->ui->error(sprintf(_("Simulator '%s' is not configured."), $simName));

            return null;
        }
        $plan->httpPort = (int) (GridInfo::parse($plan->iniPath())['httpPort'] ?? 0);
        // The address the regions of this simulator already announce
        foreach (glob("{$plan->regionsDir()}/*.ini") ?: [] as $file) {
            if (preg_match('/^\s*ExternalHostName\s*=\s*(\S+)/m', (string) file_get_contents($file), $m)) {
                $plan->externalHost = $m[1];
                break;
            }
        }

        return $plan;
    }

    /**
     * Change the place and the port of a region that exists: its name and its UUID
     * are its identity in the grid, they stay. Taken into account when the simulator
     * starts again.
     */
    public function reconfigureRegion(string $gridNick, string $simName, string $regionName): void
    {
        $profile = (new Config())->profile();
        $grid = $this->grid($profile, $gridNick);
        $plan = $grid === null ? null : $this->regionPlan($grid, $simName);
        if ($plan === null) {
            return;
        }
        $region = RegionState::list($plan->regionsDir())[$regionName] ?? null;
        if ($region === null || !$region['enabled']) {
            $this->ui->warn(sprintf(_("Region '%s' is disabled or unknown: enable it first."), $regionName));

            return;
        }
        $current = RegionState::values($region['file']);

        $plan->regionName = $regionName;
        $plan->regionUuid = $current['RegionUUID'] ?? self::uuid();
        $plan->regionSize = (int) ($current['SizeX'] ?? 256);
        $plan->externalHost = $current['ExternalHostName'] ?? $plan->externalHost;
        $plan->overwriteRegion = true;
        $plan->start = false;

        $numeric = static fn(string $v): ?string => ctype_digit(trim($v)) ? null : _('Enter a port number.');
        $plan->regionLocation = $this->askLocation($grid, new Database($this->ui), $current['Location'] ?? null);
        $plan->regionPort = (int) $this->ui->text(
            _('Region port'),
            $current['InternalPort'] ?? (string) $plan->regionPort,
            $numeric,
        );

        if (
            !$this->ui->confirm(
                sprintf(_("Change region '%s' to %s, port %s?"), $regionName, $plan->regionLocation, $plan->regionPort),
                true,
            )
        ) {
            $this->ui->note(_('Aborted — nothing changed.'));

            return;
        }
        $this->write($plan, $profile);
        $this->ui->note(sprintf(_("The simulator '%s' restarts to apply it when you quit the setup, or now: opensim restart %s"), $simName, $plan->slug));
    }

    /**
     * The writing itself: run as the system user of the install, root, or the
     * user of an install without one.
     *
     * @param array<string,mixed> $profile
     */
    public function apply(SimPlan $plan, array $profile): void
    {
        $etcRoot = $profile['EtcRoot'];
        if ($plan->remoteGrid !== null) {
            $this->ui->note(sprintf(_('Wrote %s'), (new GridConf())->writeRemote($plan->remoteGrid)));
        }
        $grid = GridInfo::load($profile, $plan->gridNick);
        if ($grid === null) {
            $this->ui->error(sprintf(_("Grid '%s' not found."), $plan->gridNick));

            throw new SetupFailed('grid');
        }

        if ($plan->regionOnly) {
            $this->applyRegion($plan, $profile, $grid);
            SetupFile::record($plan->gridDir, static fn(array $data): array => SetupFile::withSim($data, $plan));

            return;
        }

        // First, while nothing is written: the account the estate needs
        if ($plan->createOwner) {
            $accounts = new GridAccounts(new Database($this->ui), $this->ui);
            if (!$accounts->create($grid, $plan->estateOwner, $plan->ownerPassword, $plan->ownerEmail)) {
                throw new SetupFailed('account');
            }
            $this->ui->note(sprintf(_("Account %s created in the grid '%s'."), $plan->estateOwner, $grid->nick));
        }

        foreach (
            [
                $plan->dataDirectory,
                "{$plan->dataDirectory}/registry",
                $plan->cacheDirectory,
                "{$plan->cacheDirectory}/maptiles",
                $plan->regionsDir(),
                $plan->logsDirectory,
            ]
            as $dir
        ) {
            if ($dir !== '' && !is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
        }

        foreach ((new GridShared())->prepare($plan->gridDir, $plan->binDir(), $plan->hypergrid) as $file) {
            $this->ui->note(sprintf(_("Wrote %s"), $file));
        }
        $this->ui->note(sprintf(_('Wrote %s'), (new SimConfig())->write($plan)));
        if ($plan->createRegion) {
            $region = (new SimConfig())->writeRegion($plan);
            $this->ui->note(
                $region !== null
                    ? sprintf(_('Wrote %s'), $region)
                    : sprintf(_('Region %s already described, left as it is.'), $plan->regionName),
            );
        }

        if ($plan->createRegion) {
            $this->giveRoles($plan, $grid);
        }

        SetupFile::record($plan->gridDir, static fn(array $data): array => SetupFile::withSim($data, $plan));

        $systemUser = $profile['SystemUser'] ?? '';
        $this->giveToSystemUser($systemUser, [$plan->gridDir, $plan->dataDirectory, $plan->cacheDirectory], true);

        if ($plan->enable) {
            if (SimState::enable($etcRoot, $plan->slug, $plan->iniPath())) {
                $this->giveToSystemUser($systemUser, [SimState::link($etcRoot, $plan->slug)], false);
                $this->ui->note(sprintf(_('Enabled: %s'), SimState::link($etcRoot, $plan->slug)));
                if ($plan->start) {
                    $this->startSim($plan, $grid, $profile);
                }
            } else {
                $this->ui->warn(_('Could not enable the simulator (config missing).'));
            }
        }

        $this->ui->note(sprintf(_("Simulator '%s' configured."), $plan->simName));
    }

    /**
     * Write the region, and load it in the simulator through its console, which does not restart
     * it. The simulator is started when it is not running.
     */
    private function applyRegion(SimPlan $plan, array $profile, GridInfo $grid): void
    {
        @mkdir($plan->regionsDir(), 0o755, true);
        $region = (new SimConfig())->writeRegion($plan);
        $this->ui->note(
            $region !== null
                    ? sprintf(_('Wrote %s'), $region)
                    : sprintf(_('Region %s already described, left as it is.'), $plan->regionName),
        );
        $this->giveToSystemUser($profile['SystemUser'] ?? '', [$plan->regionsDir()], true);
        $this->giveRoles($plan, $grid);
        if ($plan->overwriteRegion) {
            $this->needRestart($profile, $plan, 'changed');
        }
        if (!$plan->start) {
            return;
        }

        if (!Console::running(SimState::link($profile['EtcRoot'], $plan->slug))) {
            $this->startSim($plan, $grid, $profile);

            return;
        }

        // Robust reads the roles of its regions when it starts
        if ($plan->regionRoles !== [] && !$grid->remote) {
            $this->restartGrid($grid);
        }
        // The questions of a region with no estate are answered with their default (the estate it has)
        $file = basename($plan->regionIni());
        if (!Console::send($plan->slug, "create region \"{$plan->regionName}\" $file\n\n\n")) {
            $this->failed(
                $plan,
                sprintf(_("The simulator '%s' did not take the region %s through its console. It is described, and loaded the next time the simulator starts: opensim restart %s"), $plan->simName, $plan->regionName, $plan->slug),
            );
        }
        if (!$this->registered($plan, $grid)) {
            $this->failed($plan, sprintf(_("Region %s did not register in the grid '%s'."), $plan->regionName, $grid->nick));
        }
        $this->giveHome($plan, $grid);
        $this->initRegion($plan);
        $this->ui->note(sprintf(_("Region %s is online."), $plan->regionName));
    }

    /**
     * The archive of the initialization object: the one the package has, else (a checkout of the
     * repository) made from its sources, which the simulator, running as another user, must read.
     */
    private function initArchive(): ?string
    {
        $dir = dirname(__DIR__, 3) . '/share/ossl-scripts';
        if (is_file("$dir/fix-parcel-name.oar")) {
            return "$dir/fix-parcel-name.oar";
        }
        if (!is_file("$dir/fix-parcel-name-src/archive.xml")) {
            return null;
        }
        defined('OPENSIM_ENGINE') || define('OPENSIM_ENGINE', true);
        $archive = sys_get_temp_dir() . '/opensim-kit-fix-parcel-name-' . getmypid() . '.oar';
        try {
            \OpenSim_Oar::pack("$dir/fix-parcel-name-src", $archive);
        } catch (\RuntimeException $e) {
            $this->ui->warn($e->getMessage());

            return null;
        }
        chmod($archive, 0o644);

        return $archive;
    }

    /**
     * What a new region gets once it runs: the object of share/ossl-scripts, loaded through the console of the
     * simulator, its script names the parcel after the region. --merge leaves the terrain, the parcels and the
     * objects of the region alone; the objects of the archive go to the estate owner, and to the middle of the
     * region (the archive was saved with the object in the middle of a 256 m one).
     *
     * Not fatal: a region without it is a region whose parcel is named "Your Parcel".
     */
    private function initRegion(SimPlan $plan): void
    {
        $archive = $this->initArchive();
        if ($archive === null || RegionName::problem($plan->regionName) !== null) {
            return;
        }
        $shift = (int) ($plan->regionSize / 2) - 128;
        $sent = Console::send(
            $plan->slug,
            "change region \"{$plan->regionName}\"\n"
            . "load oar --merge --default-user \"{$plan->estateOwner}\" --displacement \"<$shift,$shift,0>\" $archive\n",
        );
        if (!$sent) {
            $this->ui->warn(sprintf(_("The simulator did not take the initialization of region %s."), $plan->regionName));

            return;
        }

        // The database of the simulator, as its config says (a region added to a simulator knows no more)
        $conf = GridInfo::parse($plan->iniPath());
        $db = new GridPlan();
        $db->dbHost = (string) ($conf['dbHost'] ?? $plan->dbHost);
        $db->dbName = (string) ($conf['dbName'] ?? $plan->dbName);
        $db->dbUser = (string) ($conf['dbUser'] ?? $plan->dbUser);
        $db->dbPass = (string) ($conf['dbPass'] ?? $plan->dbPass);
        $database = new Database($this->ui);
        $uuid = $plan->regionUuid;
        for ($try = 0; $try < 30; $try++) {
            $names = $database->select($db, "SELECT Name FROM land WHERE RegionUUID = '$uuid'") ?? [];
            if ($names !== [] && !in_array('Your Parcel', $names, true)) {
                $this->ui->note(sprintf(_('Parcel of region %s is named %s.'), $plan->regionName, implode(', ', $names)));

                return;
            }
            sleep(1);
        }
        $this->ui->warn(sprintf(_('The parcel of region %s is not named yet: its initialization object may still be starting.'), $plan->regionName));
    }

    /** Give the roles asked to the region in the Robust config of the grid. */
    private function giveRoles(SimPlan $plan, GridInfo $grid): void
    {
        if ($plan->regionRoles === [] || $grid->remote || $grid->robustIni === '') {
            return;
        }
        RegionFlags::give($grid->robustIni, $plan->regionName, $plan->regionRoles);
        $this->ui->note(
            sprintf(_('Updated %s: %s is %s.'), $grid->robustIni, $plan->regionName, implode(', ', $plan->regionRoles)),
        );
    }

    /**
     * The owner of the estate gets the default region as home: Robust sets the home of an account
     * when it is made, and the default region did not exist yet for this one.
     */
    private function giveHome(SimPlan $plan, GridInfo $grid): void
    {
        if ($grid->remote || !in_array(RegionFlags::DEFAULT, $plan->regionRoles, true)) {
            return;
        }
        $owner = $plan->estateOwner !== '' ? $plan->estateOwner : (string) (GridInfo::parse($plan->iniPath())['estateOwner'] ?? '');
        if ($owner === '') {
            return;
        }
        $done = (new GridAccounts(new Database($this->ui), $this->ui))->setHome($grid, $owner, $plan->regionUuid);
        if ($done !== true) {
            $this->ui->warn(sprintf(_("Could not set the home of %s: set it from the viewer, or the first login ends with an error."), $owner));
        }
    }

    /** Robust gives the roles to a region when it registers, from its config read when it starts. */
    private function restartGrid(GridInfo $grid): void
    {
        $opensim = dirname(__DIR__, 3) . '/bin/opensim';
        [$code] = System::runShown(System::arg($opensim) . ' restart now ' . System::arg($grid->nick));
        if ($code !== 0) {
            $this->ui->warn(sprintf(_("The grid '%s' did not restart: try %s -v restart now %s"), $grid->nick, $opensim, $grid->nick));
        }
    }

    /** The simulator has to restart to take the region into account (its parcel is named then, see Actions). */
    private function needRestart(array $profile, SimPlan $plan, string $why): void
    {
        PendingRestarts::add($profile, $plan->slug, "region {$plan->regionName} $why");
    }

    /**
     * Start the simulator and report whether it came up: the launcher's
     * answer, then, for a new region, its registration in the grid.
     */
    private function startSim(SimPlan $plan, GridInfo $grid, array $profile): void
    {
        $opensim = dirname(__DIR__, 3) . '/bin/opensim';

        // A region registers with the grid when it starts: the grid has to run (when
        // it is ours: a Robust on another machine is its administrator's)
        if (!$grid->remote) {
            // Robust reads the roles of its regions when it starts: it starts again to give them
            $action = $plan->regionRoles !== [] ? 'restart now' : 'start';
            [$code] = System::runShown(System::arg($opensim) . " $action " . System::arg($grid->nick));
            if ($code !== 0) {
                $this->failed(
                    $plan,
                    sprintf(_("The grid '%s' is not running, and a simulator cannot start without it. Enable and start it first: %s -v start %s"), $grid->nick, $opensim, $grid->nick),
                );
            }
        }

        // The simulator restarts at once, unless its users are to be warned first (see opensim restart)
        $now = $plan->warnUsers ? '' : 'now ';
        [$code, $said] = System::runShown(System::arg($opensim) . " restart $now" . System::arg($plan->slug));
        $pending = $code === 0 && str_contains($said, 'still starting');
        if ($code === 0 && !$pending) {
            if ($plan->createRegion && !$grid->remote && !$this->registered($plan, $grid)) {
                $this->failed(
                    $plan,
                    sprintf(_("Simulator '%s' runs, but the region %s did not register in the grid '%s'."), $plan->simName, $plan->regionName, $grid->nick),
                );
            }
            if ($plan->createRegion) {
                $this->giveHome($plan, $grid);
                $this->initRegion($plan);
            }
            $this->ui->note(
                !$plan->createRegion
                    ? sprintf(_("Simulator '%s' is running."), $plan->simName)
                    : ($grid->remote
                        ? sprintf(_("Simulator '%s' is running, region %s started: see the grid, on its map, for its registration."), $plan->simName, $plan->regionName)
                        : sprintf(_("Simulator '%s' is running, region %s is online."), $plan->simName, $plan->regionName)),
            );

            return;
        }

        $this->failed(
            $plan,
            $pending
                ? "Simulator '{$plan->simName}' is configured but was not ready after two minutes. Try: $opensim -v start {$plan->slug}"
                : "Simulator '{$plan->simName}' is configured but did not start. Try: $opensim -v start {$plan->slug}",
        );
    }

    private function failed(SimPlan $plan, string $message): never
    {
        $this->ui->error($message);
        $log = $plan->logsDirectory . '/' . $plan->slug . '.log';
        if (is_file($log)) {
            $this->ui->note(_('Recent log:'));
            System::run('tail -n 30 ' . System::arg($log));
        }

        throw new SetupFailed($message);
    }

    /** Whether the grid knows the region, waiting a little for it to tell. */
    private function registered(SimPlan $plan, GridInfo $grid): bool
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $plan->regionName)) {
            return true; // cannot be looked for
        }
        $database = new Database($this->ui);
        for ($i = 0; $i < 60; $i++) {
            $rows = $database->select(
                $grid->databasePlan(),
                "SELECT 1 FROM regions WHERE regionName = '{$plan->regionName}'",
            );
            if ($rows === null) {
                return true; // the grid database cannot be read: not a reason to call it a failure
            }
            if ($rows !== []) {
                return true;
            }
            sleep(1);
        }

        return false;
    }

    /**
     * When the setup runs as root for an install with a system user (the
     * packages), what it creates belongs to that user, who runs the instances.
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
     * The grid the simulator joins: the given one, the only one, or a choice among
     * the known ones and "another grid", whose Robust is on another machine. With
     * no grid at all, the question is the address of that Robust.
     */
    private function grid(array $profile, ?string $nick, bool $external = false): ?GridInfo
    {
        $etcRoot = $profile['EtcRoot'];
        if ($external) {
            return $this->remoteGrid($profile);
        }
        if ($nick === null) {
            $known = [];
            foreach (glob("$etcRoot/grids/*", GLOB_ONLYDIR) ?: [] as $dir) {
                $name = basename($dir);
                if (GridState::robustIni($etcRoot, $name) !== null) {
                    $known[$name] = $name;
                } elseif (GridInfo::isRemote($etcRoot, $name)) {
                    $known[$name] = "$name (Robust on another machine)";
                }
            }
            if ($known === []) {
                return $this->remoteGrid($profile);
            }
            $known['+'] = 'Another grid, whose Robust is on another machine';
            $nick = $this->ui->choose(_('Grid of the simulator'), $known, (string) array_key_first($known));
            if ($nick === '+') {
                return $this->remoteGrid($profile);
            }
        }

        $grid = GridInfo::load($profile, $nick);
        if ($grid === null) {
            $this->ui->error(sprintf(_("Grid '%s' is not known here."), $nick));
        }

        return $grid;
    }

    /**
     * A grid whose Robust runs elsewhere (another machine, another container): only
     * what a simulator needs to join it is asked, and kept for the next ones. The
     * grid tells its name and nick itself.
     */
    private function remoteGrid(array $profile): ?GridInfo
    {
        $required = static fn(string $v): ?string => trim($v) === '' ? 'This field is required.' : null;
        $numeric = static fn(string $v): ?string => ctype_digit(trim($v)) ? null : _('Enter a port number.');
        $etcRoot = $profile['EtcRoot'];

        $this->ui->note(
            _('The simulator joins a grid whose Robust server runs on another machine (or in another container): it needs its address and its ports.'),
        );
        $address = trim($this->ui->text(_('Address of the grid (host:port)'), '', $required));
        $parts = parse_url(preg_match('#^https?://#', $address) ? $address : "http://$address") ?: [];
        $host = (string) ($parts['host'] ?? $address);
        $public = (int) ($parts['port'] ?? 8002);

        $said = $this->gridSays($host, $public);
        if ($said === []) {
            $this->ui->warn(
                sprintf(_("The grid did not answer at http://%s:%s/get_grid_info: check its address and its public port, or go on with what you know of it."), $host, $public),
            );
        }
        $name = trim($this->ui->text(_('Grid name'), $said['gridname'] ?? ucfirst(explode('.', $host)[0]), $required));
        // Letters and digits only, as typed (nick() would lower what is already a nick)
        $nick = (string) preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            $this->ui->text(_('Grid nick (snake_case)'), $said['gridnick'] ?? Slug::nick($name), $required),
        );
        if ($nick === '') {
            $nick = Slug::nick($name);
        }

        if (GridState::robustIni($etcRoot, $nick) !== null) {
            $this->ui->error(
                sprintf(_("A grid of this machine already has the nick '%s': it is its own Robust, not another one."), $nick),
            );

            return null;
        }
        if (GridInfo::isRemote($etcRoot, $nick)) {
            $this->ui->note(sprintf(_("Grid '%s' is already known here, its description is kept."), $nick));

            return GridInfo::load($profile, $nick);
        }

        $private = (int) $this->ui->text(
            _('Private port of the grid (only for its simulators, and this machine is one)'),
            (string) ($public + 1),
            $numeric,
        );
        $hypergrid = $this->ui->confirm(_('Does the grid use Hypergrid?'), true);

        $grid = new GridInfo();
        $grid->remote = true;
        $grid->nick = $nick;
        $grid->name = $name;
        $grid->slug = Slug::slug($name);
        $grid->dir = "$etcRoot/grids/$nick";
        $grid->hypergrid = $hypergrid;
        $grid->baseHostname = $host;
        $grid->publicPort = $public;
        $grid->privatePort = $private;
        $grid->coreDirectory = $profile['CoreDirectory'] ?? '';
        $grid->dataDirectory = ($profile['DataRoot'] ?? '') . "/$nick";
        $grid->cacheDirectory = ($profile['CacheRoot'] ?? '') . "/$nick";
        $grid->logsDirectory = $profile['LogsRoot'] ?? '';

        return $grid;
    }

    /**
     * What a grid says of itself at its public port (get_grid_info).
     *
     * @return array<string,string> gridname, gridnick..., empty when it does not answer
     */
    private function gridSays(string $host, int $port): array
    {
        $body = @file_get_contents(
            "http://$host:$port/get_grid_info",
            false,
            stream_context_create(['http' => ['timeout' => 5]]),
        );
        $said = [];
        foreach (['gridname', 'gridnick'] as $key) {
            if ($body !== false && preg_match("#<$key>([^<]+)</$key>#", $body, $m)) {
                $said[$key] = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1));
            }
        }

        return $said;
    }

    private function gather(GridInfo $grid, array $profile, Database $database, ?string $simName): ?SimPlan
    {
        $required = static fn(string $v): ?string => trim($v) === '' ? 'This field is required.' : null;
        $numeric = static fn(string $v): ?string => ctype_digit(trim($v)) ? null : _('Enter a port number.');

        $plan = new SimPlan();
        $plan->gridNick = $grid->nick;
        $plan->gridName = $grid->name;
        $plan->gridDir = $grid->dir;
        $plan->hypergrid = $grid->hypergrid;
        $plan->baseHostname = $grid->baseHostname;
        $plan->publicPort = $grid->publicPort;
        $plan->privatePort = $grid->privatePort;
        $plan->logsDirectory = $grid->logsDirectory;
        // A remote grid not kept yet is written with the simulator
        $plan->remoteGrid = $grid->remote && !is_file("{$grid->dir}/{$grid->nick}.conf") ? $grid->describe() : null;

        // The first simulator of a grid has the name of its landing region, that is its first region
        $firstSim = !$grid->remote && SimState::names($grid->dir) === [];
        // An accent is not refused, it is transliterated (Noël becomes Noel)
        $simNameProblem = static fn(string $v): ?string => preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9 _-]*$/',
            trim(Slug::ascii($v)),
        )
            ? null
            : _('Letters, digits, spaces, _ and - only.');
        if ($simName === null && $grid->needsLandingRegion()) {
            $this->ui->note(self::LANDING);
        }
        $plan->simName =
            $simName ??
            Slug::ascii(
                trim(
                $this->ui->text(
                    _('Simulator name'),
                    $firstSim
                        ? self::FIRST
                        : RandomName::make(
                            fn(string $v): ?string => $simNameProblem($v) ??
                                (is_file("{$grid->dir}/sims/" . GridInfo::instanceName("{$grid->nick}_$v") . '.ini')
                                    ? 'taken'
                                    : null),
                        ),
                    $simNameProblem,
                ),
            ),
            );
        $plan->slug = GridInfo::instanceName($grid->nick . '_' . $plan->simName);

        $current = [];
        if (is_file($plan->iniPath())) {
            // Named by the caller: it is the one to modify, no need to ask
            $action =
                $simName !== null
                    ? 'modify'
                    : $this->ui->choose(
                        sprintf(_("Simulator '%s' is already configured (%s)."), $plan->simName, $plan->iniPath()),
                        [
                            'modify' => _('Modify its settings (its regions are kept)'),
                            'abandon' => _('Abandon, keep it unchanged'),
                        ],
                        'modify',
                    );
            if ($action === 'abandon') {
                $this->ui->note(_('Left unchanged.'));

                return null;
            }
            $current = GridInfo::parse($plan->iniPath());
        }

        $plan->dataDirectory = "{$grid->dataDirectory}/{$plan->slug}";
        $plan->cacheDirectory = "{$grid->cacheDirectory}/{$plan->slug}";

        $cores = Cores::list($profile['CoreRoot'] ?? '');
        if ($cores === []) {
            $this->ui->error(_('No OpenSim core found.'));

            return null;
        }
        $plan->coreDirectory = $this->ui->choose(
            _('OpenSim core to run this simulator'),
            $cores,
            $grid->coreDirectory !== '' ? $grid->coreDirectory : null,
        );

        // The ports of a simulator are a block of ten, the first free one from where the
        // simulators of the grid start (9000 for Robust on 8002), on this machine and among
        // the regions the grid knows on the others: its HTTP port is the first one, the
        // console is x4, the regions take the others
        $foreign = (new GridRegistry($database))->ports($grid) ?? [];
        $block = Ports::nextBlock(Ports::simulatorsFrom($grid->publicPort), $foreign);
        // What the regions announce as their address, the viewers connect to it: the
        // public name of this machine. SYSTEMIP is the address of its first interface,
        // which is not the one of the world behind a NAT or in a container.
        $v = $this->ui->form([
            [
                'key' => 'port',
                'label' => _('Simulator HTTP port'),
                'default' => (string) ($current['httpPort'] ?? $block),
                'validate' => $numeric,
            ],
            [
                'key' => 'host',
                'label' => _('Public address of this machine'),
                'default' => $current['externalHost'] ?? 'SYSTEMIP',
                'hint' => _('What the regions announce to the viewers (SYSTEMIP: the first interface)'),
            ],
        ], _('Simulator'));
        $plan->httpPort = (int) $v['port'];
        $base = Ports::simulatorBlock($plan->httpPort);
        $plan->consolePort = $base !== null ? $base + 4 : 0;
        $plan->externalHost = trim($v['host']);

        $this->askConsole($plan, $current);

        // Its own database: the account of the grid, a database of its own
        // The account of the first simulator of the grid is the one of the next ones, and so is its password
        $account = $this->siblingAccount($grid, $plan->slug);
        $this->askDatabase($plan, $grid, [
            'dbHost' => $current['dbHost'] ?? $account['dbHost'] ?? $grid->dbHost,
            'dbName' => $current['dbName'] ?? $plan->slug,
            'dbUser' => $current['dbUser'] ?? $account['dbUser'] ?? $grid->dbUser,
            'dbPass' => $current['dbPass'] ?? $account['dbPass'] ?? ($grid->dbPass !== '' ? $grid->dbPass : self::password(20)),
        ]);

        // The helpers of the grid, when it has some: offline messages and the search index
        $plan->offlineUrl = $grid->helperUrl('offline');
        $plan->registerUrl = $grid->helperUrl('register');

        $plan->estateName = trim(
            $this->ui->text(_('Estate name'), $current['estateName'] ?? "{$grid->name} Estate", $required),
        );
        if (!$this->askOwner($plan, $grid, $database, $current['estateOwner'] ?? null)) {
            return null;
        }

        $hasRegions = (glob("{$plan->regionsDir()}/*.ini") ?: []) !== [];
        $plan->createRegion = !$hasRegions && $this->ui->confirm(_('Create the first region of this simulator?'), true);
        if ($plan->createRegion) {
            $this->askRegion($plan, $grid, $database);
        }

        return $plan;
    }

    /**
     * The console of the simulator: remote (REST, through its port x4) or a screen
     * session. What an existing config has is kept, password included.
     *
     * @param array<string,string|int> $current
     */
    private function askConsole(SimPlan $plan, array $current): void
    {
        $plan->consoleMode = $this->ui->choose(
            _('Console of the simulator'),
            [
                'rest' => _('Remote REST console (recommended)'),
                'screen' => _('Screen session (on this machine)'),
            ],
            isset($current['consoleUser']) || $current === [] ? 'rest' : 'screen',
        );
        if ($plan->consoleMode !== 'rest') {
            return;
        }

        if ($plan->consolePort === 0) {
            $plan->consolePort = (int) $this->ui->text(
                _('Console port'),
                (string) ($current['consolePort'] ?? Ports::next($plan->httpPort + 1)),
                static fn(string $v): ?string => ctype_digit(trim($v)) ? null : _('Enter a port number.'),
            );
        } else {
            $plan->consolePort = (int) ($current['consolePort'] ?? $plan->consolePort);
        }
        // As the helpers make theirs: 12 lower case letters, 32 letters and digits; the host
        // clients reach it by is the public address of the machine, when it is known
        $plan->consoleHost =
            (string) ($current['consoleHost'] ?? ($plan->externalHost !== 'SYSTEMIP' ? $plan->externalHost : ''));
        $plan->consoleUser = (string) ($current['consoleUser'] ?? self::letters(12));
        $plan->consolePass = (string) ($current['consolePass'] ?? self::password(32));
    }

    private static function letters(int $length): string
    {
        $letters = '';
        for ($i = 0; $i < $length; $i++) {
            $letters .= chr(random_int(97, 122));
        }

        return $letters;
    }

    private static function password(int $length = 24): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    /**
     * The database settings, with these defaults (those of the grid's account
     * when none is given).
     *
     * @param ?array{dbHost:string,dbName:string,dbUser:string,dbPass:string} $defaults
     */
    /**
     * The database account another simulator of the grid uses (host, user, password), when it has any.
     *
     * @return array{dbHost?:string,dbUser?:string,dbPass?:string}
     */
    private function siblingAccount(GridInfo $grid, string $slug): array
    {
        foreach (glob("{$grid->dir}/sims/*.ini") ?: [] as $ini) {
            if (basename($ini, '.ini') === $slug) {
                continue;
            }
            $found = GridInfo::parse($ini);
            if (($found['dbUser'] ?? '') !== '' && ($found['dbPass'] ?? '') !== '') {
                return array_intersect_key($found, array_flip(['dbHost', 'dbUser', 'dbPass']));
            }
        }

        return [];
    }

    private function askDatabase(SimPlan $plan, GridInfo $grid, ?array $defaults = null): void
    {
        $required = static fn(string $v): ?string => trim($v) === '' ? 'This field is required.' : null;
        $defaults ??= [
            'dbHost' => $plan->dbHost,
            'dbName' => $plan->dbName,
            'dbUser' => $plan->dbUser,
            'dbPass' => $plan->dbPass,
        ];

        // An account keeps its password for the whole session: the one of the grid's account is proposed
        $password = Database::recall($defaults['dbHost'], $defaults['dbUser']) ?? $defaults['dbPass'];
        $v = $this->ui->form([
            ['key' => 'host', 'label' => _('Database host'), 'default' => $defaults['dbHost']],
            ['key' => 'name', 'label' => _('Database name'), 'default' => $defaults['dbName']],
            ['key' => 'user', 'label' => _('Database user'), 'default' => $defaults['dbUser']],
            ['key' => 'pass', 'label' => _('Database password'), 'default' => $password],
        ], _('Database'));
        [$plan->dbHost, $plan->dbName, $plan->dbUser, $plan->dbPass] = [$v['host'], $v['name'], $v['user'], $v['pass']];
        Database::remember($plan->dbHost, $plan->dbUser, $plan->dbPass);
    }

    /**
     * The owner of the estate: an account of the grid, chosen or created.
     *
     * @return bool false when abandoned
     */
    private function askOwner(SimPlan $plan, GridInfo $grid, Database $database, ?string $current): bool
    {
        $name = static fn(string $v): ?string => GridAccounts::validName(trim($v))
            ? null
            : _('First and last name, e.g. Jane Doe.');
        $accounts = $grid->remote ? null : (new GridAccounts($database, $this->ui))->names($grid);

        if ($accounts === null && $grid->remote) {
            $this->ui->note(
                sprintf(_("The estate is owned by an account of the grid '%s', which has to exist already (its administrator makes it on its Robust)."), $grid->nick),
            );
            $plan->estateOwner = trim($this->ui->text(_('Estate owner (an account of the grid)'), $current ?? '', $name));

            return true;
        }
        if ($accounts === null) {
            // The database of the grid cannot be read: its account is taken on trust
            $this->ui->warn(
                sprintf(_("The accounts of the grid '%s' cannot be read: the owner must be an existing account."), $grid->nick),
            );
            $plan->estateOwner = trim($this->ui->text(_('Estate owner (an account of the grid)'), $current ?? '', $name));

            return true;
        }

        $choices = array_combine($accounts, $accounts);
        if ($choices !== []) {
            $choices['+'] = 'Create a new account in the grid';
            $choice = $this->ui->choose(
                _('Estate owner'),
                $choices,
                $current !== null && isset($choices[$current]) ? $current : array_key_first($choices),
            );
            if ($choice !== '+') {
                $plan->estateOwner = $choice;

                return true;
            }
        } else {
            $this->ui->note(
                sprintf(_("The grid '%s' has no account yet: the owner of the estate is the first one to create."), $grid->nick),
            );
        }

        $plan->estateOwner = trim($this->ui->text(_('Name of the new account (First Last)'), '', $name));
        if (
            $this->ui->confirm(
                sprintf(_("Account %s does not exist yet, create it in the grid '%s'?"), $plan->estateOwner, $grid->nick),
                true,
            ) === false
        ) {
            $this->ui->note(_('Aborted — nothing changed.'));

            return false;
        }
        $plan->createOwner = true;
        $plan->ownerPassword = $this->ui->secret(
            _('Password of the new account'),
            static fn(string $v): ?string => preg_match('/^[^"\r\n]{6,}$/', $v)
                ? null
                : _('At least 6 characters, without double quote.'),
        );
        $plan->ownerEmail = trim(
            $this->ui->text(
                _('Email of the new account (optional)'),
                '',
                // OpenSimulator does not need one, no more than the setup
                static fn(string $v): ?string => trim($v) === '' || preg_match('/^[^\s"\'\\\\]+@[^\s"\'\\\\]+$/', trim($v))
                    ? null
                    : _('An email address, or nothing.'),
            ),
        );

        return true;
    }

    /**
     * The roles no region of the grid has yet: the default region is checked, so is the one of
     * Hypergrid visitors when the grid has Hypergrid, the fallback is not.
     *
     * @return list<string>
     */
    private function askRoles(GridInfo $grid): array
    {
        $missing = $grid->missingRoles();
        if ($missing === []) {
            return [];
        }
        $labels = [
            RegionFlags::DEFAULT => 'set as Default Region',
            RegionFlags::DEFAULT_HG => 'set as Default HG Region',
            RegionFlags::FALLBACK => 'set as Fallback Region',
        ];
        $options = [];
        foreach ($missing as $role) {
            $options[$role] = $labels[$role];
        }

        return $this->ui->checklist(
            _('Role of this region in the grid'),
            $options,
            array_values(array_diff($missing, [RegionFlags::FALLBACK])),
        );
    }

    private function askRegion(SimPlan $plan, GridInfo $grid, Database $database): void
    {
        // A region name is unique in the grid: the same name registered twice stops the simulator
        $taken = (new GridRegistry($database))->names($grid);
        $name = static function (string $v) use ($taken, $grid): ?string {
            $v = Slug::ascii($v); // an accent is not refused, it is transliterated (Noël becomes Noel)
            if (($problem = RegionName::problem($v)) !== null) {
                return $problem;
            }
            if (
                isset($taken[strtolower(trim($v))]) ||
                ($grid->remote && RobustGrid::hasRegion($grid->baseHostname, $grid->privatePort, trim($v)) === true)
            ) {
                return 'The grid has a region of this name already.';
            }

            return null;
        };
        $numeric = static fn(string $v): ?string => ctype_digit(trim($v)) ? null : _('Enter a port number.');

        // A grid run from this machine needs a landing region: until it exists nobody can log in
        if ($grid->needsLandingRegion()) {
            $this->ui->note(self::LANDING);
        }
        // The name of the simulator is the one of its first region; the next ones are not called the same
        $first = (glob("{$plan->regionsDir()}/*.ini*") ?: []) === [];
        $default = $first && $name($plan->simName) === null ? $plan->simName : RandomName::make($name);
        $plan->regionName = Slug::ascii(trim($this->ui->text(_('Region name'), $default, $name)));
        $plan->regionRoles = $this->askRoles($grid);
        $plan->regionUuid = self::uuid();
        $plan->regionLocation = $this->askLocation($grid, $database);
        $plan->regionPort = (int) $this->ui->text(
            _('Region port'),
            (string) $this->nextRegionPort($plan, $grid, $database),
            $numeric,
        );
    }

    /**
     * The place of the region: the one asked (the first place of a grid by default),
     * or the free place nearest to it by the rule of the grid, the free blocks it
     * leaves between its regions.
     */
    private function askLocation(GridInfo $grid, Database $database, ?string $current = null): string
    {
        $known = (new GridRegistry($database))->locations($grid);
        // A region that moves frees the place it holds
        $ignored =
            $current !== null && ($place = LocationFinder::parse($current)) !== null
                ? LocationFinder::key($place[0], $place[1])
                : null;
        if ($ignored !== null) {
            unset($known[$ignored]);
        }
        $validate = static fn(string $v): ?string => LocationFinder::parse($v) === null
            ? 'Use x,y (e.g. 8002,8002).'
            : null;

        while (true) {
            // The place proposed is the first free one from the first place of a grid, not always the same
            $first = $current ?? implode(',', $this->freePlace($grid, $known, ...LocationFinder::first($grid->publicPort)));
            [$x, $y] =
                LocationFinder::parse($this->ui->text(_('Region location (x,y)'), $first, $validate)) ??
                LocationFinder::first($grid->publicPort);
            [$freeX, $freeY] = $this->freePlace($grid, $known, $x, $y, $ignored);
            if ($freeX === $x && $freeY === $y) {
                return "$x,$y";
            }
            $why =
                $grid->regionSpacing > 0
                    ? "taken or too close to a region (the grid leaves {$grid->regionSpacing} free block(s) between them)"
                    : 'taken';
            $this->ui->note(sprintf(_("The place %s,%s is %s: the nearest free one is %s,%s."), $x, $y, $why, $freeX, $freeY));
            if ($this->ui->confirm(sprintf(_("Use %s,%s?"), $freeX, $freeY), true)) {
                return "$freeX,$freeY";
            }
        }
    }

    /**
     * The free place nearest to a place, by the rule of the grid (see Places).
     *
     * @param array<string,true> $known the places taken that are known here
     * @return array{0:int,1:int}
     */
    private function freePlace(GridInfo $grid, array $known, int $x, int $y, ?string $ignored = null): array
    {
        $unreachable = false;
        $place = Places::nearestFree($grid, $known, $x, $y, $ignored, $unreachable);
        if ($unreachable) {
            $this->ui->warn(
                sprintf(_("The grid does not answer at %s:%s: its regions are not known here, ask its owner which place is free."), $grid->baseHostname, $grid->privatePort),
            );
        }

        return $place;
    }

    /** The port of a region: the next one of the block of its simulator, else the next free one. */
    private function nextRegionPort(SimPlan $plan, GridInfo $grid, Database $database): int
    {
        $taken = array_merge((new GridRegistry($database))->ports($grid) ?? [], [$plan->httpPort]);

        return Ports::nextRegion($plan->httpPort, $taken) ?? Ports::next($plan->httpPort + 1, $taken);
    }

    /** A random UUID (version 4). */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function showPlan(SimPlan $plan): void
    {
        $this->ui->note($this->planText($plan));
    }

    private function planText(SimPlan $plan): string
    {
        $lines = [
            "  Simulator:   {$plan->simName}  ({$plan->slug})",
            "  Grid:        {$plan->gridName}  ({$plan->gridNick})",
            "  Core:        {$plan->coreDirectory}",
            "  HTTP port:   {$plan->httpPort}",
            '  Console:     ' .
            ($plan->consoleMode === 'rest'
                ? "remote, port {$plan->consolePort}, user {$plan->consoleUser}"
                : 'screen session'),
            "  Database:    {$plan->dbName} @ {$plan->dbHost} (user {$plan->dbUser})",
            "  Estate:      {$plan->estateName}, owner {$plan->estateOwner}" .
            ($plan->createOwner ? ' (account to create)' : ''),
        ];
        if ($plan->createRegion) {
            $lines[] = "  Region:      {$plan->regionName} at {$plan->regionLocation}, port {$plan->regionPort}";
            if ($plan->regionRoles !== []) {
                $lines[] = '  Roles:       ' . implode(', ', $plan->regionRoles);
            }
        }
        $lines[] = "  Config:      {$plan->iniPath()}";
        return _('Simulator plan:') . "\n" . implode("\n", $lines);
    }
}
