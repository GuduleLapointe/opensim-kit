<?php

declare(strict_types=1);

namespace OpenSim\Installer\Grid;

/**
 * A grid already configured, as its files tell it: what a simulator needs to
 * join it (hostname, ports, core, database of the grid, folders).
 */
final class GridInfo
{
    public string $nick = '';
    public string $name = '';
    public string $slug = '';
    public bool $hypergrid = true;
    /** Free blocks the regions leave between them, the rule of the grid (a grid only described here follows the default one). */
    public int $regionSpacing = 0;
    /** The center of the grid, "x,y", empty when it has none (the one its port gives). */
    public string $center = '';
    /** @var list<string> The roles the regions of the Robust config have (see RegionFlags); empty for a grid described only. */
    public array $regionFlags = [];
    public string $dir = '';
    public string $robustIni = '';
    public string $coreDirectory = '';
    public string $baseHostname = '';
    public int $publicPort = 8002;
    public int $privatePort = 8003;
    public string $dbHost = 'localhost';
    public string $dbName = '';
    public string $dbUser = 'opensim';
    public string $dbPass = '';
    public string $dataDirectory = '';
    public string $cacheDirectory = '';
    public string $logsDirectory = '';
    /** A grid whose Robust is on another machine: only described here, to join it. */
    public bool $remote = false;
    /** A grid that is only planned, not made yet (the quick setup plans its first simulator before making anything). */
    public bool $planned = false;
    /** The helpers of the grid, when it has some: where they are on its web site (see helperUrl()). */
    public string $webUrl = '';
    public bool $helpers = false;
    public string $helpersPath = '/helpers';
    /** @var array<string,string> */
    public array $helpersUrls = [];

    /**
     * @param array<string,mixed> $profile the install profile (Config::profile())
     */
    /** The center of the grid: the one it was given, else the one its port gives. */
    public function centerPlace(): array
    {
        return LocationFinder::parse($this->center) ?? LocationFinder::first($this->publicPort);
    }

    public static function load(array $profile, string $nick): ?self
    {
        $etcRoot = $profile['EtcRoot'] ?? '';
        $robustIni = GridState::robustIni($etcRoot, $nick);
        if ($robustIni === null) {
            return self::loadRemote($profile, $nick);
        }

        $current = self::parse($robustIni);
        $conf = @parse_ini_file("$etcRoot/grids/$nick/$nick.conf", true, INI_SCANNER_RAW)['Grid'] ?? [];
        $conf = array_map(static fn($value): string => trim((string) $value, " \t\""), $conf);

        $grid = new self();
        $grid->nick = $nick;
        $grid->dir = "$etcRoot/grids/$nick";
        $grid->robustIni = $robustIni;
        $grid->hypergrid = str_contains(basename($robustIni), '.HG.');
        $grid->name = $current['gridName'] ?? ($conf['GridName'] ?? ucfirst($nick));
        $grid->slug = $conf['slug'] ?? Slug::slug($grid->name);
        $grid->regionSpacing = ctype_digit($conf['RegionSpacing'] ?? '') ? (int) $conf['RegionSpacing'] : 0;
        $grid->center = $conf['Center'] ?? '';
        $grid->regionFlags = $current['regionFlags'] ?? [];
        $grid->coreDirectory = $conf['CoreDirectory'] ?? ($profile['CoreDirectory'] ?? '');
        $grid->baseHostname = $current['baseHostname'] ?? 'localhost';
        $grid->publicPort = $current['publicPort'] ?? 8002;
        $grid->privatePort = $current['privatePort'] ?? 8003;
        $grid->dbHost = $current['dbHost'] ?? 'localhost';
        $grid->dbName = $current['dbName'] ?? '';
        $grid->dbUser = $current['dbUser'] ?? 'opensim';
        $grid->dbPass = $current['dbPass'] ?? '';
        $grid->dataDirectory = $conf['DataDirectory'] ?? ($profile['DataRoot'] ?? '') . "/$nick";
        $grid->cacheDirectory = $conf['CacheDirectory'] ?? ($profile['CacheRoot'] ?? '') . "/$nick";
        $grid->logsDirectory = $conf['LogsDirectory'] ?? ($profile['LogsRoot'] ?? '');

        return $grid;
    }

