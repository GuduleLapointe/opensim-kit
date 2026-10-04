<?php

declare(strict_types=1);

namespace OpenSim\Installer\Import;

use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Grid\SimPlan;

/**
 * Ports the configuration of a simulator of a grid (an OpenSim.ini with its includes, its regions folder) the way
 * RobustImporter ports a Robust: what the setup asks is detected and goes through the generators of the kit, the
 * regions are copied (their identity, their UUID, is what the grid knows them by), the other settings that differ
 * from the defaults of the core are injected. Customizations are found by RobustImporter::customizations().
 */
final class SimImporter
{
    /**
     * @param array<string,array<string,string>> $raw the original, as written, includes read
     * @return SimPlan|string the plan, or what makes it impossible
     */
    public static function plan(
        array $raw,
        string $path,
        GridInfo $grid,
        array $profile,
        ?string $name,
        string $core,
    ): SimPlan|string {
        $ini = IniReader::expand($raw);
        $architecture =
            $ini['Architecture']['Include-Architecture'] ?? ($raw['Architecture']['Include-Architecture'] ?? '');
        if ($architecture !== '' && stripos($architecture, 'Standalone') !== false) {
            return 'a standalone simulator has its own services: only the simulators of a grid are ported';
        }
        if (
            !preg_match(
                '/Data Source=([^;"\s]*);Database=([^;"\s]*);User ID=([^;"\s]*);Password=([^;"]*?);/i',
                $ini['DatabaseService']['ConnectionString'] ?? '',
                $db,
            )
        ) {
            return 'no database in [DatabaseService] ConnectionString (the simulator has its own)';
        }
        $port = (int) ($ini['Network']['http_listener_port'] ?? 0);
        if ($port <= 0) {
            return 'no http_listener_port in [Network]';
        }

        $name =
            $name !== null && $name !== ''
                ? $name
                : (string) preg_replace('/[^A-Za-z0-9 _-]/', '', basename(dirname($path, 2)));
        if ($name === '') {
            return 'the simulator has no name: give one with --name';
        }

        $plan = new SimPlan();
        $plan->gridNick = $grid->nick;
        $plan->gridName = $grid->name;
        $plan->gridDir = $grid->dir;
        $plan->hypergrid = $grid->hypergrid;
        $plan->baseHostname = $grid->baseHostname;
        $plan->publicPort = $grid->publicPort;
        $plan->privatePort = $grid->privatePort;
        $plan->logsDirectory = $grid->logsDirectory;
        $plan->coreDirectory = $core;
        $plan->simName = $name;
        $plan->slug = GridInfo::instanceName($grid->nick . '_' . $name);
        $plan->httpPort = $port;
        $plan->dataDirectory = "{$grid->dataDirectory}/{$plan->slug}";
        $plan->cacheDirectory = "{$grid->cacheDirectory}/{$plan->slug}";
        [, $plan->dbHost, $plan->dbName, $plan->dbUser, $plan->dbPass] = $db;

        $network = $ini['Network'] ?? [];
        if (($network['ConsoleUser'] ?? '') !== '' && (int) ($network['console_port'] ?? 0) > 0) {
            $plan->consoleMode = 'rest';
            $plan->consolePort = (int) $network['console_port'];
            $plan->consoleHost = $network['ConsoleHost'] ?? '';
            $plan->consoleUser = $network['ConsoleUser'];
            $plan->consolePass = $network['ConsolePass'] ?? '';
        } else {
            $plan->consoleMode = 'screen';
        }
        $plan->estateName = $ini['Estates']['DefaultEstateName'] ?? "{$grid->name} Estate";
        $plan->estateOwner = $ini['Estates']['DefaultEstateOwnerName'] ?? '';
        $plan->createRegion = false;
        $plan->enable = false;
        $plan->start = false;

        return $plan;
    }

    /**
     * The files that describe the regions of a simulator: the folder it names (`regionload_regionsdir`), else
     * Regions/ next to its bin folder, the `*.ini` files in it (a disabled region, `.ini.disabled`, is kept as it is).
     *
     * @param array<string,array<string,string>> $raw
     * @return list<string>
     */
    public static function regionFiles(array $raw, string $path): array
    {
        $dir = $raw['Startup']['regionload_regionsdir'] ?? 'Regions';
        $dir = str_starts_with($dir, '/') ? $dir : dirname($path) . "/$dir";

        return array_values(
            array_merge(glob(rtrim($dir, '/') . '/*.ini') ?: [], glob(rtrim($dir, '/') . '/*.ini.disabled') ?: []),
        );
    }

    /** The address the regions of the simulator announce, from their files (the first one that has it). */
    public static function externalHost(array $files): string
    {
        foreach ($files as $file) {
            if (preg_match('/^\s*ExternalHostName\s*=\s*"?([^"\s]+)/m', (string) file_get_contents($file), $m)) {
                return $m[1];
            }
        }

        return 'SYSTEMIP';
    }
}
