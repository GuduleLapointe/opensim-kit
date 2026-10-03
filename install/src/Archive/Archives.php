<?php

declare(strict_types=1);

namespace OpenSim\Installer\Archive;

/**
 * The inventory and region archives (IAR, OAR) the console of a simulator saves and loads: what the options of
 * `opensim save|load iar|oar` are, the console lines they make, and the names the files get by default.
 *
 * The name of a file tells what is in it, the special modes in suffixes, so that nobody restores a "no assets" archive
 * by mistake: gridnick-first-last[-noassets][-perm<P>][-skipbadassets]-stamp.iar for an inventory,
 * gridnick-sim[-region]-stamp[-noassets][-perm<P>][-publish].oar for a region (the region is left out of a multi-region
 * archive, --all). Hyphens separate the parts, the parts have none of their own.
 */
final class Archives
{
    /**
     * The options of each command: name => [short letter or '', takes a value]. The name is the long option of the
     * console of the simulator, the options the setup adds (region) are kept out of the line it sends.
     */
    private const OPTIONS = [
        'save iar' => [
            'home' => ['h', true],
            'verbose' => ['v', false],
            'noassets' => ['', false],
            'perm' => ['', true],
            'skipbadassets' => ['', false],
            'creators' => ['c', false],
            'exclude' => ['e', true],
            'excludefolder' => ['f', true],
        ],
        'load iar' => [
            'merge' => ['m', false],
        ],
        'save oar' => [
            'home' => ['h', true],
            'noassets' => ['', false],
            'publish' => ['', false],
            'perm' => ['', true],
            'all' => ['', false],
            'region' => ['', true],
        ],
        'load oar' => [
            'merge' => ['', false],
            'skip-assets' => ['', false],
            'force-terrain' => ['', false],
            'force-parcels' => ['', false],
            'no-objects' => ['', false],
            'rotation' => ['', true],
            'displacement' => ['', true],
            'default-user' => ['', true],
            'bounding-origin' => ['', true],
            'bounding-size' => ['', true],
            'region' => ['', true],
        ],
    ];

    /** The words of the command after the options (the positional arguments), by command. */
    private const POSITIONAL = [
        'save iar' => ['first', 'last', 'path', 'password', 'file'],
        'load iar' => ['first', 'last', 'path', 'password', 'file'],
        'save oar' => ['file'],
        'load oar' => ['file'],
    ];

    /** What the setup asks of the person, not of the simulator */
    private const OURS = ['region'];

