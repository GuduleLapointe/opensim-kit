<?php

declare(strict_types=1);

namespace OpenSim\Installer\Ui\Prompts;

use Laravel\Prompts\Themes\Default\Concerns\DrawsBoxes;
use Laravel\Prompts\Themes\Default\Renderer;

/** The form of FormPrompt, in the box the other prompts of the setup have. */
final class FormPromptRenderer extends Renderer
{
    use DrawsBoxes;

    public function __invoke(FormPrompt $prompt): string
    {
        $cols = $prompt->terminal()->cols();
        $labels = array_map(static fn(array $f): string => $f['label'], $prompt->fields);
        $width = max(array_map('mb_strlen', $labels));
        $width = min($width, max(12, intdiv($cols, 2) - 6));
        $valueWidth = max(10, $cols - $width - 14);

        $lines = [];
        foreach ($prompt->fields as $i => $field) {
            if (!$prompt->visible($i)) {
                continue;
            }
            $label = str_pad($this->truncate($field['label'], $width), $width);
            $value = $prompt->shown($i, $valueWidth);
            $lines[] = $prompt->state === 'submit'
                ? $this->dim($label) . '  ' . $this->truncate($value, $valueWidth)
                : ($i === $prompt->focus ? $this->cyan('›') . ' ' . $this->cyan($label) : '  ' . $label) . '  ' . $this->truncate($value, $valueWidth);
        }
        $body = implode(PHP_EOL, $lines);
        $title = $prompt->state === 'submit' ? $this->dim($prompt->title) : $this->cyan($prompt->title);

        return (string) match ($prompt->state) {
            'submit' => $this->box($title, $body),
            'error' => $this->box($title, $body, color: 'yellow')->warning($this->truncate($prompt->error, $cols - 5)),
            default => $this
                ->box($title, $body)
                ->hint($prompt->hint() !== '' ? $prompt->hint() : _('Tab or Enter: next field, Shift-Tab: back, Left, Right, Space: choose, Enter on the last one: continue')),
        };
    }
}
