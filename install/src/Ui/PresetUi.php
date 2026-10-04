<?php

declare(strict_types=1);

namespace OpenSim\Installer\Ui;

/**
 * Answers the questions of the setup from a table, so the same flows run without anyone typing: the quick
 * setup (a form for what matters, the defaults for the rest) and the setup from a file.
 *
 * A question is answered, in this order, by the table (the key is the beginning of the question, as written
 * in English, or any part of it), else by the interface behind when its label is one of those to ask (the
 * plan to accept, the credentials of the database administrator...), else by its default. An answer the
 * question refuses (its validation) is asked again by the interface behind, with this answer as the default.
 *
 * With $prefill, the table is only what the questions propose: every question is asked by the interface behind,
 * with the answer of the table as its default (the way to go through what a setup chose, to edit it).
 *
 * When the user tries the database again after a failure, its settings are asked.
 */
final class PresetUi implements InstallerUi
{
    use AsksForms {
        form as private askForm;
    }

    /** @var list<array{0:string,1:string}> what was answered, the label and the answer, secrets masked */
    public array $answered = [];

    /**
     * @param array<string,string|bool|list<string>> $answers question => answer
     * @param list<string> $ask the questions the interface behind asks, whatever the table says
     */
    public function __construct(
        private InstallerUi $inner,
        private array $answers,
        private array $ask = [],
        private bool $prefill = false,
    ) {}

    /** Set when something failed (the database): its settings are asked again, not answered from the table */
    private bool $retrying = false;

    /**
     * A form answers field by field, from the table, the defaults or the interface for what is asked: the summary to
     * check is for the one who edits (prefill), not for a setup that runs on its own.
     */
    public function form(array $fields, string $title = ''): array
    {
        if ($this->prefill) {
            return $this->askForm($fields, $title);
        }
        $values = [];
        foreach ($fields as $field) {
            $values[$field['key']] = $this->visible($field, $values)
                ? $this->askField($field, $field['default'] ?? '')
                : '';
        }

        return $values;
    }

    public function intro(string $title): void
    {
        $this->inner->intro($title);
    }

    public function note(string $message): void
    {
        $this->inner->note($message);
    }

    public function entity(string $name): string
    {
        return $this->inner->entity($name);
    }

    public function warn(string $message): void
    {
        $this->inner->warn($message);
    }

    public function error(string $message): void
    {
        $this->retrying = true;
        $this->inner->error($message);
    }

    public function outro(string $message): void
    {
        $this->inner->outro($message);
    }

    public function spin(\Closure $callback, string $message): mixed
    {
        return $this->inner->spin($callback, $message);
    }

    public function choose(string $label, array $options, ?string $default = null, ?string $hint = null): string
    {
        $preset = $this->preset($label);
        // A list is the choices to try in turn (an account that exists, else the one to make)
        foreach (is_array($preset) ? $preset : [$preset] as $candidate) {
            if (is_string($candidate) && isset($options[$candidate])) {
                return $this->prefill
                    ? $this->inner->choose($label, $options, $candidate, $hint)
                    : $this->took($label, $candidate);
            }
        }
        if ($this->asked($label) || $preset !== null || $this->prefill) {
            return $this->inner->choose($label, $options, $default, $hint);
        }

        return $default ?? (string) array_key_first($options);
    }

    public function checklist(string $label, array $options, array $defaults = [], ?string $hint = null): array
    {
        $preset = $this->preset($label);
        if ($preset !== null) {
            $keys = is_array($preset)
                ? array_map('strval', $preset)
                : array_values(array_filter(explode(',', (string) $preset)));
            $keys = array_values(array_filter($keys, static fn(string $k): bool => isset($options[$k])));

            return $this->prefill ? $this->inner->checklist($label, $options, $keys, $hint) : $keys;
        }

        return $this->asked($label) || $this->prefill
            ? $this->inner->checklist($label, $options, $defaults, $hint)
            : $defaults;
    }

    public function text(string $label, string $default = '', ?\Closure $validate = null, ?string $hint = null): string
    {
        if ($this->retrying && str_starts_with($label, 'Database ')) {
            $answer = $this->inner->text($label, $default, $validate, $hint);
            // The last field of the credentials: the settings are the new ones
            $this->retrying = $label !== 'Database password';

            return $answer;
        }
        $preset = $this->preset($label);
        if ($preset !== null && !is_array($preset)) {
            $value = (string) (is_bool($preset) ? ($preset ? 'true' : 'false') : $preset);
            if (!$this->prefill && ($validate === null || $validate($value) === null)) {
                return $this->took($label, $value);
            }

            return $this->inner->text($label, $value, $validate, $hint);
        }
        if ($this->asked($label) || $this->prefill) {
            return $this->inner->text($label, $default, $validate, $hint);
        }
        // The default of a question the user is not asked, which the question would refuse, is asked after all
        if ($validate !== null && $validate($default) !== null) {
            return $this->inner->text($label, $default, $validate, $hint);
        }

        return $default;
    }

    public function secret(string $label, ?\Closure $validate = null, ?string $hint = null): string
    {
        $preset = $this->preset($label);
        if (is_string($preset) && ($validate === null || $validate($preset) === null)) {
            return $this->took($label, $preset, true);
        }

        // A secret has no default: it is asked, unless it can be nothing
        return $validate === null || $validate('') === null ? '' : $this->inner->secret($label, $validate, $hint);
    }

    public function confirm(string $label, bool $default = true): bool
    {
        $preset = $this->preset($label);
        if ($preset !== null) {
            $value = is_bool($preset)
                ? $preset
                : in_array(strtolower((string) $preset), ['1', 'true', 'yes', 'y'], true);
            if ($this->prefill) {
                return $this->inner->confirm($label, $value);
            }
            $this->took($label, $value ? 'yes' : 'no');

            return $value;
        }

        if ($this->asked($label) || $this->prefill) {
            return $this->inner->confirm($label, $default);
        }

        return $default;
    }

    /** @return string|bool|list<string>|null */
    private function preset(string $label): string|bool|array|null
    {
        foreach ($this->answers as $match => $answer) {
            if (self::matches($label, $match)) {
                return $answer;
            }
        }

        return null;
    }

    private function asked(string $label): bool
    {
        if ($this->retrying && str_starts_with($label, 'Database ')) {
            return true;
        }
        foreach ($this->ask as $match) {
            if (self::matches($label, $match)) {
                return true;
            }
        }

        return false;
    }

    /** The question holds the words, as they are written in the source or translated. */
    private static function matches(string $label, string $words): bool
    {
        $words = explode('%', $words)[0];

        return $words !== '' && (str_contains($label, $words) || str_contains($label, _($words)));
    }

    private function took(string $label, string $answer, bool $secret = false): string
    {
        $this->answered[] = [$label, $secret ? '' : $answer];

        return $answer;
    }
}
