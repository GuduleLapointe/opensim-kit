<?php

declare(strict_types=1);

namespace OpenSim\Installer\Grid;

/**
 * Everything gathered to (re)configure one grid, before anything is written.
 */
final class GridPlan
{
    public string $gridName = '';
    public string $gridNick = '';
    public string $gridSlug = '';
    public bool $enableHypergrid = true;
    /** Free blocks the regions of the grid leave between them (0: side by side). */
    public int $regionSpacing = 0;
    /** The center of the grid, "x,y": where the search of a place for a region starts. */
    public string $center = '';

    // Selected core (multi-version aware).
    public string $coreDirectory = '';
    public string $binDir = '';

    public string $baseHostname = '';
    public int $publicPort = 8002;
    public int $privatePort = 8003;
    public string $webUrl = '';

    // The console: 'rest' (remote, through its port: from another machine or a
    // container) or 'screen' (a session to attach on this machine).
    public string $consoleMode = 'rest';
    public int $consolePort = 0;
    /** The name clients use to reach the console (ConsoleHost, read by the helpers): the address of the machine. */
    public string $consoleHost = '';
    public string $consoleUser = '';
    public string $consolePass = '';

    /** The helpers of the grid (economy, search, offline messages) are served by the web site: written in helpers.ini. */
    public bool $helpers = false;
    /** Where the helpers are on the web site. */
    public string $helpersPath = '/helpers';
    /** @var array<string,string> The paths the operator gave to some services in the helpers.ini of the grid ([Urls]). */
    public array $helpersUrls = [];

    // Database.
    public string $dbHost = 'localhost';
    public string $dbName = '';
    public string $dbUser = 'opensim';
    public string $dbPass = 'changeme';

    // Derived per-grid locations (filled from the active install profile).
    public string $gridDir = '';
    public string $etcDirectory = '';
    public string $dataDirectory = '';
    public string $cacheDirectory = '';
    public string $logsDirectory = '';

    // What to do once written, asked before anything is written.
    public bool $enable = true;
    public bool $start = true;

    /** The plan as plain values, to hand it to the process that writes it. */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): self
    {
        $plan = new self();
        foreach ($values as $name => $value) {
            if (property_exists($plan, $name)) {
                $plan->$name = $value;
            }
        }

        return $plan;
    }

    public function robustIni(): string
    {
        return $this->gridDir . '/' . ($this->enableHypergrid ? 'Robust.HG.ini' : 'Robust.ini');
    }

    public function connectionString(): string
    {
        return sprintf(
            'Data Source=%s;Database=%s;User ID=%s;Password=%s;Old Guids=true;',
            $this->dbHost,
            $this->dbName,
            $this->dbUser,
            $this->dbPass,
        );
    }
}
