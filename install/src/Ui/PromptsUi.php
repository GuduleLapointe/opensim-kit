<?php

declare(strict_types=1);

namespace OpenSim\Installer\Ui;

use OpenSim\Installer\Ui\Prompts\ConfirmPrompt;
use OpenSim\Installer\Ui\Prompts\FormPrompt;
use OpenSim\Installer\Ui\Prompts\MultiSelectPrompt;
use OpenSim\Installer\Ui\Prompts\PasswordPrompt;
use OpenSim\Installer\Ui\Prompts\SelectPrompt;
use OpenSim\Installer\Ui\Prompts\TextPrompt;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Text/CLI frontend built on Laravel Prompts (arrow-key selection, inline
 * validation, spinners), with the shortcuts of a terminal: Escape gives up the screen
 * (Back), Ctrl-Q leaves the setup (Quit), Ctrl-U and Ctrl-W erase in a text.
 */
final class PromptsUi implements InstallerUi
{
    public function intro(string $title): void
    {
        intro($title);
    }

    public function note(string $message): void
    {
        note($message);
    }

    public function entity(string $name): string
    {
        return "\e[36m$name\e[39m";
    }

    public function warn(string $message): void
    {
        warning($message);
    }

    public function error(string $message): void
    {
        error($message);
    }

    public function outro(string $message): void
    {
        outro($message);
    }

    public function choose(string $label, array $options, ?string $default = null, ?string $hint = null): string
    {
        return (string) (new SelectPrompt(
            label: $label,
            options: $options,
            default: $default ?? array_key_first($options),
            hint: $hint ?? '',
        ))->prompt();
    }

    public function checklist(string $label, array $options, array $defaults = [], ?string $hint = null): array
    {
        return array_map(
            'strval',
            (new MultiSelectPrompt(
                label: $label,
                options: $options,
                default: $defaults,
                required: false,
                hint: $hint ?? '',
            ))->prompt(),
        );
    }

    public function text(string $label, string $default = '', ?\Closure $validate = null, ?string $hint = null): string
    {
        return (new TextPrompt(label: $label, default: $default, validate: $validate, hint: $hint ?? ''))->prompt();
    }

    public function secret(string $label, ?\Closure $validate = null, ?string $hint = null): string
    {
        return (new PasswordPrompt(label: $label, validate: $validate, hint: $hint ?? ''))->prompt();
    }

    public function form(array $fields, string $title = ''): array
    {
        return (new FormPrompt($title !== '' ? $title : ' ', $fields))->prompt();
    }

    public function confirm(string $label, bool $default = true): bool
    {
        return (new ConfirmPrompt(label: $label, default: $default))->prompt();
    }

    public function spin(\Closure $callback, string $message): mixed
    {
        return spin($callback, $message);
    }
}
