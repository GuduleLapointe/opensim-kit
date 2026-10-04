<?php

declare(strict_types=1);

namespace OpenSim\Installer\Grid;

/**
 * Where a new region goes among the ones of a grid. A place is a block of 256 m,
 * which a region of that size takes (a larger one takes several); the places
 * taken are a set of "x,y" keys.
 *
 * The new region goes to the free place nearest to the one asked, by distance, so
 * that regions asked for the same place fill a disc around it: the circle that a
 * grid tends to form around its first region. The rule of the grid is a gap: free
 * blocks around the place, to leave room between the regions.
 */
final class LocationFinder
{
    /**
     * The first place of a grid: its public port, on both axes (8002 gives 8002,8002), so that the
     * grids of a machine do not start at the same place, which a teleport between them refuses.
     *
     * @return array{0:int,1:int}
     */
    public static function first(int $publicPort): array
    {
        return [$publicPort, $publicPort];
    }

    /**
     * The center of a grid, where its next regions gather: the middle of the places it has, and only when it has none the
     * first place by the port. What the rule of the port set at the start is not set again by it.
     *
     * @param array<string,true> $used
     * @return array{0:int,1:int}
     */
    public static function center(array $used, int $publicPort): array
    {
        $sumX = $sumY = $count = 0;
        foreach (array_keys($used) as $key) {
            if (($place = self::parse((string) $key)) !== null) {
                $sumX += $place[0];
                $sumY += $place[1];
                $count++;
            }
        }

        return $count === 0 ? self::first($publicPort) : [intdiv($sumX + intdiv($count, 2), $count), intdiv($sumY + intdiv($count, 2), $count)];
    }

    /** The key of a place. */
    public static function key(int $x, int $y): string
    {
        return "$x,$y";
    }

    /**
     * The place written "x,y".
     *
     * @return array{0:int,1:int}|null
     */
    public static function parse(string $location): ?array
    {
        return preg_match('/^\s*(\d+)\s*,\s*(\d+)\s*$/', $location, $m) ? [(int) $m[1], (int) $m[2]] : null;
    }

    /**
     * Mark the places a region takes.
     *
     * @param array<string,true> $used
     */
    public static function take(array &$used, int $x, int $y, int $width = 1, int $height = 1): void
    {
        for ($dx = 0; $dx < max(1, $width); $dx++) {
            for ($dy = 0; $dy < max(1, $height); $dy++) {
                $used[self::key($x + $dx, $y + $dy)] = true;
            }
        }
    }

    /**
     * The free place nearest to a point, with no place taken within $gap blocks
     * of it. At the same distance, the places are taken turning from the east.
     *
     * @param array<string,true> $used
     * @return array{0:int,1:int}
     */
    public static function nearestFree(array $used, int $x, int $y, int $gap = 0): array
    {
        for ($radius = 8; ; $radius *= 2) {
            foreach (self::offsets($radius) as [$dx, $dy]) {
                $cx = $x + $dx;
                $cy = $y + $dy;
                if ($cx >= 0 && $cy >= 0 && self::free($used, $cx, $cy, $gap)) {
                    return [$cx, $cy];
                }
            }
        }
    }

    /** No place taken within $gap blocks of this one (itself included). */
    private static function free(array $used, int $x, int $y, int $gap): bool
    {
        for ($dx = -$gap; $dx <= $gap; $dx++) {
            for ($dy = -$gap; $dy <= $gap; $dy++) {
                if (isset($used[self::key($x + $dx, $y + $dy)])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The offsets within a radius, nearest first, turning from the east for the
     * ones at the same distance.
     *
     * @return list<array{0:int,1:int}>
     */
    private static function offsets(int $radius): array
    {
        $offsets = [];
        for ($dx = -$radius; $dx <= $radius; $dx++) {
            for ($dy = -$radius; $dy <= $radius; $dy++) {
                if ($dx * $dx + $dy * $dy <= $radius * $radius) {
                    $angle = atan2($dy, $dx);
                    $offsets[] = [$dx * $dx + $dy * $dy, $angle < 0 ? $angle + 2 * M_PI : $angle, $dx, $dy];
                }
            }
        }
        usort($offsets, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn(array $o): array => [$o[2], $o[3]], $offsets);
    }
}
