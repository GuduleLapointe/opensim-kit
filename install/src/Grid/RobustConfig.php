<?php

declare(strict_types=1);

namespace OpenSim\Installer\Grid;

use OpenSim\Installer\Ini;
use OpenSim\Installer\Web\Services;

/**
 * Generates a grid's Robust.HG.ini by overlaying our settings onto the
 * distribution's Robust(.HG).ini.example, preserving its comments and defaults.
 *
 * No [Launch] section: that was a pre-Const artifact. Everything lives in the
 * standard [Const]/[Startup]/… sections; the launcher reads BinDirectory from
 * [Const] and derives the rest.
 */
final class RobustConfig
{
    public function example(GridPlan $plan): string
    {
        return $plan->binDir . '/' . ($plan->enableHypergrid ? 'Robust.HG.ini.example' : 'Robust.ini.example');
    }

    public function generate(GridPlan $plan): string
    {
        $ini = Ini::load($this->example($plan));

        $ini->merge([
            'Const' => [
                'BaseHostname' => $this->q($plan->baseHostname),
                'BaseURL' => $this->q("http://{$plan->baseHostname}"),
                'WebURL' => $this->q($plan->webUrl),
                'PublicPort' => (string) $plan->publicPort,
                'PrivatePort' => (string) $plan->privatePort,
                'EtcDirectory' => $this->q($plan->etcDirectory),
                'DataDirectory' => $this->q($plan->dataDirectory),
                'CacheDirectory' => $this->q($plan->cacheDirectory),
                'LogsDirectory' => $this->q($plan->logsDirectory),
            ],
            'Startup' => [
                'PIDFile' => $this->q('${Const|CacheDirectory}/' . $plan->gridNick . '.pid'),
                'RegistryLocation' => $this->q('${Const|DataDirectory}/registry'),
                'ConfigDirectory' => $this->q('${Const|EtcDirectory}/robust-include'),
                'ConsoleHistoryFile' => $this->q(
                    '${Const|LogsDirectory}/' . $plan->gridNick . '.RobustConsoleHistory.txt',
                ),
            ],
            'DatabaseService' => [
                'ConnectionString' => $this->q($plan->connectionString()),
            ],
            'AssetService' => [
                'BaseDirectory' => $this->q('${Const|DataDirectory}/fsassets/data'),
                'SpoolDirectory' => $this->q('${Const|CacheDirectory}/fsassets/tmp'),
                'AssetLoaderArgs' => $this->q('${Const|EtcDirectory}/assets/AssetSets.xml'),
            ],
            'GridService' => [
                'MapTileDirectory' => $this->q('${Const|CacheDirectory}/maptiles'),
            ],
            'LibraryService' => [
                'LibraryName' => $this->q("{$plan->gridName} Library"),
                'DefaultLibrary' => $this->q('${Const|EtcDirectory}/inventory/Libraries.xml'),
            ],
            'LoginService' => [
                'DestinationGuide' => $this->q('${Const|WebURL}/guide'),
                // Shown to the user when logging in (<USERNAME> is replaced by the name of the avatar)
                'WelcomeMessage' => $this->q("Welcome to {$plan->gridName}, <USERNAME>!"),
            ],
            'MapImageService' => [
                'TilesStoragePath' => $this->q('${Const|DataDirectory}/maptiles'),
            ],
            'GridInfoService' => [
                'gridname' => $this->q($plan->gridName),
                'gridnick' => $this->q($plan->gridNick),
                'welcome' => $this->q('${Const|WebURL}'),
            ],
            'BakedTextureService' => [
                'BaseDirectory' => $this->q('${Const|CacheDirectory}/bakes'),
            ],
        ]);

        // What the viewers are told of the services of the helpers, at the path the grid has chosen
        if ($plan->helpers) {
            $services = new Services($plan->helpersPath, $plan->helpersUrls);
            // The economy is the path of the helpers: the viewer adds the name of the script (currency.php...)
            $ini->set('GridInfoService', 'economy', $this->q('${Const|WebURL}' . $services->base()));
            // The splash page the viewer shows before the login
            $ini->set('GridInfoService', 'welcome', $this->q('${Const|WebURL}' . $services->path('welcome')));
            // The search of the viewer, in-world, is the query script (not a page of the web site)
            $ini->set('LoginService', 'SearchURL', $this->q('${Const|WebURL}' . $services->path('search')));
            // The destination guide of the viewers is the one of the helpers
            $ini->set('LoginService', 'DestinationGuide', $this->q('${Const|WebURL}' . $services->path('guide')));
            // The message of the day: the text of motd.php, read when Robust starts (WelcomeMessage when it cannot be)
            $ini->set('LoginService', 'MessageUrl', $this->q('${Const|WebURL}' . $services->path('motd')));
        }

        if ($plan->enableHypergrid) {
            $ini->merge([
                'Hypergrid' => [
                    'HomeURI' => $this->q('${Const|BaseURL}:${Const|PublicPort}'),
                    'GatekeeperURI' => $this->q('${Const|BaseURL}:${Const|PublicPort}'),
                ],
            ]);
        }

        // The remote console, every right on Robust through its port
        if ($plan->consoleMode === 'rest' && $plan->consolePort > 0) {
            $ini->merge([
                'Network' => [
                    'ConsoleHost' => $this->q($plan->consoleHost),
                    'ConsoleUser' => $this->q($plan->consoleUser),
                    'ConsolePass' => $this->q($plan->consolePass),
                    'ConsolePort' => (string) $plan->consolePort,
                ],
            ]);
        }

        // Enable user profiles (connector + service).
        $ini->uncomment('UserProfilesServiceConnector');
        $ini->set('UserProfilesService', 'Enabled', 'true');

        return $ini->toString();
    }

    private function q(string $value): string
    {
        return '"' . $value . '"';
    }
}
