<?php

declare(strict_types=1);

namespace OpenSim\Installer;

use OpenSim\Installer\Ui\InstallerUi;

/**
 * Hands the writing of the files and the instances to the system user of the
 * install (the packages): the questions, and the database with the rights of
 * the user who started the setup, stay with this process.
 */
final class Elevated
{
    /**
     * Whether the writing belongs to another process: the install has a system
     * user, and this process is neither that user nor root.
     *
     * @param array<string,mixed> $profile
     */
    public static function needed(array $profile): bool
    {
        $user = $profile['SystemUser'] ?? '';
        if ($user === '' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
            return false;
        }

        return (posix_getpwuid(posix_geteuid())['name'] ?? '') !== $user;
    }

    /**
     * Run the writing as the system user: install.php with the given flag,
     * the payload on its standard input (not its arguments: it can hold a
     * password).
     *
     * @param array<string,mixed> $payload
     * @throws SetupFailed when it fails
     */
    public static function run(InstallerUi $ui, string $flag, array $payload, string $user): void
    {
        $process = proc_open(
            ['sudo', '-H', '-u', $user, PHP_BINARY, dirname(__DIR__) . '/install.php', $flag],
            [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
            $pipes,
        );
        if (!is_resource($process)) {
            $ui->error(sprintf(_('Could not run the writing as %s.'), $user));

            throw new SetupFailed('write');
        }
        fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        if (proc_close($process) !== 0) {
            throw new SetupFailed('write');
        }
    }
}
