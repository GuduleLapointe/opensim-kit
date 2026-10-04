<?php

declare(strict_types=1);

namespace OpenSim\Installer\Archive;

/**
 * The old archives and backups, `--keep N`: the files that have the name of a new one but for its stamp
 * (`YYYYMMDD-HHMMSS`) are its series, the N newest are kept.
 */
final class Rotation
{
    /**
     * The number of files to keep, as the option gives it.
     *
     * @throws \InvalidArgumentException
     */
    public static function count(string|bool $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException('--keep needs a number of 1 or more');
        }

        return (int) $value;
    }

    /**
     * Remove the oldest files of the series of a file, the file included in the ones kept.
     *
     * @return list<string> the files removed
     */
    public static function keep(string $file, int $keep): array
    {
        if (!preg_match('/^(.*)\d{8}-\d{6}(.*)$/', basename($file), $m)) {
            return [];
        }
        $pattern = '/^' . preg_quote($m[1], '/') . '(\d{8}-\d{6})' . preg_quote($m[2], '/') . '$/';
        $directory = dirname($file);

        $series = [];
        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match($pattern, $name, $found)) {
                $series[$found[1]] = "$directory/$name";
            }
        }
        ksort($series);

        $removed = [];
        foreach (array_slice($series, 0, max(0, count($series) - $keep)) as $old) {
            if ($old !== $file && @unlink($old)) {
                $removed[] = $old;
            }
        }

        return $removed;
    }
}
