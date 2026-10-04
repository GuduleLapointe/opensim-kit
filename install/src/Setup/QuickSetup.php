<?php

declare(strict_types=1);

namespace OpenSim\Installer\Setup;

use OpenSim\Installer\Grid\GridAccounts;
use OpenSim\Installer\Grid\NewGrid;
use OpenSim\Installer\Ui\InstallerUi;

/**
 * The quick setup: one screen with what a grid needs from its owner (its name, where it is reached, its
 * owner), the defaults of the setup for everything else, shown before anything is written. The database
 * is the setup's business: its user and password are the defaults, and they are asked only when the
 * setup cannot make the database by itself.
 */
final class QuickSetup
{
    public function __construct(private InstallerUi $ui) {}

    /**
     * What the form gives, as a setup (see SetupFile): a grid, its owner, one simulator with its first region.
     *
     * @return array<string,mixed>
     */
    public function ask(): array
    {
        $this->ui->note(
            _(
                'A grid and its first region, with the usual settings: you see them before anything is written, and can edit them.',
            ),
        );
        $v = $this->ui->form(
            [
                ['key' => 'name', 'label' => _('Grid name'), 'default' => NewGrid::defaultName()],
                [
                    'key' => 'login',
                    'label' => _('Login URI'),
                    'default' => 'http://' . NewGrid::defaultHost() . ':' . NewGrid::defaultPublicPort(),
                    'hint' => _('host:port, the address the viewers log in to'),
                    'validate' => static fn(string $v): ?string => self::login($v) !== null
                        ? null
                        : _('host:port, e.g. play.example.org:8002.'),
                ],
                [
                    'key' => 'owner',
                    'label' => _('Grid owner'),
                    'hint' => _('First Last: the first account, owner of the first estate'),
                    'validate' => static fn(string $v): ?string => GridAccounts::validName(trim($v))
                        ? null
                        : _('First and last name, e.g. Jane Doe.'),
                ],
                [
                    'key' => 'password',
                    'label' => _('Owner password'),
                    'type' => 'secret',
                    'validate' => static fn(string $v): ?string => preg_match('/^[^"\r\n]{6,}$/', $v)
                        ? null
                        : _('At least 6 characters, without double quote.'),
                ],
                [
                    'key' => 'email',
                    'label' => _('Owner email'),
                    'required' => false,
                    'hint' => _('Optional'),
                    'validate' => static fn(string $v): ?string => preg_match(
                        '/^[^\s"\'\\\\]+@[^\s"\'\\\\]+$/',
                        trim($v),
                    )
                        ? null
                        : _('An email address, or nothing.'),
                ],
            ],
            _('Quick setup'),
        );

        [$host, $port] = self::login($v['login']) ?? ['localhost', 8002];
        $owner = ['name' => $v['owner'], 'password' => $v['password']];
        if ($v['email'] !== '') {
            $owner['email'] = $v['email'];
        }

        return [
            'grid' => ['name' => $v['name'], 'hostname' => $host, 'public_port' => $port],
            'owner' => $owner,
            // The first simulator and its region: the defaults of the setup
            'simulators' => [[]],
        ];
    }

    /**
     * The host and the port of a login URI, given as host:port or with the scheme.
     *
     * @return ?array{0:string,1:int}
     */
    public static function login(string $uri): ?array
    {
        return preg_match('~^(?:https?://)?([A-Za-z0-9][A-Za-z0-9.-]*):(\d{2,5})/?$~', trim($uri), $m) === 1 &&
            (int) $m[2] <= 65535
            ? [$m[1], (int) $m[2]]
            : null;
    }
}
