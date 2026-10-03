<?php

declare(strict_types=1);

namespace OpenSim\Installer\Ui;

/**
 * Presentation abstraction for the installer.
 *
 * The installer flow depends only on this interface, so the same flow can be
 * driven by a text frontend (PromptsUi, using Laravel Prompts) today and a web
 * frontend tomorrow.
 */
interface InstallerUi
{
    public function intro(string $title): void;

    public function note(string $message): void;

    /** A name (of a grid, a simulator, a region) as it shows among the words of a menu, to tell it from them. */
    public function entity(string $name): string;

    public function warn(string $message): void;

    public function error(string $message): void;

    public function outro(string $message): void;

    /**
     * Single choice among options.
     *
     * @param array<string,string> $options  key => label shown to the user
     * @return string                         the chosen key
     */
    public function choose(string $label, array $options, ?string $default = null, ?string $hint = null): string;

    /**
     * Several choices among options, any of them or none.
     *
     * @param array<string,string> $options   key => label shown to the user
     * @param list<string>         $defaults  the keys checked at first
     * @return list<string>                   the keys checked
     */
    public function checklist(string $label, array $options, array $defaults = [], ?string $hint = null): array;

    /**
     * Free text input.
     *
     * @param ?\Closure(string):?string $validate  returns an error message, or null if valid
     */
    public function text(string $label, string $default = '', ?\Closure $validate = null, ?string $hint = null): string;

    /**
     * Secret input (a password): not shown while typed, never proposed.
     *
     * @param ?\Closure(string):?string $validate  returns an error message, or null if valid
     */
    public function secret(string $label, ?\Closure $validate = null, ?string $hint = null): string;

    /**
     * Several fields on one screen, to go through with Tab or Enter.
     *
     * @param list<array{key:string,label:string,type?:string,options?:array<string,string>,default?:string,required?:bool,validate?:?\Closure,hint?:string,when?:?\Closure}> $fields
     *        type: text (default), secret, choice (options: key => label), confirm (yes or no) or checklist (options; the
     *        keys checked, joined by commas); required: true unless said otherwise; when: given the values so far, tells
     *        whether the field is there
     * @return array<string,string>  the value of each field, by key
     */
    public function form(array $fields, string $title = ''): array;

    public function confirm(string $label, bool $default = true): bool;

    /**
     * Run $callback while showing a progress indicator; return its result.
     *
     * @template T
     * @param \Closure():T $callback
     * @return T
     */
    public function spin(\Closure $callback, string $message): mixed;
}
