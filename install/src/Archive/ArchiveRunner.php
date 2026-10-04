<?php

declare(strict_types=1);

namespace OpenSim\Installer\Archive;

use OpenSim\Installer\Console;
use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Grid\RegionState;
use OpenSim\Installer\Grid\SimState;

/**
 * `opensim save|load iar|oar`: find the simulator, the region and the file, give the console of the simulator its command,
 * and wait for its log to say how it went.
 */
final class ArchiveRunner
{
    /** What the log of a simulator says when the command is over, by command: [success, failure] */
    private const ENDS = [
        'save iar' => [
            '/Saved archive with \d+ items/',
            '/Archive save for .* failed|Password for user .* incorrect|User .* not found|Inventory path .* does not exist|Failed to find user info/',
        ],
        'load iar' => [
            '/Loaded \d+ items from archive/',
            '/Archive load for .* failed|Password for user .* incorrect|User .* not found|Failed to find user info|Inventory path .* does not exist/',
        ],
        'save oar' => [
            '/Finished writing out OAR/',
            '/Error closing archive|Terminating archive creation|Aborted because/',
        ],
        'load oar' => [
            '/Successfully loaded archive/',
            '/Aborting load with error|Control file not found|Error reading archive|Error loading|Not loading archived region/',
        ],
    ];

    /**
     * @param array<string,mixed> $profile the install profile
     * @param \Closure(string,string):bool $send gives the lines to the console of an instance
     * @param \Closure(string):bool $running whether the instance of a config runs
     * @param \Closure(string):void $say what the person is told
     */
    public function __construct(
        private array $profile,
        private \Closure $send,
        private \Closure $running,
        private \Closure $say,
        private int $timeout = 1800,
        private ?\Closure $ask = null,
    ) {}

    public static function system(array $profile, int $timeout = 1800): self
    {
        return new self(
            $profile,
            static fn(string $instance, string $lines): bool => Console::send($instance, $lines),
            static fn(string $ini): bool => Console::running($ini),
            static function (string $message): void {
                fwrite(STDERR, $message . "\n");
            },
            $timeout,
            static function (string $first, string $last): string {
                if (!function_exists('posix_isatty') || !posix_isatty(STDIN)) {
                    return '';
                }
                fwrite(STDERR, "Password of $first $last: ");
                system('stty -echo');
                $password = trim((string) fgets(STDIN));
                system('stty echo');
                fwrite(STDERR, "\n");

                return $password;
            },
        );
    }

    /**
     * @param list<string> $args what follows `save` or `load`
     * @return array{0:int,1:?string} the exit code and the file
     */
    public function run(string $verb, array $args): array
    {
        try {
            return $this->execute(Archives::parse($verb, $args));
        } catch (\InvalidArgumentException $e) {
            ($this->say)("$verb: " . $e->getMessage());

            return [2, null];
        } catch (\RuntimeException $e) {
            ($this->say)("$verb: " . $e->getMessage());

            return [1, null];
        }
    }

