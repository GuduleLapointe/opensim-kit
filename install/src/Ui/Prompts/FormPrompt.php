<?php

declare(strict_types=1);

namespace OpenSim\Installer\Ui\Prompts;

use Closure;
use Laravel\Prompts\Concerns\TypedValue;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

/**
 * Several fields on one screen, of these types: text (default), secret, choice (options: key => label, Left, Right or
 * Space change it), confirm (yes or no, as a choice), checklist (options, a list of keys joined by commas: Left and
 * Right move, Space checks). A field with a `when` closure, given the values so far, is there only when it says so.
 *
 * Several fields on one screen: Tab, Enter or Down go to the next field, Shift-Tab or Up to the one before,
 * Enter in the last one sends the form. The fields are edited with the keys of Shortcuts (readline).
 * Whatever is wrong is shown under the form, on the field to correct.
 */
final class FormPrompt extends Prompt
{
    use Shortcuts;
    use TypedValue;

    /** The field being edited */
    public int $focus = 0;

    /** @var array<string,string> the value of each field, by key */
    public array $values = [];

    /** @var array<string,int> the option the cursor is on, in a checklist, by key */
    public array $cursors = [];

    public bool|string $required = false;

    public mixed $validate = null;

    public ?Closure $transform = null;

    /**
     * @param list<array{key:string,label:string,type?:string,default?:string,required?:bool,validate?:?Closure,hint?:string}> $fields
     */
    public function __construct(public string $title, public array $fields)
    {
        foreach ($fields as $field) {
            $this->values[$field['key']] = self::initial($field);
        }
        $this->focus = $this->nextVisible(-1) ?? 0;
        $this->trackTypedValue($this->values[$fields[$this->focus]['key']], submit: false);
        $this->validate = fn(array $values): ?string => $this->firstProblem();
        $this->on('key', fn(string $key) => $this->navigate($key));
    }

    /** @return array<string,string> */
    public function value(): array
    {
        // What is not there has no value
        $values = [];
        foreach ($this->fields as $i => $field) {
            $values[$field['key']] = $this->visible($i) ? $this->values[$field['key']] : '';
        }

        return $values;
    }

    /** What the field being edited says of its hint, to show under the form */
    public function hint(): string
    {
        return (string) ($this->fields[$this->focus]['hint'] ?? '');
    }

    /** The type of a field */
    public static function typeOf(array $field): string
    {
        return $field['type'] ?? 'text';
    }

    /** The options of a choice or a checklist: key => label (yes or no for a confirm) */
    public static function optionsOf(array $field): array
    {
        return self::typeOf($field) === 'confirm' ? ['yes' => _('Yes'), 'no' => _('No')] : $field['options'] ?? [];
    }

    /** The value a field starts with: the default, or the first option of a choice */
    private static function initial(array $field): string
    {
        $type = self::typeOf($field);
        $default = (string) ($field['default'] ?? '');
        if ($type === 'confirm') {
            return in_array($default, ['yes', '1', 'true'], true) ? 'yes' : 'no';
        }
        if ($type === 'choice') {
            $options = self::optionsOf($field);

            return isset($options[$default]) ? $default : (string) array_key_first($options);
        }

        return $default;
    }

    /** Whether a field is there, given the values so far */
    public function visible(int $i): bool
    {
        $when = $this->fields[$i]['when'] ?? null;

        return $when === null || (bool) $when($this->values);
    }

    private function nextVisible(int $from): ?int
    {
        for ($i = $from + 1; $i < count($this->fields); $i++) {
            if ($this->visible($i)) {
                return $i;
            }
        }

        return null;
    }

    private function previousVisible(int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if ($this->visible($i)) {
                return $i;
            }
        }

