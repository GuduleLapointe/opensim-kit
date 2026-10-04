<?php

declare(strict_types=1);

namespace OpenSim\Installer\Ui;

/**
 * A form made of the questions of the interface: each field is asked in turn, then the answers are
 * shown to be accepted or corrected (a field at a time, the others are kept), so a typo is not a
 * reason to start again. The form of InstallerUi for every frontend that asks one question at a time.
 */
trait AsksForms
{
    /**
     * @param list<array{key:string,label:string,type?:string,options?:array<string,string>,default?:string,required?:bool,validate?:?\Closure,hint?:string,when?:?\Closure}> $fields
     *        type: text (default), secret, choice (options), confirm (yes or no), checklist (options, keys joined by commas);
     *        when: given the values so far, tells whether the field is there
     * @return array<string,string>
     */
    public function form(array $fields, string $title = ''): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field['key']] = $this->visible($field, $values)
                ? $this->askField($field, $field['default'] ?? '')
                : '';
        }

        while (true) {
            $lines = [];
            foreach ($fields as $field) {
                if (!$this->visible($field, $values)) {
                    continue;
                }
                $value = $values[$field['key']];
                $lines[] = $field['label'] . ': ' . $this->shownValue($field, $value);
            }
            $this->note(implode("\n", $lines));
            if (
                $this->choose(_('Is this right?'), ['ok' => _('Continue'), 'edit' => _('Change something')], 'ok') ===
                'ok'
            ) {
                return $values;
            }

            $options = [];
            foreach ($fields as $field) {
                if ($this->visible($field, $values)) {
                    $options[$field['key']] = $field['label'];
                }
            }
            $key = $this->choose(_('Which one?'), $options, array_key_first($options));
            foreach ($fields as $field) {
                if ($field['key'] === $key) {
                    $values[$key] = $this->askField($field, $values[$key], true);
                }
            }
            // A field that was not there may be now: asked, with its default
            foreach ($fields as $field) {
                if ($values[$field['key']] === '' && $this->visible($field, $values) && ($field['required'] ?? true)) {
                    $values[$field['key']] = $this->askField($field, $field['default'] ?? '');
                }
            }
        }
    }

    /** @param array<string,mixed> $field @param array<string,string> $values */
    private function visible(array $field, array $values): bool
    {
        return !isset($field['when']) || (bool) $field['when']($values);
    }

    /** @param array<string,mixed> $field */
    private function shownValue(array $field, string $value): string
    {
        $type = $field['type'] ?? 'text';
        if ($type === 'secret') {
            return $value !== '' ? '••••••' : '';
        }
        if ($type === 'confirm') {
            return $value === 'yes' ? _('Yes') : _('No');
        }
        if ($type === 'choice') {
            return $field['options'][$value] ?? $value;
        }
        if ($type === 'checklist') {
            return implode(
                ', ',
                array_map(
                    static fn(string $k): string => $field['options'][$k] ?? $k,
                    array_filter(explode(',', $value)),
                ),
            );
        }

        return $value;
    }

    /** @param array{key:string,label:string,type?:string,options?:array<string,string>,default?:string,required?:bool,validate?:?\Closure,hint?:string} $field */
    private function askField(array $field, string $current, bool $keep = false): string
    {
        $type = $field['type'] ?? 'text';
        if ($type === 'confirm') {
            return $this->confirm($field['label'], in_array($current, ['yes', '1', 'true'], true)) ? 'yes' : 'no';
        }
        if ($type === 'choice') {
            return $this->choose(
                $field['label'],
                $field['options'],
                $current !== '' ? $current : null,
                $field['hint'] ?? null,
            );
        }
        if ($type === 'checklist') {
            return implode(
                ',',
                $this->checklist(
                    $field['label'],
                    $field['options'],
                    array_values(array_filter(explode(',', $current))),
                    $field['hint'] ?? null,
                ),
            );
        }
        $required = $field['required'] ?? true;
        $custom = $field['validate'] ?? null;
        $validate = static fn(string $v): ?string => $required && trim($v) === ''
            ? _('This field is required.')
            : ($custom !== null && trim($v) !== ''
                ? $custom($v)
                : null);

        if (($field['type'] ?? 'text') === 'secret') {
            // A secret is never shown: nothing typed keeps the one given before
            $value = $this->secret(
                $field['label'],
                $keep && $current !== ''
                    ? static fn(string $v): ?string => $v === '' ? null : ($custom !== null ? $custom($v) : null)
                    : $validate,
                $field['hint'] ?? null,
            );

            return $value === '' && $keep ? $current : $value;
        }

        return trim($this->text($field['label'], $current, $validate, $field['hint'] ?? null));
    }
}