    /**
     * @param array{verb:string,kind:string,options:array<string,string|true>,words:list<string>} $spec
     * @return array{0:int,1:?string}
     */
    private function execute(array $spec): array
    {
        ['verb' => $verb, 'kind' => $kind, 'options' => $options] = $spec;
        $keep = isset($options['keep']) ? Rotation::count($options['keep']) : null;
        [$refs, $words] = $this->takeInstance($kind, $spec['words']);
        $given = Archives::positional($verb, $kind, $words);
        foreach (Archives::required($verb, $kind) as $name) {
            if (!isset($given[$name])) {
                throw new \InvalidArgumentException("missing argument <$name>");
            }
        }

        [$nick, $slug, $named] = Instances::locate($this->profile, $refs);
        $grid = GridInfo::load($this->profile, $nick) ?? throw new \RuntimeException("grid '$nick' is not known here");
        if ($named !== null && !isset($options['region'])) {
            $options['region'] = $named;
        }
        $slug ??= $this->pickSimulator($grid, $kind === 'oar');
        $directory = rtrim($grid->dataDirectory, '/') . "/backups/$kind";

        $lines = [];
        $region = null;
        $arguments = [];
        if ($kind === 'oar') {
            $region = $this->region($grid, $slug, $options);
            // No region is all of them: the root of the console
            $lines[] = 'change region ' . ($region ?? 'root');
            $sim = $this->simName($grid, $slug);
            $prefix = Archives::oarPrefix($grid->nick, $sim, isset($options['all']) ? null : $region);
            $name =
                $verb === 'save' ? Archives::oarName($grid->nick, $sim, $region, $options, Archives::stamp()) : null;
        } else {
            $prefix = Archives::iarPrefix($grid->nick, $given['first'], $given['last']);
            $name =
                $verb === 'save'
                    ? Archives::iarName($grid->nick, $given['first'], $given['last'], $options, Archives::stamp())
                    : null;
            $arguments = [$given['first'], $given['last'], $given['path'], $this->password($given)];
        }

        $file = $this->file($verb, $kind, $given['file'] ?? null, $directory, $name, $prefix);
        $arguments[] = $file;
        $lines[] = Archives::line($verb, $kind, $options, $arguments);

        $log = rtrim($grid->logsDirectory, '/') . "/$slug.log";
        $mark = is_file($log) ? (int) filesize($log) : 0;
        if (!($this->send)($slug, implode("\n", $lines) . "\n")) {
            throw new \RuntimeException("$slug does not answer: is it running?");
        }
        ($this->say)(sprintf('%s %s %s ... (%s)', $verb === 'save' ? 'saving' : 'loading', $kind, $file, $slug));

        [$ok, $message] = $this->waitFor("$verb $kind", $log, $mark);
        if (!$ok) {
            throw new \RuntimeException($message);
        }
        if ($verb === 'save' && !is_file($file)) {
            throw new \RuntimeException("the simulator says it is done, but there is no $file");
        }
        ($this->say)($message);
        if ($verb === 'save' && $keep !== null && ($removed = Rotation::keep($file, $keep)) !== []) {
            ($this->say)('removed ' . count($removed) . ' older ' . $kind . ' file' . (count($removed) > 1 ? 's' : ''));
        }

        return [0, $file];
    }

    /**
     * The instance words at the start of the others: for an inventory a grid, for a region a grid and a simulator or a
     * region of it, or one of these alone. What is not one is for the command: a name, a file, a password.
     *
     * @param list<string> $words
     * @return array{0:list<string>,1:list<string>} the instance words, the other ones
     */
    private function takeInstance(string $kind, array $words): array
    {
        $refs = [];
        $grids = Instances::grids($this->profile['EtcRoot'] ?? '');
        if ($words === []) {
            return [[], []];
        }
        // The names of an account follow an inventory: the first word is taken for the instance only when it is a grid
        if ($kind === 'iar' && !in_array($words[0], $grids, true)) {
            return [[], $words];
        }
        if ($this->names([$words[0]])) {
            $first = array_shift($words);
            $refs[] = $first;
            if (in_array($first, $grids, true) && $words !== [] && $this->names([$first, $words[0]])) {
                $refs[] = array_shift($words);
            }
        }

        return [$refs, $words];
    }