    /**
     * Read the arguments after the verb: the instance words (a grid, a simulator), the kind (iar or oar), the options and
     * the positional arguments.
     *
     * @param list<string> $args what follows `save` or `load`
     * @return array{verb:string,refs:list<string>,kind:string,options:array<string,string|true>,positional:array<string,string>}
     * @throws \InvalidArgumentException
     */
    public static function parse(string $verb, array $args): array
    {
        $refs = [];
        while ($args !== [] && !in_array($args[0], ['iar', 'oar'], true)) {
            if (str_starts_with($args[0], '-') || count($refs) >= 2) {
                throw new \InvalidArgumentException("expected iar or oar after the instance, not '{$args[0]}'");
            }
            $refs[] = array_shift($args);
        }
        if ($args === []) {
            throw new \InvalidArgumentException('iar or oar?');
        }
        $kind = array_shift($args);
        $command = "$verb $kind";
        $known = self::OPTIONS[$command];
        $shorts = [];
        foreach ($known as $name => [$short]) {
            if ($short !== '') {
                $shorts[$short] = $name;
            }
        }

        $options = [];
        $words = [];
        while ($args !== []) {
            $arg = array_shift($args);
            if ($arg === '--') {
                array_push($words, ...$args);
                break;
            }
            if (!str_starts_with($arg, '-') || $arg === '-') {
                $words[] = $arg;
                continue;
            }
            $value = null;
            if (str_starts_with($arg, '--')) {
                [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
            } else {
                $name = $shorts[substr($arg, 1, 1)] ?? null;
                if ($name === null) {
                    throw new \InvalidArgumentException("unknown option $arg");
                }
                $value = strlen($arg) > 2 ? ltrim(substr($arg, 2), '=') : null;
            }
            if (!isset($known[$name])) {
                // What the console of the simulator knows and the setup does not: given as it is
                $options[$name] = $value ?? true;
                continue;
            }
            if ($known[$name][1]) {
                $value ??= array_shift($args);
                if ($value === null) {
                    throw new \InvalidArgumentException("option --$name needs a value");
                }
                $options[$name] = $value;
            } else {
                if ($value !== null) {
                    throw new \InvalidArgumentException("option --$name takes no value");
                }
                $options[$name] = true;
            }
        }

        $positional = [];
        $names = self::POSITIONAL[$command];
        if (count($words) > count($names)) {
            throw new \InvalidArgumentException("too many arguments, '{$words[count($names)]}'");
        }
        foreach ($words as $i => $word) {
            $positional[$names[$i]] = $word;
        }

        return ['verb' => $verb, 'refs' => $refs, 'kind' => $kind, 'options' => $options, 'positional' => $positional];
    }

    /** The positional arguments a command needs, those it can do without (the password is asked, the file defaulted) left out */
    public static function required(string $verb, string $kind): array
    {
        return $kind === 'iar' ? ['first', 'last', 'path'] : [];
    }

    /** Date and time as the stamp of a file name: YYYYMMDD-HHMMSS */
    public static function stamp(?int $time = null): string
    {
        return date('Ymd-His', $time ?? time());
    }

    /** A part of a file name: letters, digits, underscore and dot, nothing that could be taken for a separator */
    public static function part(string $text): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_.]+/', '_', $text), '_');
    }

    /** The suffixes of the special modes of a save, in the order of the names */
    private static function modes(array $options, array $names): string
    {
        $suffix = '';
        foreach ($names as $name) {
            if (!isset($options[$name])) {
                continue;
            }
            $suffix .= $name === 'perm' ? '-perm' . self::part((string) $options[$name]) : "-$name";
        }

        return $suffix;
    }

    /**
     * The default name of an inventory archive.
     *
     * @param array<string,string|true> $options
     */
    public static function iarName(string $grid, string $first, string $last, array $options, string $stamp): string
    {
        return self::part($grid) . '-' . self::part($first) . '-' . self::part($last)
            . self::modes($options, ['noassets', 'perm', 'skipbadassets']) . "-$stamp.iar";
    }

    /**
     * The default name of a region archive: the region is left out when all the regions are saved.
     *
     * @param array<string,string|true> $options
     */
    public static function oarName(string $grid, string $sim, ?string $region, array $options, string $stamp): string
    {
        $parts = [self::part($grid), self::part($sim)];
        if ($region !== null && !isset($options['all'])) {
            $parts[] = self::part($region);
        }

        return implode('-', $parts) . "-$stamp" . self::modes($options, ['noassets', 'perm', 'publish']) . '.oar';
    }

    /**
     * What a default name starts with, to find the archives of an inventory or a region again (the newest one is
     * loaded when no file is given).
     */
    public static function iarPrefix(string $grid, string $first, string $last): string
    {
        return self::part($grid) . '-' . self::part($first) . '-' . self::part($last) . '-';
    }

    public static function oarPrefix(string $grid, string $sim, ?string $region): string
    {
        return implode('-', array_filter([self::part($grid), self::part($sim), $region !== null ? self::part($region) : null])) . '-';
    }

    /**
     * The newest archive of a folder whose name starts with the prefix (the stamps sort as the dates do), null when none.
     * The name must be what the prefix and the stamp make: a longer region name does not borrow the archives of a shorter.
     */
    public static function newest(string $directory, string $prefix, string $extension): ?string
    {
        $found = [];
        foreach (glob(rtrim($directory, '/') . '/*.' . $extension) ?: [] as $file) {
            $name = basename($file);
            if (str_starts_with($name, $prefix) && preg_match('/^\d{8}-\d{6}(-|\.)/', substr($name, strlen($prefix)))) {
                $found[$name] = $file;
            }
        }
        if ($found === []) {
            return null;
        }
        // The stamp is what sorts, not the prefix (it is the same)
        uksort($found, static fn(string $a, string $b): int => strcmp(substr($a, strlen($prefix)), substr($b, strlen($prefix))));

        return end($found);
    }

    /**
     * The words of the console line: the options as the simulator knows them, then the positional arguments, quoted when
     * they have a space or a character the console reads as something else.
     *
     * @param array<string,string|true> $options
     * @param list<string> $arguments
     */
    public static function line(string $verb, string $kind, array $options, array $arguments): string
    {
        $words = [$verb, $kind];
        foreach ($options as $name => $value) {
            if (in_array($name, self::OURS, true)) {
                continue;
            }
            $words[] = $value === true ? "--$name" : "--$name=" . self::quote((string) $value);
        }
        foreach ($arguments as $argument) {
            $words[] = self::quote($argument);
        }

        return implode(' ', $words);
    }

    private static function quote(string $word): string
    {
        return preg_match('/[\s"<>]/', $word) === 1 ? '"' . str_replace('"', '\"', $word) . '"' : $word;
    }
}
