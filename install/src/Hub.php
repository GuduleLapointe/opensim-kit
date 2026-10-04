<?php

declare(strict_types=1);

namespace OpenSim\Installer;

use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Grid\GridState;
use OpenSim\Installer\Grid\NewGrid;
use OpenSim\Installer\Grid\NewSim;
use OpenSim\Installer\Grid\RegionState;
use OpenSim\Installer\Grid\SimConfig;
use OpenSim\Installer\Grid\SimState;
use OpenSim\Installer\Setup\QuickSetup;
use OpenSim\Installer\Setup\SetupRunner;
use OpenSim\Installer\Ui\Back;
use OpenSim\Installer\Ui\EditConfig;
use OpenSim\Installer\Ui\InstallerUi;
use OpenSim\Installer\Ui\Quit;

/**
 * Setup hub: screens one level deeper each time, from the home to a region:
 *
 *   home          the core, the grids, add a grid
 *   grid          configure, its simulators, add a simulator, enable or disable
 *   simulator     reconfigure, enable or disable, its regions, add a region
 *   region        reconfigure, enable or disable
 *
 * Back goes up one level, Quit leaves the setup from any of them. A grid whose Robust is on another machine is only a
 * list of simulators here.
 *
 * The flow talks only to an InstallerUi, so a web frontend can render the same
 * screens later.
 */
final class Hub
{
    public function __construct(private InstallerUi $ui) {}

    public function run(): void
    {
        while (true) {
            $config = new Config();
            $cores = $config->profiles();
            $activeCore = $config->defaultProfile();
            $etcRoot = $config->profile()['EtcRoot'] ?? '';
            $grids = $this->grids($etcRoot);

            $options = ['core' => 'OpenSim core ' . ($cores !== [] ? "[$activeCore]" : '(install)')];
            foreach ($grids as $nick) {
                $name = $this->ui->entity($nick);
                $options["grid:$nick"] = GridInfo::isRemote($etcRoot, $nick)
                    ? "$name (Robust on another machine)"
                    : $name . (GridState::isEnabled($etcRoot, $nick) ? '' : ' [disabled]');
            }
            $options['add'] = _('Add grid');
            $options['quit'] = _('Quit');

            $default = $cores === [] ? 'core' : ($grids === [] ? 'add' : "grid:{$grids[0]}");
            $choice = $this->ui->choose(_('OpenSim — setup'), $options, $default);

            try {
                $quit = match (true) {
                    $choice === 'quit' => true,
                    $choice === 'core' => $this->coreMenu($cores),
                    $cores === [] => $this->ui->warn(_('Install an OpenSim core first.')) ?? false,
                    $choice === 'add' => $this->addGrid(),
                    default => $this->gridScreen(substr($choice, strlen('grid:'))),
                };
            } catch (Back) {
                // Escape in a screen: the menu it came from (here, the first one)
                $quit = false;
            } catch (Quit) {
                $quit = true;
            }
            if ($quit) {
                $this->offerRestarts();

                return;
            }
        }
    }

    /**
     * A flow that asks questions, which the user can give up with Escape: back to the screen that started
     * it, nothing was written (everything is written after the questions).
     *
     * @template T
     * @param \Closure():T $flow
     * @return T|null
     */
    private function guard(\Closure $flow): mixed
    {
        try {
            return $flow();
        } catch (Back) {
            $this->ui->note(_('Abandoned.'));

            return null;
        }
    }

    /** What the setup did that needs instances to restart: shown when it ends, to restart them or leave them. */
    private function offerRestarts(): void
    {
        $profile = (new Config())->profile();
        $etcRoot = $profile['EtcRoot'] ?? '';
        $pending = PendingRestarts::read($profile);
        if ($pending === []) {
            return;
        }

        $lines = [];
        $running = false;
        foreach ($pending as $entry) {
            $lines[] = "  {$entry['instance']}: {$entry['reason']}";
            $running = $running || Console::running("$etcRoot/opensim.d/{$entry['instance']}.ini");
        }
        $this->ui->note(_('To take these changes into account, restart:') . "\n" . implode("\n", $lines));
        if (!$this->ui->confirm(_('Restart them now?'), true)) {
            $this->ui->note(
                sprintf(
                    _('They stay listed in %s: the setup offers them again next time.'),
                    PendingRestarts::path($profile),
                ),
            );

            return;
        }
        // A simulator that runs warns its users and waits, or restarts at once
        $warn =
            $running && $this->ui->confirm(_('Warn the users of the simulators first (it takes two minutes)?'), false);
        if (!$this->act('restart', ['warn' => $warn])) {
            $this->ui->warn(_('Some instances did not restart: see above, they stay listed.'));
        }
    }

