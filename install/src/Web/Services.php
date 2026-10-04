<?php

declare(strict_types=1);

namespace OpenSim\Installer\Web;

/**
 * The web services of a grid, the scripts of opensim-helpers that answer them, and the public path of
 * each: `/helpers/<script>` unless the grid says otherwise ([Urls] of its helpers.ini), so an
 * operator who has always had `/helper`, or `/search`, keeps his URLs.
 *
 * The helpers route any URL the web server sends them (see `index.php` of the helpers): the paths here are the
 * routes of their `config.php` (OPENSIM_ROUTES).
 */
final class Services
{
    /** The service and the script of the helpers that answers it. */
    public const SCRIPTS = [
        'search' => 'query.php',
        'register' => 'register.php',
        'offline' => 'offline.php',
        'currency' => 'currency.php',
        'guide' => 'guide.php',
        'motd' => 'motd.php',
        'landtool' => 'landtool.php',
        'parser' => 'parser.php',
        'eventsparser' => 'eventsparser.php',
        'textgen' => 'textgen.php',
        'directory_info' => 'directory_info.php',
    ];

    /** The pages of the helpers and their default path: the home page, the splash page of the viewer. */
    public const PAGES = ['home' => '/', 'welcome' => '/welcome'];

    /**
     * @param string               $base the path the helpers are served under (`/helpers`)
     * @param array<string,string> $urls the paths the grid gives to some services
     */
    public function __construct(private string $base = '/helpers', private array $urls = []) {}

    /** A path as the web server has it: a slash first, none at the end (the root is `/`). */
    public static function normalize(string $path): string
    {
        $path = '/' . trim(trim($path), '/');

        return $path;
    }

    /** The base path, and an operator's own path for a service. */
    public function base(): string
    {
        return self::normalize($this->base);
    }

    /** The public path of a service: the one of the grid, else the script under the base path. */
    public function path(string $service): string
    {
        if (isset(self::PAGES[$service])) {
            $own = trim($this->urls[$service] ?? '');

            return $own !== '' ? self::normalize($own) : self::PAGES[$service];
        }
        $script = self::SCRIPTS[$service] ?? $service;
        $name = array_search($script, self::SCRIPTS, true);
        $own = $name !== false ? trim($this->urls[$name] ?? '') : '';

        return $own !== '' ? self::normalize($own) : rtrim($this->base(), '/') . '/' . $script;
    }

    /** The address of a service on a web site. */
    public function url(string $webUrl, string $service): string
    {
        return rtrim($webUrl, '/') . $this->path($service);
    }

    /**
     * What a service with its own path needs from the web server: it answers at that path with the
     * script, outside the base path.
     *
     * @return array<string,string> service => its path, only for the services that are not under the base
     */
    public function aliases(): array
    {
        $aliases = [];
        foreach (array_keys(self::SCRIPTS) as $service) {
            $default = rtrim($this->base(), '/') . '/' . self::SCRIPTS[$service];
            if ($this->path($service) !== $default) {
                $aliases[$service] = $this->path($service);
            }
        }

        return $aliases;
    }
}
