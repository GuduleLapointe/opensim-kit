<?php

declare(strict_types=1);

namespace OpenSim\Installer\Ui\Prompts;

use Laravel\Prompts\Key;
use OpenSim\Installer\Ui\Back;
use OpenSim\Installer\Ui\Quit;

/**
 * The keys of a terminal that Laravel Prompts does not know, for every prompt of the setup:
 * Escape gives up the screen (Back), Ctrl-Q leaves the setup (Quit); in a text, Ctrl-U clears the
 * line and Ctrl-W the word before the cursor.
 *
 * The keys are caught where the prompts read them (runLoop), before Laravel handles them: it takes
 * Ctrl-U for "revert the form", which has no meaning in a prompt of its own.
 */
trait Shortcuts
{
    private const CTRL_Q = "\x11";
    private const CTRL_W = "\x17";
    private const CTRL_K = "\x0b";
    private const CTRL_D = "\x04";
    private const CTRL_Y = "\x19";
    private const CTRL_T = "\x14";

    /** What was cut by the last Ctrl-U, Ctrl-K, Ctrl-W or Alt-D, for Ctrl-Y (readline's kill ring, of one) */
    private static string $killed = '';

    public function runLoop(callable $callable): mixed
    {
        // The terminal keeps Ctrl-Q for itself (flow control) unless told not to
        static::terminal()->setTty('-ixon');

        return parent::runLoop(function (string $key) use ($callable) {
            if ($key === Key::ESCAPE) {
                $this->leave();

                throw new Back();
            }
            if ($key === self::CTRL_Q) {
                $this->leave();

                throw new Quit();
            }
            if (property_exists($this, 'typedValue') && $this->edit($key)) {
                $this->render();

                return null;
            }

            return $callable($key);
        });
    }

    /**
     * The editing keys of readline (emacs mode) that Laravel Prompts does not have: kill to the start
     * (Ctrl-U), to the end (Ctrl-K), the word before (Ctrl-W) or after (Alt-D) the cursor, yank (Ctrl-Y),
     * delete the character under the cursor (Ctrl-D), transpose (Ctrl-T), move by word (Alt-B, Alt-F,
     * Ctrl-Left, Ctrl-Right). The other keys (arrows, Home, End, Ctrl-A, Ctrl-B, Ctrl-E, Ctrl-F, Delete,
     * Backspace, Alt-Backspace) are Laravel's.
     *
     * @return bool whether the key was one of them
     */
    private function edit(string $key): bool
    {
        $value = $this->typedValue;
        $at = $this->cursorPosition;
        $before = mb_substr($value, 0, $at);
        $after = mb_substr($value, $at);

        switch ($key) {
            case Key::CTRL_U:
                self::$killed = $before;
                $this->typedValue = $after;
                $this->cursorPosition = 0;
                break;
            case self::CTRL_K:
                self::$killed = $after;
                $this->typedValue = $before;
                break;
            case self::CTRL_W:
                $kept = (string) preg_replace('/\S+\s*$/u', '', $before);
                self::$killed = mb_substr($before, mb_strlen($kept));
                $this->typedValue = $kept . $after;
                $this->cursorPosition = mb_strlen($kept);
                break;
            case "\ed": // Alt-D
                $rest = (string) preg_replace('/^\s*\S+/u', '', $after);
                self::$killed = mb_substr($after, 0, mb_strlen($after) - mb_strlen($rest));
                $this->typedValue = $before . $rest;
                break;
            case self::CTRL_Y:
                $this->typedValue = $before . self::$killed . $after;
                $this->cursorPosition = $at + mb_strlen(self::$killed);
                break;
            case self::CTRL_D:
                if ($after !== '') {
                    $this->typedValue = $before . mb_substr($after, 1);
                }
                break;
            case self::CTRL_T:
                if ($at > 0 && mb_strlen($value) > 1) {
                    $at = min($at, mb_strlen($value) - 1);
                    $this->typedValue =
                        mb_substr($value, 0, $at - 1) .
                        mb_substr($value, $at, 1) .
                        mb_substr($value, $at - 1, 1) .
                        mb_substr($value, $at + 1);
                    $this->cursorPosition = $at + 1;
                }
                break;
            case "\eb": // Alt-B
            case "\e[1;5D": // Ctrl-Left
            case "\e[1;3D": // Alt-Left
                $this->cursorPosition = mb_strlen((string) preg_replace('/\w+\W*$/u', '', $before));
                break;
            case "\ef": // Alt-F
            case "\e[1;5C": // Ctrl-Right
            case "\e[1;3C": // Alt-Right
                $moved = preg_match('/^\W*\w*/u', $after, $m) === 1 ? mb_strlen($m[0]) : 0;
                $this->cursorPosition = $at + $moved;
                break;
            default:
                return false;
        }

        return true;
    }

    /** The themes know the prompts of Laravel by their class: this one is drawn as the one it extends. */
    protected function getRenderer(): callable
    {
        $class = (string) get_parent_class($this);

        $renderer = static::$themes[static::$theme][$class] ?? static::$themes['default'][$class];

        return new $renderer($this);
    }

    /** Leave the terminal as it was found: the exception goes up through the prompt. */
    private function leave(): void
    {
        $this->showCursor();
        static::terminal()->restoreTty();
        // The line the prompt was drawn on is left as it is, the next screen starts under it
        static::output()->writeln('');
    }
}