    /** Whether the words name an instance of the install */
    private function names(array $refs): bool
    {
        try {
            Instances::locate($this->profile, $refs);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** A simulator of the grid that runs: any for a command that is the grid's (an inventory), the only one for a region */
    private function pickSimulator(GridInfo $grid, bool $onlyOne): string
    {
        $etc = $this->profile['EtcRoot'] ?? '';
        $names = SimState::names($grid->dir);
        $up = array_values(
            array_filter($names, fn(string $slug): bool => ($this->running)(SimState::link($etc, $slug))),
        );
        if ($up === []) {
            throw new \RuntimeException(
                "no simulator of the grid '{$grid->nick}' runs" .
                    ($names === [] ? ' (it has none)' : ': start one of ' . implode(', ', $names)),
            );
        }

        if ($onlyOne && count($up) > 1) {
            throw new \InvalidArgumentException('which simulator? ' . implode(', ', $up));
        }

        return $up[0];
    }

    private function simName(GridInfo $grid, string $slug): string
    {
        $prefix = GridInfo::instanceName($grid->nick) . '_';

        return str_starts_with($slug, $prefix) ? substr($slug, strlen($prefix)) : $slug;
    }

    /**
     * The region of a command on a region: the one asked, or the only one of the simulator; with --all, the one that is
     * given or none (all the regions).
     *
     * @param array<string,string|true> $options
     */
    private function region(GridInfo $grid, string $slug, array $options): ?string
    {
        $names = array_keys(
            array_filter(
                RegionState::list("{$grid->dir}/sims/$slug/regions"),
                static fn(array $region): bool => $region['enabled'],
            ),
        );
        if (isset($options['region'])) {
            if ($names !== [] && !in_array($options['region'], $names, true)) {
                throw new \InvalidArgumentException(
                    "'{$options['region']}' is not a region of $slug (" . implode(', ', $names) . ')',
                );
            }

            return (string) $options['region'];
        }
        if (isset($options['all'])) {
            return null;
        }
        if (count($names) === 1) {
            return $names[0];
        }

        throw new \InvalidArgumentException(
            $names === []
                ? "$slug has no region"
                : "$slug has several regions, give one with --region (" .
                    implode(', ', $names) .
                    ') or all of them with --all',
        );
    }

    /** The password of the account, given, from OPENSIM_IAR_PASSWORD, or asked without echo */
    private function password(array $given): string
    {
        if (isset($given['password'])) {
            return $given['password'];
        }
        $env = getenv('OPENSIM_IAR_PASSWORD');
        if ($env !== false && $env !== '') {
            return $env;
        }
        // Asked only by who has a way to ask (the command line, on a terminal)
        $password = $this->ask !== null ? ($this->ask)($given['first'], $given['last']) : '';
        if ($password === '') {
            throw new \InvalidArgumentException('missing argument <password> (or OPENSIM_IAR_PASSWORD)');
        }

        return $password;
    }

    /**
     * The file: the one given (a bare name is in the folder of the archives, a path is where it says, from the folder the
     * command was typed in: the simulator runs elsewhere), else a new name to save to, else the newest archive of the
     * inventory or the region.
     */
    private function file(
        string $verb,
        string $kind,
        ?string $given,
        string $directory,
        ?string $name,
        string $prefix,
    ): string {
        if ($given !== null) {
            if (!str_contains($given, '/')) {
                $given = "$directory/$given";
            } elseif (!str_starts_with($given, '/')) {
                $given = getcwd() . '/' . $given;
            }
        } elseif ($verb === 'save') {
            $given = "$directory/$name";
        } else {
            $given =
                Archives::newest($directory, $prefix, $kind) ??
                throw new \RuntimeException("no $kind archive starting with $prefix in $directory, give the file");
            ($this->say)("the newest archive is $given");
        }
        if ($verb === 'save') {
            $folder = dirname($given);
            if (!is_dir($folder) && !@mkdir($folder, 0770, true) && !is_dir($folder)) {
                throw new \RuntimeException("cannot make the folder $folder");
            }
            if (is_file($given)) {
                throw new \RuntimeException("$given exists already");
            }
        } elseif (!is_file($given)) {
            throw new \RuntimeException("no such file: $given");
        }

        return $given;
    }

    /**
     * Wait for the log of the simulator to say the command is over.
     *
     * @return array{0:bool,1:string} whether it went well, and the line that says it
     */
    private function waitFor(string $command, string $log, int $mark): array
    {
        [$success, $failure] = self::ENDS[$command];
        $end = time() + $this->timeout;
        do {
            $text = is_file($log) ? (string) @file_get_contents($log, false, null, $mark) : '';
            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                if (preg_match($failure, $line)) {
                    return [false, trim(preg_replace('/^\S+ \S+ +\w+ +/', '', $line) ?? $line)];
                }
            }
            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                if (preg_match($success, $line)) {
                    return [true, trim(preg_replace('/^\S+ \S+ +\w+ +/', '', $line) ?? $line)];
                }
            }
            if ($this->timeout === 0) {
                break;
            }
            usleep(500000);
        } while (time() < $end);

        return [false, "the simulator did not say it was done in {$this->timeout} seconds: see $log"];
    }
}