    /**
     * What a menu does to the install, by the system user of the install.
     *
     * @param array<string,mixed> $args
     */
    private function act(string $op, array $args): bool
    {
        $profile = (new Config())->profile();
        if (!Elevated::needed($profile)) {
            return (new Actions($this->ui))->perform($op, $args, $profile);
        }
        try {
            Elevated::run(
                $this->ui,
                '--apply-action',
                ['op' => $op, 'args' => $args, 'profile' => $profile],
                $profile['SystemUser'],
            );
        } catch (SetupFailed) {
            return false;
        }

        return true;
    }

    /** @return bool whether to quit the setup */
    private function coreMenu(array $cores): bool
    {
        $options = [];
        foreach ($cores as $name) {
            $options[$name] = $name;
        }
        $options['new'] = _('Install a new version');
        $options['back'] = _('Back');
        $options['quit'] = _('Quit');

        $choice = $this->ui->choose(_('OpenSim core'), $options, 'new');
        if ($choice === 'new') {
            (new Installer($this->ui))->run();
        } elseif ($choice !== 'back' && $choice !== 'quit') {
            (new Config())->setDefaultProfile($choice);
        }

        return $choice === 'quit';
    }

    /**
     * A grid run from this machine, or one run elsewhere, of which only its simulators are set up
     * here. What is configured is where the setup goes next: its screen, ready to add a simulator.
     *
     * @return bool whether to quit the setup
     */
    private function addGrid(): bool
    {
        $choice = $this->ui->choose(
            _('Add a grid'),
            [
                'quick' => _('Quick setup'),
                'new' => _('Advanced setup'),
                'external' => _('Connect to an external grid'),
                'back' => _('Back'),
                'quit' => _('Quit'),
            ],
            'quick',
        );

        switch ($choice) {
            case 'quick':
                $made = $this->guard(fn() => $this->quickSetup());

                return $made !== null && $this->ready($made[0], $made[1]);
            case 'new':
                $nick = $this->guard(fn() => (new NewGrid($this->ui))->run(null));
                if ($nick === null) {
                    return false;
                }
                // The grid is made: its first simulator and its region are what is missing to use it
                $made = $this->guard(fn() => (new NewSim($this->ui))->run($nick));

                return $made !== null ? $this->ready($made[0], $made[1]) : $this->gridScreen($nick);
            case 'external':
                $made = $this->guard(fn() => (new NewSim($this->ui))->run(null, null, true));

                return $made !== null && $this->simScreen($made[0], $made[1]);
            default:
                return $choice === 'quit';
        }
    }

    /**
     * The quick setup: a form, then the setup with the defaults and the plan to accept. When the plan is
     * not accepted, the advanced setup is offered.
     *
     * @return ?array{0:string,1:string} the grid and its simulator
     */
    private function quickSetup(): ?array
    {
        $data = (new QuickSetup($this->ui))->ask();
        try {
            return (new SetupRunner($this->ui))->runQuick($data);
        } catch (EditConfig) {
            // Nothing is written yet: every question again, with what was typed and what the setup chose as the
            // answers proposed
            return (new SetupRunner($this->ui))->run($data, [], '', true);
        }
    }

    /**
     * What the user sees when a grid and its first region are made: it is finished, and what remains
     * is optional. Finishing is what is proposed.
     *
     * @return bool whether to quit the setup
     */
    private function ready(string $nick, string $slug): bool
    {
        $profile = (new Config())->profile();
        $grid = GridInfo::load($profile, $nick);
        $this->ui->note(
            sprintf(
                _('Your grid %s is ready, nothing more is needed.'),
                $this->ui->entity($grid !== null && $grid->name !== '' ? $grid->name : $nick),
            ) .
                ($grid !== null && !$grid->remote
                    ? "\n" . sprintf(_('Login URI: %s'), "http://{$grid->baseHostname}:{$grid->publicPort}")
                    : '') .
                "\n" .
                _('You can finish now, or continue to add regions and simulators and change settings.'),
        );
        $choice = $this->ui->choose(_('Setup'), ['quit' => _('Finish setup'), 'more' => _('Continue setup')], 'quit');

        return $choice === 'quit' || $this->simScreen($nick, $slug);
    }