        return null;
    }

    /** The value of a field to show: a secret is masked, the field being edited has the cursor */
    public function shown(int $i, int $maxWidth): string
    {
        $field = $this->fields[$i];
        $type = self::typeOf($field);
        $focused = $i === $this->focus && $this->state !== 'submit';
        if ($type === 'choice' || $type === 'confirm') {
            $label = self::optionsOf($field)[$this->values[$field['key']]] ?? '';

            return $focused ? '‹ ' . $label . ' ›' : $label;
        }
        if ($type === 'checklist') {
            $checked = array_filter(explode(',', $this->values[$field['key']]));
            $parts = [];
            foreach (array_keys(self::optionsOf($field)) as $n => $key) {
                $part = (in_array($key, $checked, true) ? '[x] ' : '[ ] ') . self::optionsOf($field)[$key];
                $parts[] =
                    $focused && ($this->cursors[$field['key']] ?? 0) === $n ? '‹' . $part . '›' : ' ' . $part . ' ';
            }

            return trim(implode(' ', $parts));
        }
        $secret = $type === 'secret';
        $value = $i === $this->focus ? $this->typedValue : $this->values[$field['key']];
        $text = $secret ? str_repeat('•', mb_strlen($value)) : $value;

        return $focused ? $this->addCursor($text, $this->cursorPosition, $maxWidth) : $text;
    }

    /** Left, Right or Space on a choice or a checklist; whether the key was for it */
    private function pick(string $key): bool
    {
        $field = $this->fields[$this->focus];
        $type = self::typeOf($field);
        $options = array_keys(self::optionsOf($field));
        if ($options === []) {
            return false;
        }
        $name = $field['key'];
        $step = in_array($key, [Key::RIGHT, Key::RIGHT_ARROW], true)
            ? 1
            : (in_array($key, [Key::LEFT, Key::LEFT_ARROW], true)
                ? -1
                : 0);
        if ($type === 'choice' || $type === 'confirm') {
            if ($step === 0 && $key !== Key::SPACE) {
                return false;
            }
            $at = (int) array_search($this->values[$name], $options, true);
            $this->values[$name] = (string) $options[($at + ($step ?: 1) + count($options)) % count($options)];

            return true;
        }
        if ($type === 'checklist') {
            $cursor = $this->cursors[$name] ?? 0;
            if ($step !== 0) {
                $this->cursors[$name] = ($cursor + $step + count($options)) % count($options);

                return true;
            }
            if ($key === Key::SPACE) {
                $checked = array_filter(explode(',', $this->values[$name]));
                $option = (string) $options[$cursor];
                $checked = in_array($option, $checked, true) ? array_diff($checked, [$option]) : [...$checked, $option];
                // In the order of the options
                $this->values[$name] = implode(
                    ',',
                    array_values(array_filter($options, static fn($o): bool => in_array((string) $o, $checked, true))),
                );

                return true;
            }
        }

        return false;
    }

    private function navigate(string $key): void
    {
        $field = $this->fields[$this->focus];
        if (in_array(self::typeOf($field), ['choice', 'confirm', 'checklist'], true)) {
            // What was typed is of no use there: Left, Right and Space choose
            $picked = $this->pick($key);
            $this->typedValue = $this->values[$field['key']];
            if ($picked) {
                return;
            }
        } else {
            $this->values[$field['key']] = $this->typedValue;
        }

        $next = $this->nextVisible($this->focus);
        if ($key === Key::ENTER && $next === null) {
            $this->submit();

            return;
        }
        if (in_array($key, [Key::ENTER, Key::TAB, Key::DOWN, Key::DOWN_ARROW], true)) {
            $this->focusOn($next ?? $this->focus);
        } elseif (in_array($key, [Key::SHIFT_TAB, Key::UP, Key::UP_ARROW], true)) {
            $this->focusOn($this->previousVisible($this->focus) ?? $this->focus);
        }
    }

    private function focusOn(int $i): void
    {
        $this->focus = $i;
        $this->typedValue = $this->values[$this->fields[$i]['key']];
        $this->cursorPosition = mb_strlen($this->typedValue);
    }

    /** The first field that is not right: it takes the focus, and its problem is the error shown. */
    private function firstProblem(): ?string
    {
        foreach ($this->fields as $i => $field) {
            if (!$this->visible($i) || !in_array(self::typeOf($field), ['text', 'secret'], true)) {
                continue;
            }
            $value = trim($this->values[$field['key']]);
            $problem = null;
            if (($field['required'] ?? true) && $value === '') {
                $problem = sprintf(_('%s is required.'), $field['label']);
            } elseif ($value !== '' && ($check = $field['validate'] ?? null) !== null) {
                $problem = $check($this->values[$field['key']]);
            }
            if ($problem !== null) {
                $this->focusOn($i);

                return $problem;
            }
        }

        return null;
    }

    protected function getRenderer(): callable
    {
        return new FormPromptRenderer($this);
    }
}
