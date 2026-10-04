<?php

declare(strict_types=1);

namespace OpenSim\Installer;

use OpenSim\Installer\Ui\InstallerUi;

/**
 * Install (or add) an OpenSim core: pick a version, gather the plan, apply it.
 *
 * Reusable flow driven through an InstallerUi, returning control to the caller
 * (the setup hub) when done — it never exits the process.
 */
final class Installer
{
    public function __construct(private InstallerUi $ui) {}

    public function run(): void
    {
        $ui = $this->ui;
        $root = dirname(__DIR__, 2);

        // --- Version selection ---
        $releases = new Releases();
        $list = $ui->spin(static fn() => $releases->available(), 'Fetching the OpenSim release list…');
        if ($list === []) {
            $ui->error(_('Could not retrieve the OpenSim release list (no network and no cache).'));

            return;
        }

        $options = [];
        $byVersion = [];
        foreach ($list as $release) {
            $options[$release['version']] =
                'opensim-' . $release['version'] . ($release['installed'] ? '  (installed)' : '');
            $byVersion[$release['version']] = $release;
        }
        $options['dev'] = _('Development version (unstable, built from source — not yet implemented)');
        $options['back'] = _('Back');

        $choice = $ui->choose(_('OpenSim version to install'), $options, array_key_first($options));
        if ($choice === 'back') {
            return;
        }
        if ($choice === 'dev') {
            $ui->error(_('Building from source is not implemented yet.'));

            return;
        }

        $plan = new Plan();
        $plan->version = $choice;
        $plan->tarball = $byVersion[$choice]['tarball'];

        // --- Runtime gate ---
        (new Runtime($ui))->gate($plan);

        // --- Layout + locations ---
        $absolute = static fn(string $v): ?string => str_starts_with($v, '/')
            ? null
            : _('Use an absolute path (starting with /).');

        $plan->layout = $ui->choose(_('Installation layout'), Layout::LABELS, 'system');
        $plan->installPath = Layout::needsBase($plan->layout)
            ? $ui->text(_('Install base directory'), Layout::defaultBase($plan->layout), $absolute)
            : Layout::defaultBase($plan->layout);

        Layout::applyDefaults($plan);

        $plan->coreRoot = $ui->text(_('Core root'), $plan->coreRoot, $absolute);
        $plan->coreDirectory = $ui->text(_('Core directory'), $plan->coreDirectory, $absolute);
        $plan->etcRoot = $ui->text(_('Etc directory'), $plan->etcRoot, $absolute);
        $plan->varRoot = $ui->text(_('Var directory'), $plan->varRoot, $absolute);
        $plan->logsRoot = $ui->text(_('Logs directory'), $plan->logsRoot, $absolute);
        $plan->cacheRoot = $ui->text(_('Cache directory'), $plan->cacheRoot, $absolute);
        $plan->dataRoot = $ui->text(_('Data directory'), $plan->dataRoot, $absolute);
        // Downloads go next to a git checkout, in the cache for a packaged kit
        $sources = file_exists("$root/.git") ? "$root/src" : "{$plan->cacheRoot}/src";
        $plan->sourcesDirectory = $ui->text(_('Sources directory'), $sources, $absolute);

        // --- Profile name + default ---
        $plan->profile = $ui->text(
            _('Profile name'),
            "opensim-{$plan->version}",
            static fn(string $v): ?string => preg_match('/^[A-Za-z0-9._-]+$/', $v)
                ? null
                : _('Letters, digits, dot, dash and underscore only.'),
        );

        $current = (new Config())->defaultProfile();
        $plan->makeDefault =
            $current === null ||
            $current === $plan->profile ||
            $ui->confirm(sprintf(_("Make '%s' the default install (current: %s)?"), $plan->profile, $current), false);

        // --- Show the gathered plan ---
        $lines = [];
        foreach ($plan->summary() as $label => $value) {
            $lines[] = sprintf('  %-16s %s', $label . ':', $value);
        }
        $ui->note(_('Installation plan:') . "\n" . implode("\n", $lines));

        // --- Apply ---
        if (!$ui->confirm(_('Proceed with the installation?'), true)) {
            $ui->note(_('Aborted — nothing changed.'));

            return;
        }

        (new Packages($ui))->ensure('screen', 'screen'); // needed to run instances later

        // A packaged kit ships its submodules
        if (file_exists("$root/.git")) {
            $ui->note(_('Updating git submodules…'));
            System::run('git -C ' . System::arg($root) . ' submodule update --init');
        }

        $user = getenv('USER') ?: get_current_user();
        $dirs = [
            $plan->etcRoot,
            "{$plan->etcRoot}/opensim.d",
            "{$plan->etcRoot}/robust.d",
            "{$plan->etcRoot}/grids",
            $plan->sourcesDirectory,
            $plan->coreRoot,
            $plan->coreDirectory,
            $plan->varRoot,
            $plan->logsRoot,
            $plan->cacheRoot,
            $plan->dataRoot,
        ];
        foreach (array_unique($dirs) as $dir) {
            if (!is_dir($dir)) {
                System::run('sudo install -d -o ' . System::arg($user) . ' ' . System::arg($dir));
            }
        }

        (new Distribution($ui))->fetchAndExtract($plan);
        (new Runtime($ui))->install($plan);

        if (!is_file("{$plan->coreDirectory}/bin/OpenSim.exe") && !is_file("{$plan->coreDirectory}/bin/OpenSim.dll")) {
            $ui->error(sprintf(_('Core not found after install: %s/bin/OpenSim.dll'), $plan->coreDirectory));

            return;
        }

        $path = (new Config())->write($plan);
        $ui->note(sprintf(_('OpenSim %s installed. Config: %s'), $plan->version, $path));
    }
}