    /** A grid that is planned, to plan its simulator before it is made: nothing of it is on disk or in the database yet. */
    public static function fromPlan(GridPlan $plan): self
    {
        $grid = new self();
        $grid->planned = true;
        $grid->nick = $plan->gridNick;
        $grid->name = $plan->gridName;
        $grid->slug = $plan->gridSlug;
        $grid->dir = $plan->gridDir;
        $grid->robustIni = $plan->robustIni();
        $grid->hypergrid = $plan->enableHypergrid;
        $grid->regionSpacing = $plan->regionSpacing;
        $grid->center = $plan->center;
        $grid->coreDirectory = $plan->coreDirectory;
        $grid->baseHostname = $plan->baseHostname;
        $grid->publicPort = $plan->publicPort;
        $grid->privatePort = $plan->privatePort;
        $grid->dbHost = $plan->dbHost;
        $grid->dbName = $plan->dbName;
        $grid->dbUser = $plan->dbUser;
        $grid->dbPass = $plan->dbPass;
        $grid->dataDirectory = $plan->dataDirectory;
        $grid->cacheDirectory = $plan->cacheDirectory;
        $grid->logsDirectory = $plan->logsDirectory;
        $grid->helpers = $plan->helpers;
        $grid->webUrl = $plan->webUrl;
        $grid->helpersPath = $plan->helpersPath;
        $grid->helpersUrls = $plan->helpersUrls;

        return $grid;
    }

    /**
     * The address of a service of the helpers (offline, register, search...), empty when the grid has none.
     */
    public function helperUrl(string $service): string
    {
        if ($this->planned) {
            return $this->helpers
                ? (new \OpenSim\Installer\Web\Services($this->helpersPath, $this->helpersUrls))->url(
                    $this->webUrl,
                    $service,
                )
                : '';
        }
        $ini = \OpenSim\Installer\Web\HelpersConfig::read($this->dir);
        $webUrl = $ini['Helpers']['web_url'] ?? '';
        if ($webUrl === '') {
            return '';
        }

        return \OpenSim\Installer\Web\HelpersConfig::services($this->dir)->url($webUrl, $service);
    }

    /**
     * The roles no region of a grid run from this machine has yet (see RegionFlags::roles()),
     * to offer them to the next region.
     *
     * @return list<string>
     */
    public function missingRoles(): array
    {
        return $this->remote ? [] : array_values(array_diff(RegionFlags::roles($this->hypergrid), $this->regionFlags));
    }

    /**
     * Whether a grid run from this machine still needs its default region (the one visitors
     * arrive in), which comes first: until it exists nobody can log in.
     */
    public function needsLandingRegion(): bool
    {
        return array_intersect($this->missingRoles(), [RegionFlags::DEFAULT, RegionFlags::DEFAULT_HG]) !== [];
    }

    /** Whether the grid is only described here, its Robust being on another machine. */
    public static function isRemote(string $etcRoot, string $nick): bool
    {
        return GridState::robustIni($etcRoot, $nick) === null &&
            self::remoteValues("$etcRoot/grids/$nick/$nick.conf") !== null;
    }

    /** @return array<string,string>|null the [Grid] of a description of a remote grid */
    private static function remoteValues(string $conf): ?array
    {
        $grid = @parse_ini_file($conf, true, INI_SCANNER_RAW)['Grid'] ?? null;
        if (
            !is_array($grid) ||
            !in_array(strtolower(trim((string) ($grid['Remote'] ?? ''))), ['true', '1', 'yes'], true)
        ) {
            return null;
        }

        return array_map(static fn($value): string => trim((string) $value, " \t\""), $grid);
    }

    /** A remote grid, from its description. */
    private static function loadRemote(array $profile, string $nick): ?self
    {
        $etcRoot = $profile['EtcRoot'] ?? '';
        $conf = self::remoteValues("$etcRoot/grids/$nick/$nick.conf");
        if ($conf === null) {
            return null;
        }

        $grid = new self();
        $grid->remote = true;
        $grid->nick = $nick;
        $grid->dir = "$etcRoot/grids/$nick";
        $grid->name = $conf['GridName'] ?? ucfirst($nick);
        $grid->slug = $conf['slug'] ?? Slug::slug($grid->name);
        $grid->hypergrid = !in_array(strtolower($conf['Hypergrid'] ?? 'true'), ['false', '0', 'no'], true);
        $grid->baseHostname = $conf['BaseHostname'] ?? 'localhost';
        $grid->publicPort = (int) ($conf['PublicPort'] ?? 8002);
        $grid->privatePort = (int) ($conf['PrivatePort'] ?? 8003);
        $grid->coreDirectory = $conf['CoreDirectory'] ?? ($profile['CoreDirectory'] ?? '');
        $grid->dbHost = 'localhost';
        $grid->dbName = '';
        $grid->dbPass = '';
        $grid->dataDirectory = $conf['DataDirectory'] ?? ($profile['DataRoot'] ?? '') . "/$nick";
        $grid->cacheDirectory = $conf['CacheDirectory'] ?? ($profile['CacheRoot'] ?? '') . "/$nick";
        $grid->logsDirectory = $conf['LogsDirectory'] ?? ($profile['LogsRoot'] ?? '');

        return $grid;
    }

