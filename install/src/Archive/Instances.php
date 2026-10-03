<?php

declare(strict_types=1);

namespace OpenSim\Installer\Archive;

use OpenSim\Installer\Grid\GridInfo;

/**
 * Which grid, and which simulator of it, the words of a command name: `GRID`, `GRID SIM`, `GRID _SIM`, the instance
 * `grid_sim`, or nothing when the install has one grid.
 */
final class Instances
{
    /**
     * @param array<string,mixed> $profile the install profile
     * @param list<string> $refs the words, none to two
     * @return array{0:string,1:?string} the nick of the grid, the instance of the simulator when one is named
     * @throws \InvalidArgumentException
     */
    public static function resolve(array $profile, array $refs): array
    {
        $etc = $profile['EtcRoot'] ?? '';
        $nicks = self::grids($etc);
        $nick = null;
        $simRef = null;
        if (count($refs) > 2) {
            throw new \InvalidArgumentException('a grid and a simulator at most');
        }
        if (count($refs) === 2) {
            [$nick, $simRef] = $refs;
        } elseif (count($refs) === 1) {
            if (in_array($refs[0], $nicks, true)) {
                $nick = $refs[0];
            } else {
                $simRef = $refs[0];
            }
        }
        if ($nick === null) {
            // The simulator names its grid by its own name (grid_sim), else the only grid there is
            foreach ($nicks as $candidate) {
                if ($simRef !== null && self::simulator($etc, $candidate, $simRef) !== null) {
                    $nick = $candidate;
                    break;
                }
            }
            $nick ??= count($nicks) === 1 ? $nicks[0] : null;
        }
        if ($nick === null || !in_array($nick, $nicks, true)) {
            throw new \InvalidArgumentException(
                $nicks === [] ? 'no grid here' : ($simRef !== null && $nick === null ? "'$simRef' is not a simulator, " : '') . 'which grid? ' . implode(', ', $nicks),
            );
        }
        $slug = null;
        if ($simRef !== null) {
            $slug = self::simulator($etc, $nick, $simRef)
                ?? throw new \InvalidArgumentException("'$simRef' is not a simulator of the grid '$nick'");
        }

        return [$nick, $slug];
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
