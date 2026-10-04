<?php

declare(strict_types=1);

namespace OpenSim\Installer;

use OpenSim\Installer\Ui\InstallerUi;

/**
 * Discriminating runtime gate: decides whether the chosen OpenSim version can
 * run (mono for < 0.9.3.0, .NET for >= 0.9.3.0) and records the decision in the
 * plan. Aborts the wizard if no usable runtime and the user declines to install.
 *
 * The exact .NET version is re-verified from the binaries at launch
 * (bin/opensim); here we map version -> runtime before anything is downloaded.
 */
final class Runtime
{
    private const DOTNET_THRESHOLD = '0.9.3.0';
    private const DOTNET_MAJOR = 8; // OpenSim 0.9.3.x targets .NET 8 (net8.0)

    public function __construct(private InstallerUi $ui) {}

    public function gate(Plan $plan): void
    {
        if (version_compare($plan->version, self::DOTNET_THRESHOLD, '>=')) {
            $this->gateDotnet($plan);
        } else {
            $this->gateMono($plan);
        }
    }

    private function gateDotnet(Plan $plan): void
    {
        $plan->runtime = 'dotnet';
        $plan->dotnetMajor = self::DOTNET_MAJOR;
        $major = self::DOTNET_MAJOR;
        $installed = $this->dotnetMajors();

        if (in_array($major, $installed, true)) {
            $this->ui->note(sprintf(_('.NET %s is installed; OpenSim %s will use it.'), $major, $plan->version));

            return;
        }

        $newer = array_filter($installed, static fn(int $m): bool => $m > $major);
        if ($newer !== []) {
            $choice = $this->ui->choose(
                "OpenSim {$plan->version} targets .NET $major. Installed: " . implode(', ', $installed),
                [
                    'rollforward' => 'Use roll-forward on .NET ' . max($newer) . ' (no additional install)',
                    'install' => sprintf(_('Install native .NET %s'), $major),
                ],
                'rollforward',
            );
            $choice === 'install' ? ($plan->installRuntime = true) : ($plan->rollForward = true);

            return;
        }

        $msg =
            $installed !== []
                ? 'Installed .NET (' . implode(', ', $installed) . ") is older than $major. "
                : 'No .NET runtime is installed. ';
        if ($this->ui->confirm($msg . "OpenSim {$plan->version} needs .NET $major. Install it now?", true)) {
            $plan->installRuntime = true;
        } else {
            $this->ui->error(sprintf(_('OpenSim %s cannot run without .NET %s.'), $plan->version, $major));
            exit(1);
        }
    }

    private function gateMono(Plan $plan): void
    {
        $plan->runtime = 'mono';

        if (System::commandExists('mono')) {
            $this->ui->note(sprintf(_('Mono is installed; OpenSim %s will use it.'), $plan->version));

            return;
        }

        if ($this->ui->confirm(sprintf(_('OpenSim %s needs Mono. Install it now?'), $plan->version), true)) {
            $plan->installRuntime = true;
        } else {
            $this->ui->error(sprintf(_('OpenSim %s cannot run without Mono.'), $plan->version));
            exit(1);
        }
    }

    /** Install the runtime when the plan requires it (apply phase). */
    public function install(Plan $plan): void
    {
        if (!$plan->installRuntime) {
            return;
        }

        if ($plan->runtime === 'dotnet') {
            $this->installDotnet((int) $plan->dotnetMajor);
        } else {
            $this->installMono();
        }
    }

    private function installDotnet(int $major): void
    {
        // Install into the active dotnet root so the existing 'dotnet' finds it.
        [$code, $path] = System::capture('command -v dotnet');
        $dir =
            $code === 0 && trim($path) !== ''
                ? dirname(realpath(trim($path)) ?: trim($path))
                : (getenv('HOME') ?: '') . '/.dotnet';
        $sudo = is_writable($dir) ? '' : 'sudo ';

        $script = sys_get_temp_dir() . '/dotnet-install.sh';
        if (
            System::run(
                'curl -fsSL https://builds.dotnet.microsoft.com/dotnet/scripts/v1/dotnet-install.sh -o ' .
                    System::arg($script),
            ) !== 0
        ) {
            $this->ui->error(_('Could not fetch dotnet-install.sh.'));
            exit(1);
        }

        $this->ui->note(sprintf(_('Installing .NET %s into %s'), $major, $dir));
        $code = System::run(
            $sudo .
                'bash ' .
                System::arg($script) .
                ' --runtime dotnet --channel ' .
                System::arg("$major.0") .
                ' --install-dir ' .
                System::arg($dir),
        );
        if ($code !== 0) {
            $this->ui->error(sprintf(_('.NET %s installation failed.'), $major));
            exit(1);
        }
    }

    private function installMono(): void
    {
        $package = PHP_OS_FAMILY === 'Darwin' ? 'mono' : 'mono-complete';
        $this->ui->note(sprintf(_('Installing %s …'), $package));

        // The package manager may exit non-zero on a non-fatal post-install
        // step (e.g. Homebrew's mono GAC setup) while mono itself is perfectly
        // usable, so judge success by whether the binary ends up available.
        (new Packages($this->ui))->install($package);
        if (!System::commandExists('mono')) {
            $this->ui->error(sprintf(_('Mono installation failed (%s not on PATH).'), $package));
            exit(1);
        }

        [, $version] = System::capture('mono --version');
        $this->ui->note(sprintf(_('mono ready: %s'), strtok($version, "\n") ?: _('installed')));
    }

    /** @return list<int> installed Microsoft.NETCore.App major versions, ascending */
    private function dotnetMajors(): array
    {
        if (!System::commandExists('dotnet')) {
            return [];
        }

        [, $out] = System::capture('dotnet --list-runtimes');
        preg_match_all('/Microsoft\.NETCore\.App (\d+)\./', $out, $m);
        $majors = array_values(array_unique(array_map('intval', $m[1])));
        sort($majors);

        return $majors;
    }
}