    /** @return bool whether to quit the setup */
    private function gridScreen(string $nick): bool
    {
        while (true) {
            $etcRoot = (new Config())->profile()['EtcRoot'] ?? '';
            $remote = GridInfo::isRemote($etcRoot, $nick);
            $enabled = GridState::isEnabled($etcRoot, $nick);
            $sims = $this->sims($etcRoot, $nick);

            // The grid is its regions: the simulators are the pool of servers that provide them
            $options = [];
            foreach ($sims as $slug) {
                foreach (RegionState::list("$etcRoot/grids/$nick/sims/$slug/regions") as $name => $region) {
                    $options["region:$slug:$name"] =
                        $this->ui->entity($name) .
                        ' [' .
                        $this->simName($nick, $slug) .
                        ']' .
                        ($region['enabled'] ? '' : ' [disabled]');
                }
            }
            $options['addregion'] = _('Add region');
            $options['sims'] = _('Simulators');
            if (!$remote) {
                $options['configure'] = _('Configure grid');
                $options['toggle'] = $enabled ? 'Disable this grid' : 'Enable this grid';
            }
            $options['back'] = _('Back');
            $options['quit'] = _('Quit');

            $choice = $this->ui->choose(
                'Grid: ' . $this->ui->entity($nick) . ($remote ? ' (Robust on another machine)' : ''),
                $options,
                array_key_first($options),
            );
            if ($choice === 'back' || $choice === 'quit') {
                return $choice === 'quit';
            }
            switch (true) {
                case $choice === 'configure':
                    $this->guard(fn() => (new NewGrid($this->ui))->run($nick));
                    break;
                case $choice === 'addregion':
                    $made = $this->guard(fn() => (new NewSim($this->ui))->addRegionToGrid($nick));
                    if ($made !== null && $this->simScreen($made[0], $made[1])) {
                        return true;
                    }
                    break;
                case $choice === 'sims':
                    if ($this->poolScreen($nick)) {
                        return true;
                    }
                    break;
                case $choice === 'toggle':
                    if ($this->act($enabled ? 'grid-disable' : 'grid-enable', ['nick' => $nick])) {
                        $this->ui->note(($enabled ? 'Disabled' : 'Enabled') . " $nick.");
                    }
                    break;
                default:
                    [, $slug, $name] = explode(':', $choice, 3);
                    if ($this->regionScreen($nick, $slug, $name)) {
                        return true;
                    }
            }
        }
    }

    /**
     * The simulators of a grid, the pool of servers that provide its regions.
     *
     * @return bool whether to quit the setup
     */
    private function poolScreen(string $nick): bool
    {
        while (true) {
            $etcRoot = (new Config())->profile()['EtcRoot'] ?? '';
            $sims = $this->sims($etcRoot, $nick);
            $options = [];
            foreach ($sims as $slug) {
                $options["sim:$slug"] =
                    $this->ui->entity($this->simTitle($etcRoot, $nick, $slug)) .
                    (SimState::isEnabled($etcRoot, $slug) ? '' : ' [disabled]');
            }
            $options['addsim'] = _('Add simulator');
            $options['back'] = _('Back');
            $options['quit'] = _('Quit');

            $choice = $this->ui->choose(
                'Simulators of ' . $this->ui->entity($nick),
                $options,
                $sims !== [] ? "sim:{$sims[0]}" : 'addsim',
            );
            if ($choice === 'back' || $choice === 'quit') {
                return $choice === 'quit';
            }
            if ($choice === 'addsim') {
                $made = $this->guard(fn() => (new NewSim($this->ui))->run($nick));
                if ($made !== null && $this->simScreen($made[0], $made[1])) {
                    return true;
                }
            } elseif ($this->simScreen($nick, substr($choice, strlen('sim:')))) {
                return true;
            }
        }
    }

