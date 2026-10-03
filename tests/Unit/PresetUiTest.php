<?php

declare(strict_types=1);

use OpenSim\Installer\Setup\QuickSetup;
use OpenSim\Installer\Ui\AsksForms;
use OpenSim\Installer\Ui\InstallerUi;
use OpenSim\Installer\Ui\PresetUi;

/** An interface that answers what is asked of it, and keeps the questions. */
final class AskingUi implements InstallerUi
{
    use AsksForms;

    public array $asked = [];

    public function __construct(private array $answers) {}

    public function intro(string $title): void {}
    public function note(string $message): void {}
    public function entity(string $name): string
    {
        return $name;
    }
    public function warn(string $message): void {}
    public function error(string $message): void {}
    public function outro(string $message): void {}
    public function spin(\Closure $callback, string $message): mixed
    {
        return $callback();
    }

    public function choose(string $label, array $options, ?string $default = null, ?string $hint = null): string
    {
        $this->asked[] = [$label, $default, array_keys($options)];

        return (string) array_shift($this->answers);
    }

    public function checklist(string $label, array $options, array $defaults = [], ?string $hint = null): array
    {
        return $defaults;
    }

    public function text(string $label, string $default = '', ?\Closure $validate = null, ?string $hint = null): string
    {
        $this->asked[] = [$label, $default];

        return (string) (array_shift($this->answers) ?? $default);
    }

    public function secret(string $label, ?\Closure $validate = null, ?string $hint = null): string
    {
        return '';
    }

    public function confirm(string $label, bool $default = true): bool
    {
        $this->asked[] = [$label, $default];

        return (bool) array_shift($this->answers);
    }
}

function askingUi(array $answers): AskingUi
{
    return new AskingUi($answers);
}

it('does not ask the plan of a setup from a file', function () {
    $inner = askingUi([]);
    $ui = new PresetUi($inner, ['Apply this configuration' => true]);

    expect($ui->confirm("Apply this configuration to grid 'x'?", false))->toBeTrue()->and($inner->asked)->toBe([]);
});

it('asks every question, with the answers of the table as the ones proposed, to edit a setup', function () {
    $inner = askingUi([null, null]);
    $ui = new PresetUi($inner, ['Grid name' => 'Vonda'], [], true);

    expect($ui->text('Grid name', 'Other'))->toBe('Vonda')
        ->and($inner->asked[0])->toBe(['Grid name', 'Vonda'])
        ->and($ui->text('Region name', 'Welcome'))->toBe('Welcome')
        ->and($inner->asked[1])->toBe(['Region name', 'Welcome']);
});

it('asks the settings of the database when a failure is tried again', function () {
    $inner = askingUi(['other']);
    $ui = new PresetUi($inner, []);

    expect($ui->text('Database user', 'opensim'))->toBe('opensim')->and($inner->asked)->toBe([]);
    $ui->error('Access denied');
    expect($ui->text('Database user', 'opensim'))->toBe('other');
});

it('reads a login URI as host:port, with or without the scheme', function () {
    expect(QuickSetup::login('play.example.org:8002'))->toBe(['play.example.org', 8002])
        ->and(QuickSetup::login(' http://vonda:8012/ '))->toBe(['vonda', 8012])
        ->and(QuickSetup::login('play.example.org'))->toBeNull()
        ->and(QuickSetup::login('host:99999'))->toBeNull();
});

it('answers from the table what the questions to ask would match too', function () {
    // "Password of " asks the password of a database administrator; the password of the new account is not that
    $inner = askingUi([]);
    $ui = new PresetUi($inner, ['Password of the new account' => 'hunter22'], ['Password of ']);

    expect($ui->secret('Password of the new account'))->toBe('hunter22')->and($inner->asked)->toBe([]);
});

it('answers a form field by field, with choices, yes or no and the fields that are there only sometimes', function () {
    $ui = new PresetUi(askingUi([]), ['Console of' => 'screen', 'Start it' => false]);
    $v = $ui->form([
        ['key' => 'mode', 'label' => 'Console of the thing', 'type' => 'choice', 'options' => ['rest' => 'REST', 'screen' => 'Screen'], 'default' => 'rest'],
        ['key' => 'port', 'label' => 'Console port', 'default' => '9004', 'when' => fn(array $v) => $v['mode'] === 'rest'],
        ['key' => 'start', 'label' => 'Start it now', 'type' => 'confirm', 'default' => 'yes'],
        ['key' => 'roles', 'label' => 'Roles', 'type' => 'checklist', 'options' => ['a' => 'A', 'b' => 'B'], 'default' => 'a,b'],
    ]);

    expect($v)->toBe(['mode' => 'screen', 'port' => '', 'start' => 'no', 'roles' => 'a,b']);
});
