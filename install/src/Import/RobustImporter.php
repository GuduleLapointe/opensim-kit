<?php

declare(strict_types=1);

namespace OpenSim\Installer\Import;

use OpenSim\Installer\Grid\GridPlan;
use OpenSim\Installer\Grid\RobustConfig;
use OpenSim\Installer\Grid\Slug;
use OpenSim\Installer\Ini;

/**
 * Ports the configuration of a Robust that was not made by the kit: what the setup asks is detected in its config
 * and goes through the generators of the kit (a standard config, valid for the core chosen), then the other values
 * of the original that differ from the defaults of the core are injected into it, so the settings of the user
 * are kept. The original is never changed.
 */
final class RobustImporter
{
    /** The sections the kit lays out itself: its folders, its pid file, its registry. */
    private const KIT_SECTIONS = ['Const', 'Startup', 'Launch'];

    /**
     * What the setup asks, read from the original config: a plan for NewGrid.
     *
     * @param array<string,array<string,string>> $raw the original, as written (see IniReader::load)
     * @param array<string,string>               $profile the install profile (roots of the kit)
     * @return GridPlan|string the plan, or what makes it impossible
     */
    public static function plan(array $raw, string $path, array $profile, ?string $nick, string $core): GridPlan|string
    {
        $ini = IniReader::expand($raw);
        $name = $ini['GridInfoService']['gridname'] ?? '';
        $nick = $nick ?: $ini['GridInfoService']['gridnick'] ?? '' ?: Slug::nick($name);
        $nick = preg_replace('/[^A-Za-z0-9]/', '', $nick) ?? '';
        if ($nick === '') {
            return 'the grid has no nick (gridnick of [GridInfoService]): give one with --nick';
        }
        if (
            !preg_match(
                '/Data Source=([^;"\s]*);Database=([^;"\s]*);User ID=([^;"\s]*);Password=([^;"]*?);/i',
                $ini['DatabaseService']['ConnectionString'] ?? '',
                $db,
            )
        ) {
            return 'no database in [DatabaseService] ConnectionString';
        }

        $plan = new GridPlan();
        $plan->gridName = $name !== '' ? $name : ucfirst($nick);
        $plan->gridNick = $nick;
        $plan->gridSlug = Slug::slug($plan->gridName);
        $plan->enableHypergrid = str_contains(basename($path), '.HG.') || isset($ini['Hypergrid']['GatekeeperURI']);
        $plan->coreDirectory = $core;
        $plan->binDir = "$core/bin";

        $host = $ini['Const']['BaseHostname'] ?? '';
        if ($host === '' && preg_match('#^https?://([^:/]+)#', $ini['Const']['BaseURL'] ?? '', $m)) {
            $host = $m[1];
        }
        $plan->baseHostname = $host !== '' ? $host : 'localhost';
        $plan->publicPort = (int) ($ini['Const']['PublicPort'] ?? 8002);
        $plan->privatePort = (int) ($ini['Const']['PrivatePort'] ?? $plan->publicPort + 1);
        $plan->webUrl = $ini['Const']['WebURL'] ?? "https://{$plan->baseHostname}";
        [, $plan->dbHost, $plan->dbName, $plan->dbUser, $plan->dbPass] = $db;

        $network = $ini['Network'] ?? [];
        if (($network['ConsoleUser'] ?? '') !== '' && (int) ($network['ConsolePort'] ?? 0) > 0) {
            $plan->consoleMode = 'rest';
            $plan->consolePort = (int) $network['ConsolePort'];
            $plan->consoleHost = $network['ConsoleHost'] ?? $plan->baseHostname;
            $plan->consoleUser = $network['ConsoleUser'];
            $plan->consolePass = $network['ConsolePass'] ?? '';
        } else {
            $plan->consoleMode = 'screen';
        }

        $plan->gridDir = ($profile['EtcRoot'] ?? '') . "/grids/$nick";
        $plan->etcDirectory = $plan->gridDir;
        $plan->dataDirectory = ($profile['DataRoot'] ?? '') . "/$nick";
        $plan->cacheDirectory = ($profile['CacheRoot'] ?? '') . "/$nick";
        $plan->logsDirectory = $profile['LogsRoot'] ?? '';
        $plan->enable = false;
        $plan->start = false;

        return $plan;
    }