    /**
     * A simulator and its regions. Adding a region is the usual next step, so it is the one
     * proposed, also after a region was added.
     *
     * @return bool whether to quit the setup
     */
    private function simScreen(string $nick, string $slug): bool
    {
        while (true) {
            $etcRoot = (new Config())->profile()['EtcRoot'] ?? '';
            $enabled = SimState::isEnabled($etcRoot, $slug);
            $regions = RegionState::list("$etcRoot/grids/$nick/sims/$slug/regions");

            $options = [];
            foreach ($regions as $name => $region) {
                $options["region:$name"] = $this->ui->entity($name) . ($region['enabled'] ? '' : ' [disabled]');
            }
            $options['addregion'] = _('Add region');
            $options['reconfigure'] = _('Configure sim');
            $options['toggle'] = $enabled ? 'Disable this simulator' : 'Enable this simulator';
            $options['back'] = _('Back');
            $options['quit'] = _('Quit');

            $choice = $this->ui->choose(
                'Simulator: ' . $this->ui->entity($this->simTitle($etcRoot, $nick, $slug)),
                $options,
                'addregion',
            );
            if ($choice === 'back' || $choice === 'quit') {
                return $choice === 'quit';
            }
            switch (true) {
                case $choice === 'reconfigure':
                    $this->guard(fn() => (new NewSim($this->ui))->run($nick, $this->simName($nick, $slug)));
                    break;
                case $choice === 'addregion':
                    $this->guard(fn() => (new NewSim($this->ui))->addRegion($nick, $this->simName($nick, $slug)));
                    break;
                case $choice === 'toggle':
                    $done = $this->act($enabled ? 'sim-disable' : 'sim-enable', [
                        'slug' => $slug,
                        'ini' => "$etcRoot/grids/$nick/sims/$slug.ini",
                    ]);
                    if ($done) {
                        $this->ui->note(($enabled ? 'Disabled' : 'Enabled') . " $slug.");
                    }
                    break;
                default:
                    if ($this->regionScreen($nick, $slug, substr($choice, strlen('region:')))) {
                        return true;
                    }
            }
        }
    }

    /** @return bool whether to quit the setup */
    private function regionScreen(string $nick, string $slug, string $name): bool
    {
        while (true) {
            $etcRoot = (new Config())->profile()['EtcRoot'] ?? '';
            $region = RegionState::list("$etcRoot/grids/$nick/sims/$slug/regions")[$name] ?? null;
            if ($region === null) {
                return false;
            }

            $choice = $this->ui->choose(
                'Region: ' . $this->ui->entity($name),
                [
                    'reconfigure' => _('Reconfigure'),
                    'toggle' => $region['enabled'] ? 'Disable this region' : 'Enable this region',
                    'back' => _('Back'),
                    'quit' => _('Quit'),
                ],
                'back',
            );

            switch ($choice) {
                case 'reconfigure':
                    $this->guard(
                        fn() => (new NewSim($this->ui))->reconfigureRegion($nick, $this->simName($nick, $slug), $name),
                    );
                    break;
                case 'toggle':
                    $done = $this->act($region['enabled'] ? 'region-disable' : 'region-enable', [
                        'file' => $region['file'],
                        'name' => $name,
                        'instance' => $slug,
                    ]);
                    if ($done) {
                        $this->ui->note(
                            sprintf(
                                $region['enabled']
                                    ? _('Disabled %s: the simulator takes it into account when it restarts.')
                                    : _('Enabled %s: the simulator takes it into account when it restarts.'),
                                $name,
                            ),
                        );
                    }
                    break;
                default:
                    return $choice === 'quit';
            }
        }
    }

    /** The name of a simulator as it was given, else (its config has no mention of it) as its instance name tells it. */
    private function simTitle(string $etcRoot, string $nick, string $slug): string
    {
        return SimConfig::readName("$etcRoot/grids/$nick/sims/$slug.ini") ?? $this->simName($nick, $slug);
    }

    /** The name of a simulator from the name of its instance: what follows the grid nick. */
    private function simName(string $nick, string $slug): string
    {
        $etcRoot = (new Config())->profile()['EtcRoot'] ?? '';
        if (($name = SimConfig::readName("$etcRoot/grids/$nick/sims/$slug.ini")) !== null) {
            return $name;
        }
        $prefix = GridInfo::instanceName($nick) . '_';

        return str_starts_with($slug, $prefix) ? substr($slug, strlen($prefix)) : $slug;
    }

    /** @return list<string> grid nicks under EtcRoot/grids */
    private function grids(string $etcRoot): array
    {
        return $this->dirNames($etcRoot === '' ? '' : "$etcRoot/grids");
    }

    /** @return list<string> instance names of the simulators of a grid */
    private function sims(string $etcRoot, string $grid): array
    {
        return $etcRoot === '' ? [] : SimState::names("$etcRoot/grids/$grid");
    }

    /** @return list<string> */
    private function dirNames(string $path): array
    {
        if ($path === '' || !is_dir($path)) {
            return [];
        }

        $names = [];
        foreach (glob("$path/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $names[] = basename($dir);
        }

        return $names;
    }
}
