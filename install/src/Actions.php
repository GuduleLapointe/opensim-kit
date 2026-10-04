<?php

declare(strict_types=1);

namespace OpenSim\Installer;

use OpenSim\Installer\Grid\GridState;
use OpenSim\Installer\Grid\RegionState;
use OpenSim\Installer\Grid\SimState;
use OpenSim\Installer\Import\RobustImporter;
use OpenSim\Installer\Ui\InstallerUi;

/**
 * What the setup menus do to the install once the questions are answered: enabling or disabling a
 * grid, a simulator or a region, restarting what has to. The files and the instances belong to
 * the system user of the install, so it is the process of that user that does them (see Elevated).
 */
final class Actions
{
    public function __construct(private InstallerUi $ui) {}

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed> $profile
     * @return bool whether it was done, the reason said on screen when it was not
     */
    public function perform(string $op, array $args, array $profile): bool
    {
        $etcRoot = (string) ($profile['EtcRoot'] ?? '');

        switch ($op) {
            case 'grid-enable':
                return GridState::enable($etcRoot, $args['nick']) ||
                    $this->no('Cannot enable the grid (no Robust config).');
            case 'grid-disable':
                GridState::disable($etcRoot, $args['nick']);

                return !GridState::isEnabled($etcRoot, $args['nick']) ||
                    $this->no('Cannot disable the grid (permission).');
            case 'sim-enable':
                return SimState::enable($etcRoot, $args['slug'], $args['ini']) ||
                    $this->no('Cannot enable the simulator (no config).');
            case 'sim-disable':
                SimState::disable($etcRoot, $args['slug']);

                return !SimState::isEnabled($etcRoot, $args['slug']) ||
                    $this->no('Cannot disable the simulator (permission).');
            case 'region-enable':
            case 'region-disable':
                $moved =
                    $op === 'region-enable' ? RegionState::enable($args['file']) : RegionState::disable($args['file']);
                if ($moved === null) {
                    return $this->no(
                        'Cannot change the region (permission, or a region of that name is there already).',
                    );
                }
                PendingRestarts::add(
                    $profile,
                    $args['instance'],
                    "region {$args['name']} " . ($op === 'region-enable' ? 'enabled' : 'disabled'),
                );

                return true;
            case 'restart':
                return $this->restart($profile, (bool) ($args['warn'] ?? false));
            case 'copy-regions':
                // The region files of an imported simulator: copied, the original ones are not touched
                @mkdir((string) $args['to'], 0o755, true);
                foreach ($args['files'] as $file) {
                    $dest = rtrim((string) $args['to'], '/') . '/' . basename((string) $file);
                    if (!is_file($dest) && !@copy((string) $file, $dest)) {
                        return $this->no("Cannot copy $file to $dest.");
                    }
                }

                return true;
            case 'inject-config':
                // The settings of an imported config go into the config the kit wrote, and the report beside it
                $path = (string) $args['path'];
                if (
                    !is_file($path) ||
                    @file_put_contents(
                        $path,
                        RobustImporter::inject((string) file_get_contents($path), $args['customizations']),
                    ) === false
                ) {
                    return $this->no("Cannot write the settings into $path.");
                }
                if (($args['report_path'] ?? '') !== '') {
                    @file_put_contents((string) $args['report_path'], (string) $args['report']);
                }

                return true;
        }

        return $this->no("Unknown action $op.");
    }

    private function no(string $message): bool
    {
        $this->ui->warn($message);

        return false;
    }

    /**
     * Restart the instances waiting for it: the grids first (the simulators register in them),
     * then each simulator, stopped, its regions' parcels named, started again when it was running.
     *
     * @param array<string,mixed> $profile
     */
    private function restart(array $profile, bool $warn): bool
    {
        $etcRoot = (string) ($profile['EtcRoot'] ?? '');
        $opensim = dirname(__DIR__, 2) . '/bin/opensim';
        $instances = array_values(array_unique(array_column(PendingRestarts::read($profile), 'instance')));
        usort(
            $instances,
            static fn(string $a, string $b): int => is_file("$etcRoot/robust.d/$b.ini") <=>
                is_file("$etcRoot/robust.d/$a.ini"),
        );

        $ok = true;
        foreach ($instances as $instance) {
            $now = $warn ? '' : 'now ';
            if (is_file("$etcRoot/robust.d/$instance.ini")) {
                $ok = System::run(System::arg($opensim) . ' restart now ' . System::arg($instance)) === 0 && $ok;

                continue;
            }
            $running = Console::running("$etcRoot/opensim.d/$instance.ini");
            if ($running) {
                $ok = System::run(System::arg($opensim) . " stop $now" . System::arg($instance)) === 0 && $ok;
            }
            if ($running) {
                $ok = System::run(System::arg($opensim) . ' start ' . System::arg($instance)) === 0 && $ok;
            }
        }
        if ($ok) {
            PendingRestarts::clear($profile);
        }

        return $ok;
    }
}
