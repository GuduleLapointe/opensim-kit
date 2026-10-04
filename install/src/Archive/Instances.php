<?php

declare(strict_types=1);

namespace OpenSim\Installer\Archive;

use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Grid\RegionState;

/**
 * Which grid, and which simulator or region of it, the words of a command name: `GRID`, `GRID SIM`, `GRID _SIM`,
 * `GRID REGION`, the instance `grid_sim`, a region or a simulator alone, or nothing when the install has one grid.
 */
final class Instances
{
    /**
     * Which grid, which simulator, which region the words name: a grid, a grid and a simulator or a region of it, the
     * simulator or the region alone, or nothing when the install has one grid.
     *
     * @param array<string,mixed> $profile the install profile
     * @param list<string> $refs the words, none to two
     * @return array{0:string,1:?string,2:?string} the nick of the grid, the instance of the simulator (the one that has the
     *         region when a region is named), the region
     * @throws \InvalidArgumentException
     */
    public static function locate(array $profile, array $refs): array
    {
        $etc = $profile['EtcRoot'] ?? '';
        $nicks = self::grids($etc);
        $nick = null;
        $target = null;
        if (count($refs) > 2) {
            throw new \InvalidArgumentException('a grid, and a simulator or a region of it, at most');
        }
        if (count($refs) === 2) {
            [$nick, $target] = $refs;
        } elseif (count($refs) === 1) {
            if (in_array($refs[0], $nicks, true)) {
                $nick = $refs[0];
            } else {
                $target = $refs[0];
            }
        }
        if ($nick === null) {
            // The simulator or the region says its grid, else the only grid there is
            foreach ($nicks as $candidate) {
                if ($target !== null && self::find($etc, $candidate, $target) !== null) {
                    $nick = $candidate;
                    break;
                }
            }
            $nick ??= count($nicks) === 1 ? $nicks[0] : null;
        }
        if ($nick === null || !in_array($nick, $nicks, true)) {
            throw new \InvalidArgumentException(
                $nicks === [] ? 'no grid here' : ($target !== null ? "'$target' is not a simulator or a region, " : '') . 'which grid? ' . implode(', ', $nicks),
            );
        }
        if ($target === null) {
            return [$nick, null, null];
        }
        $found = self::find($etc, $nick, $target)
            ?? throw new \InvalidArgumentException("'$target' is not a simulator or a region of the grid '$nick'");

        return [$nick, ...$found];
    }

    /**
     * Which grid, and which simulator of it, the words name (a region names the simulator that has it).
     *
     * @param list<string> $refs
     * @return array{0:string,1:?string}
     */
    public static function resolve(array $profile, array $refs): array
    {
        [$nick, $slug] = self::locate($profile, $refs);

        return [$nick, $slug];
    }

    /**
     * A simulator, else a region, of a grid, by the name given.
     *
     * @return ?array{0:string,1:?string} the simulator, the region when it is one that was named
     */
    private static function find(string $etc, string $nick, string $ref): ?array
    {
        $slug = self::simulator($etc, $nick, $ref);
        if ($slug !== null) {
            return [$slug, null];
        }
        foreach (glob("$etc/grids/$nick/sims/*.ini") ?: [] as $ini) {
            $sim = basename($ini, '.ini');
            foreach (array_keys(RegionState::list("$etc/grids/$nick/sims/$sim/regions")) as $region) {
                if (strcasecmp($region, $ref) === 0) {
                    return [$sim, $region];
                }
            }
        }

        return null;
    }

    /**
     * The nicks of the grids of the install, local or remote ones.
     *
     * @return list<string>
     */
    public static function grids(string $etc): array
    {
        $nicks = array_map('basename', glob("$etc/grids/*", GLOB_ONLYDIR) ?: []);
        sort($nicks);

        return $nicks;
    }

    /** The instance of a simulator named in full (grid_sim), or by its own name with or without a leading underscore */
    public static function simulator(string $etc, string $nick, string $ref): ?string
    {
        foreach ([$ref, GridInfo::instanceName($nick . '_' . ltrim($ref, '_'))] as $candidate) {
            if (is_file("$etc/grids/$nick/sims/$candidate.ini")) {
                return $candidate;
            }
        }

        return null;
    }
}