    /** The description of a remote grid, to keep it: what a simulator needs to join it. */
    public function describe(): array
    {
        return [
            'nick' => $this->nick,
            'name' => $this->name,
            'slug' => $this->slug,
            'baseHostname' => $this->baseHostname,
            'publicPort' => $this->publicPort,
            'privatePort' => $this->privatePort,
            'hypergrid' => $this->hypergrid,
            'dataDirectory' => $this->dataDirectory,
            'cacheDirectory' => $this->cacheDirectory,
            'logsDirectory' => $this->logsDirectory,
            'dir' => $this->dir,
        ];
    }

    /** The name of the instance running its Robust: what the launcher makes of the config file name. */
    public function robustInstance(): string
    {
        return self::instanceName($this->nick);
    }

    /** The name the launcher gives an instance: snake_case, the words kept apart (see Slug). */
    public static function instanceName(string $name): string
    {
        return Slug::slug($name);
    }

    /** The grid's own database settings, to ask questions of it. */
    public function databasePlan(): GridPlan
    {
        $plan = new GridPlan();
        $plan->dbHost = $this->dbHost;
        $plan->dbName = $this->dbName;
        $plan->dbUser = $this->dbUser;
        $plan->dbPass = $this->dbPass;

        return $plan;
    }

    /**
     * Current settings of an existing Robust or simulator config (tolerant
     * regex, comments and quotes ignored).
     *
     * @return array<string,string|int|list<string>>
     */
    public static function parse(string $path): array
    {
        $text = (string) file_get_contents($path);
        $current = [];

        $grab = static function (string $key) use ($text): ?string {
            return preg_match('/^\s*' . $key . '\s*=\s*"?([^"\n]+?)"?\s*$/im', $text, $m) ? trim($m[1]) : null;
        };

        foreach (['gridName' => 'gridname', 'baseHostname' => 'BaseHostname', 'webUrl' => 'WebURL'] as $field => $key) {
            $value = $grab($key);
            if ($value !== null) {
                $current[$field] = $value;
            }
        }
        foreach (
            ['publicPort' => 'PublicPort', 'privatePort' => 'PrivatePort', 'httpPort' => 'http_listener_port']
            as $field => $key
        ) {
            if (($port = $grab($key)) !== null && ctype_digit($port)) {
                $current[$field] = (int) $port;
            }
        }
        if (
            preg_match(
                '/Data Source=([^;"\s]*);Database=([^;"\s]*);User ID=([^;"\s]*);Password=([^;"]*?);/i',
                $text,
                $m,
            )
        ) {
            $current['dbHost'] = $m[1];
            $current['dbName'] = $m[2];
            $current['dbUser'] = $m[3];
            $current['dbPass'] = $m[4];
        }
        // The remote console, when the config has one (its port is ConsolePort in Robust, console_port in a simulator)
        foreach (
            ['consoleHost' => 'ConsoleHost', 'consoleUser' => 'ConsoleUser', 'consolePass' => 'ConsolePass']
            as $field => $key
        ) {
            $value = $grab($key);
            if ($value !== null && $value !== '') {
                $current[$field] = $value;
            }
        }
        if (($port = $grab('ConsolePort') ?? $grab('console_port')) !== null && ctype_digit($port) && (int) $port > 0) {
            $current['consolePort'] = (int) $port;
        }
        // The roles of the regions: Region_<Name> = "DefaultRegion, ..."
        if (($flags = RegionFlags::found($text)) !== []) {
            $current['regionFlags'] = $flags;
        }
        foreach (['estateName' => 'DefaultEstateName', 'estateOwner' => 'DefaultEstateOwnerName'] as $field => $key) {
            $value = $grab($key);
            if ($value !== null) {
                $current[$field] = $value;
            }
        }

        return $current;
    }
}
