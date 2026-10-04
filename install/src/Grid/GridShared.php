<?php

declare(strict_types=1);

namespace OpenSim\Installer\Grid;

use OpenSim\Installer\Ini;
use OpenSim\Installer\TextFile;

/**
 * The architecture files a grid shares between its simulators (its
 * config-include folder), made usable by simulators run from a read-only core.
 *
 * A simulator reads one config file and the files it includes after it, each
 * one overriding what came before, and the included paths are relative to the
 * core (where the files are only examples). So, in the copies of the grid:
 *  - the includes are absolute, pointing at the copies;
 *  - the SQLite storage included by default, which would override the database
 *    of the simulator, is off;
 *  - the asset cache goes to the cache folder of the simulator, not the core.
 *
 * Idempotent: what is already done is left as it is, so it also repairs the
 * copies of a grid made before, and keeps the changes made by hand.
 */
final class GridShared
{
    /**
     * Copy what is missing from the core and patch it.
     *
     * @return list<string> the files written
     */
    public function prepare(string $gridDir, string $binDir, bool $hypergrid): array
    {
        $dir = "$gridDir/config-include";
        $architecture = $hypergrid ? 'GridHypergrid.ini' : 'Grid.ini';
        $written = [];

        foreach (
            [$architecture, 'GridCommon.ini', 'FlotsamCache.ini', 'osslDefaultEnable.ini', 'osslEnable.ini']
            as $file
        ) {
            $dest = "$dir/$file";
            if (is_file($dest)) {
                if (TextFile::clean($dest)) {
                    $written[] = $dest;
                }
            } else {
                $source = $this->source($binDir, $file);
                if ($source === null) {
                    continue;
                }
                is_dir($dir) || mkdir($dir, 0o755, true);
                if (TextFile::copy($source, $dest)) {
                    $written[] = $dest;
                }
            }
        }

        $written = array_merge(
            $written,
            array_filter([
                $this->patch("$dir/$architecture", [
                    '/^(\s*Include-Common\s*=\s*)"?config-include\/GridCommon\.ini"?/m' =>
                        '$1"' . $dir . '/GridCommon.ini"',
                ]),
                $this->patch("$dir/GridCommon.ini", [
                    '/^(\s*Include-Storage\s*=.*)$/m' => ';$1',
                    '/^(\s*Include-FlotsamCache\s*=\s*)"?config-include\/FlotsamCache\.ini"?/m' =>
                        '$1"' . $dir . '/FlotsamCache.ini"',
                ]),
                $this->patch("$dir/osslDefaultEnable.ini", [
                    '/^(\s*Include-osslEnable\s*=\s*)"?config-include\/osslEnable\.ini"?/m' =>
                        '$1"' . $dir . '/osslEnable.ini"',
                ]),
                $this->enableOssl("$dir/osslEnable.ini"),
                $this->patch("$dir/FlotsamCache.ini", [
                    '/^(\s*CacheDirectory\s*=\s*)\.?\/?assetcache\s*$/m' => '$1"${Const|CacheDirectory}/assetcache"',
                ]),
            ]),
        );

        return array_values(array_unique($written));
    }

    /**
     * OSSL is part of the standard config: the functions are on, with the permissions of the defaults of the
     * core (osSetParcelDetails, which the region initialization script needs, is for the owner and the
     * managers of the estate).
     *
     * @return ?string the path when written
     */
    private function enableOssl(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $ini = Ini::load($path);
        $before = $ini->toString();
        if ($ini->get('OSSL', 'AllowOSFunctions') !== 'true') {
            $ini->set('OSSL', 'AllowOSFunctions', 'true');
        }
        if ($ini->toString() === $before) {
            return null;
        }
        $ini->save($path);

        return $path;
    }

    /** The file in the core, as it is or as an example. */
    private function source(string $binDir, string $file): ?string
    {
        foreach (["$binDir/config-include/$file", "$binDir/config-include/$file.example"] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Apply replacements to a file, written only when something changed.
     *
     * @param array<string,string> $replacements pattern => replacement
     * @return ?string the path when written
     */
    private function patch(string $path, array $replacements): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $before = (string) file_get_contents($path);
        $after = $before;
        foreach ($replacements as $pattern => $replacement) {
            $after = (string) preg_replace($pattern, $replacement, $after);
        }
        if ($after === $before) {
            return null;
        }
        file_put_contents($path, $after);

        return $path;
    }
}