    /**
     * What the original has that the kit's config does not say by itself, and that is not the default of the core:
     * the settings of the user. A value the setup writes (the values it asked) is not one, nor is a value the
     * example of the core has as it is. The exceptions are the places of the data (the assets, the map tiles...):
     * they stay where they are, as the original has them (made absolute), default or not, as the data is not
     * moved by porting the config.
     *
     * @param array<string,array<string,string>> $original   as written
     * @param array<string,array<string,string>> $generated  the standard config of the kit, as written
     * @param array<string,array<string,string>> $example    the example of the core, as written
     * @param string                             $dir        the folder of the original, for relative paths
     * @param array<string,array<string,string>> $expanded   the original with its references replaced (for the places)
     * @return list<array{section:string,key:string,value:string,unknown:bool,path:bool}>
     */
    public static function customizations(
        array $original,
        array $generated,
        array $example,
        string $dir,
        string $exampleText = '',
        array $expanded = [],
    ): array {
        $found = [];
        foreach ($original as $section => $values) {
            // The layout of the kit (its folders, its pid, its registry) is its own
            if (in_array($section, self::KIT_SECTIONS, true)) {
                continue;
            }
            foreach ($values as $key => $value) {
                // The files the original includes are those of its own layout, the kit has its own
                if (stripos($key, 'Include-') === 0) {
                    continue;
                }
                $standard = $generated[$section][$key] ?? null;
                $default = $example[$section][$key] ?? null;
                $unknown =
                    $exampleText !== '' &&
                    preg_match('/^\s*;*\s*' . preg_quote($key, '/') . '\s*=/mi', $exampleText) !== 1;

                if ($value !== '' && preg_match('/(Directory|Path|Dir|Location)$/i', $key) === 1) {
                    $place = self::absolute($key, $expanded[$section][$key] ?? $value, $dir);
                    if ($place !== $standard) {
                        $found[] = [
                            'section' => $section,
                            'key' => $key,
                            'value' => $place,
                            'unknown' => $unknown,
                            'path' => true,
                        ];
                    }
                    continue;
                }
                // What the setup wrote is its own; what is as in the core is no choice of the user
                if (
                    ($standard !== null && $standard !== $default) ||
                    $value === $standard ||
                    $value === $default ||
                    $value === ''
                ) {
                    continue;
                }
                $found[] = [
                    'section' => $section,
                    'key' => $key,
                    'value' => $value,
                    'unknown' => $unknown,
                    'path' => false,
                ];
            }
        }

        return $found;
    }

    /**
     * Put the customizations into the config the kit wrote, in place.
     *
     * @param list<array{section:string,key:string,value:string,unknown:bool,path?:bool}> $customizations
     */
    public static function inject(string $text, array $customizations): string
    {
        $ini = new Ini($text);
        foreach ($customizations as $c) {
            $value =
                preg_match('/^(-?\d+(\.\d+)?|true|false)$/i', $c['value']) === 1
                    ? $c['value']
                    : '"' . $c['value'] . '"';
            $ini->set($c['section'], $c['key'], $value);
        }

        return rtrim($ini->toString()) . "\n";
    }

    /** A path that was relative to the original config is made absolute: the user's data stays where it is. */
    private static function absolute(string $key, string $value, string $dir): string
    {
        if (
            !preg_match('/(Directory|Path|Dir|Location)$/i', $key) ||
            $value === '' ||
            str_starts_with($value, '/') ||
            str_contains($value, '${') ||
            str_contains($value, '://')
        ) {
            return $value;
        }
        $parts = [];
        foreach (explode('/', $dir . '/' . $value) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return '/' . implode('/', $parts);
    }

    /** The standard config of the kit for a plan: what the setup writes (see RobustConfig). */
    public static function generate(GridPlan $plan): string
    {
        return (new RobustConfig())->generate($plan);
    }
}
