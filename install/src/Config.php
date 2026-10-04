<?php

declare(strict_types=1);

namespace OpenSim\Installer;

/**
 * Reader for the single opensim.conf (phase 2 only needs the default profile;
 * phase 3 will add writing).
 */
final class Config
{
    public function __construct(private readonly ?string $file = null) {}

    public function path(): ?string
    {
        if ($this->file !== null) {
            return is_file($this->file) ? $this->file : null;
        }

        $home = getenv('HOME') ?: '';
        $candidates = array_filter([
            $home !== '' ? "$home/.config/opensim/opensim.conf" : null,
            $home !== '' ? "$home/.opensim.conf" : null,
            '/etc/opensim/opensim.conf',
            dirname(__DIR__, 2) . '/config/opensim.conf',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Names of the installed profiles (every section except [Defaults]).
     *
     * @return list<string>
     */
    public function profiles(): array
    {
        $path = $this->path();
        if ($path === null) {
            return [];
        }

        $ini = parse_ini_file($path, true, INI_SCANNER_RAW) ?: [];

        return array_values(array_filter(array_keys($ini), static fn(string $s): bool => $s !== 'Defaults'));
    }

    /** Switch the default profile (updates the pointer and mirrors its values). */
    public function setDefaultProfile(string $name): void
    {
        $path = $this->path();
        if ($path === null) {
            return;
        }

        $data = parse_ini_file($path, true, INI_SCANNER_RAW) ?: [];
        $mirror = $data[$name] ?? ($data['Defaults'] ?? []);
        unset($mirror['DefaultProfile']);
        // Settings of [Defaults] that profiles don't hold (e.g. SystemUser) are kept
        $data['Defaults'] = ['DefaultProfile' => $name] + $mirror + ($data['Defaults'] ?? []);

        $this->save($path, $data);
    }

    public function defaultProfile(): ?string
    {
        $path = $this->path();
        if ($path === null) {
            return null;
        }

        $ini = @parse_ini_file($path, true, INI_SCANNER_RAW);

        return $ini['Defaults']['DefaultProfile'] ?? null;
    }

    /**
     * Resolved settings of an install profile: [Defaults] as the base, then the
     * named profile (or the default one) overriding it. Empty if no config.
     *
     * @return array<string,string>
     */
    public function profile(?string $name = null): array
    {
        $path = $this->path();
        if ($path === null) {
            return [];
        }

        $ini = parse_ini_file($path, true, INI_SCANNER_RAW) ?: [];
        $defaults = $ini['Defaults'] ?? [];
        $name ??= $defaults['DefaultProfile'] ?? null;
        unset($defaults['DefaultProfile']);

        $section = $name !== null && isset($ini[$name]) ? $ini[$name] : [];

        return array_merge($defaults, $section);
    }

    /**
     * Write the plan into $etcRoot/opensim.conf (additive: other profiles are
     * preserved), link it from ~/.config, and return the canonical path.
     */
    public function write(Plan $plan): string
    {
        $path = rtrim($plan->etcRoot, '/') . '/opensim.conf';
        $data = is_file($path) ? (parse_ini_file($path, true, INI_SCANNER_RAW) ?: []) : [];

        $profile = $this->profileValues($plan);
        $current = $data['Defaults']['DefaultProfile'] ?? null;

        if ($plan->makeDefault || $current === null) {
            // [Defaults] = pointer + a mirror of this profile's values, keeping
            // the settings profiles don't hold (e.g. SystemUser).
            $data['Defaults'] = ['DefaultProfile' => $plan->profile] + $profile + ($data['Defaults'] ?? []);
        } else {
            // Keep the existing default; just ensure the pointer stays first.
            $data['Defaults'] = ['DefaultProfile' => $current] + ($data['Defaults'] ?? []);
        }
        $data[$plan->profile] = $profile;

        $this->save($path, $data);
        $this->link($path);

        return $path;
    }

    /**
     * Register an install that exists (made by hand or by another tool): its base directories are the whole
     * profile. Only the core directory is required, the config and data ones default to it (an install by the book).
     * The default profile is left alone unless asked.
     *
     * @return array<string,string> the profile written
     */
    public function addProfile(
        string $name,
        string $core,
        ?string $etc = null,
        ?string $data = null,
        bool $makeDefault = false,
    ): array {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) || $name === 'Defaults') {
            throw new \InvalidArgumentException(sprintf(_('Invalid profile name: %s'), $name));
        }
        $core = rtrim($core, '/');
        $etc = rtrim($etc ?? $core, '/');
        $data = rtrim($data ?? $core, '/');
        $profile = [
            'DirectoryLayout' => 'flat',
            'CoreDirectory' => $core,
            'EtcRoot' => $etc,
            'DataRoot' => $data,
        ];

        $path = $this->path() ?? ($this->file ?? '/etc/opensim/opensim.conf');
        $all = is_file($path) ? (parse_ini_file($path, true, INI_SCANNER_RAW) ?: []) : [];
        $all[$name] = $profile;
        $current = $all['Defaults']['DefaultProfile'] ?? null;
        if ($makeDefault || $current === null) {
            $all['Defaults'] = ['DefaultProfile' => $name] + $profile + ($all['Defaults'] ?? []);
        }
        $this->save($path, $all);

        return $profile;
    }

    /** Forget a profile; the default one cannot be removed (choose another first). */
    public function removeProfile(string $name): bool
    {
        $path = $this->path();
        $all = $path === null ? [] : (parse_ini_file($path, true, INI_SCANNER_RAW) ?: []);
        if (!isset($all[$name]) || $name === 'Defaults') {
            return false;
        }
        if (($all['Defaults']['DefaultProfile'] ?? null) === $name) {
            throw new \RuntimeException(
                sprintf(_('%s is the default profile, make another one the default first'), $name),
            );
        }
        unset($all[$name]);
        $this->save($path, $all);

        return true;
    }

    /** @return array<string,string> */
    private function profileValues(Plan $plan): array
    {
        return [
            'DirectoryLayout' => $plan->layout,
            'OpensimVersion' => $plan->version,
            'InstallPath' => $plan->installPath,
            'CoreRoot' => $plan->coreRoot,
            'CoreDirectory' => $plan->coreDirectory,
            'EtcRoot' => $plan->etcRoot,
            'VarRoot' => $plan->varRoot,
            'LogsRoot' => $plan->logsRoot,
            'CacheRoot' => $plan->cacheRoot,
            'DataRoot' => $plan->dataRoot,
            'Runtime' => $plan->runtime,
        ];
    }

    /** @param array<string,array<string,string>> $data */
    private function save(string $path, array $data): void
    {
        $ordered = [];
        if (isset($data['Defaults'])) {
            $ordered['Defaults'] = $data['Defaults']; // always first
        }
        foreach ($data as $section => $values) {
            if ($section !== 'Defaults') {
                $ordered[$section] = $values;
            }
        }

        $out = '';
        foreach ($ordered as $section => $values) {
            $out .= "[$section]\n";
            foreach ($values as $key => $value) {
                $out .= "$key = $value\n";
            }
            $out .= "\n";
        }

        is_dir(dirname($path)) || mkdir(dirname($path), 0o755, true);
        file_put_contents($path, $out);
    }

    private function link(string $path): void
    {
        $home = getenv('HOME') ?: '';
        if ($home === '') {
            return;
        }
        $link = "$home/.config/opensim/opensim.conf";
        @mkdir(dirname($link), 0o755, true);
        if (is_link($link) || is_file($link)) {
            @unlink($link);
        }
        @symlink($path, $link);
    }
}
