<?php

declare(strict_types=1);

use OpenSim\Installer\Grid\AccountList;
use OpenSim\Installer\Grid\GridPlan;
use OpenSim\Installer\Grid\SimPlan;
use OpenSim\Installer\Setup\SetupFile;
use OpenSim\Installer\Ui\PresetUi;
use OpenSim\Installer\Ui\QuietUi;

const SETUP_YAML = <<<'YAML'
grid:
  name: Test Grid
  hostname: play.example.org
  database: { user: opensim, password: secret1 }
owner: { name: Jane Doe, password: hunter22 }
simulators:
  - name: Welcome
    regions:
      - { name: Welcome, location: "1000,1000", roles: [default, default_hg] }
      - { name: Second Region, port: 9003 }
users:
  - { first: Bob, last: Roe, password: pw123456 }
  - { first: Cy, last: Doe, email: cy@example.org }
YAML;

it('reads a setup from YAML or from JSON', function () {
    $yaml = SetupFile::parse(SETUP_YAML);
    $json = SetupFile::parse(json_encode($yaml));

    expect($yaml['grid']['name'])->toBe('Test Grid')
        ->and($json)->toBe($yaml)
        ->and($yaml['simulators'][0]['regions'][1]['port'])->toBe(9003);
});

it('says what is missing', function () {
    expect(fn() => SetupFile::parse("grid:\n  name: X\n"))
        ->toThrow(InvalidArgumentException::class, 'grid.database.user is required');
    expect(fn() => SetupFile::parse("grid: { name: X, database: { user: a, password: b } }\nowner: { name: Jane, password: abc }\n"))
        ->toThrow(InvalidArgumentException::class, 'owner.name');
    expect(fn() => SetupFile::parse("- just\n- a list\n"))->toThrow(InvalidArgumentException::class);
});

it('requires no email', function () {
    $data = SetupFile::parse(SETUP_YAML);

    expect(SetupFile::problems($data))->toBe([])
        ->and(isset($data['owner']['email']))->toBeFalse();
});

it('gives the answers of the grid', function () {
    $answers = SetupFile::gridAnswers(SetupFile::parse(SETUP_YAML));

    expect($answers['Grid name'])->toBe('Test Grid')
        ->and($answers['Base hostname'])->toBe('play.example.org')
        ->and($answers['Database user'])->toBe('opensim')
        ->and($answers['Database password'])->toBe('secret1')
        ->and($answers['Apply this configuration'])->toBeTrue()
        ->and(isset($answers['Web URL']))->toBeFalse();
});

it('gives the answers of a simulator', function () {
    $data = SetupFile::parse(SETUP_YAML);
    $answers = SetupFile::simAnswers($data, 0);

    expect($answers['Simulator name'])->toBe('Welcome')
        ->and($answers['Region name'])->toBe('Welcome')
        ->and($answers['Role of this region'])->toBe(['DefaultRegion', 'DefaultHGRegion'])
        ->and($answers['Estate owner'])->toBe(['Jane Doe', '+'])
        ->and($answers['Password of the new account'])->toBe('hunter22')
        // the database of the simulator is the one of the grid
        ->and($answers['Database user'])->toBe('opensim')
        ->and(SetupFile::regionAnswers($data, 0, 1)['Region name'])->toBe('Second Region');
});

it('answers the questions of the setup from it', function () {
    $ui = new PresetUi(new QuietUi(), SetupFile::simAnswers(SetupFile::parse(SETUP_YAML), 0));

    expect($ui->text('Region name', 'Other'))->toBe('Welcome')
        ->and($ui->secret('Password of the new account'))->toBe('hunter22')
        ->and($ui->choose('Estate owner', ['Bob Roe' => 'Bob Roe', '+' => 'new']))->toBe('+')
        ->and($ui->choose('Estate owner', ['Jane Doe' => 'Jane Doe', '+' => 'new']))->toBe('Jane Doe')
        ->and($ui->confirm("Account Jane Doe does not exist yet, create it in the grid 'x'?", false))->toBeTrue()
        // what the file does not say is the default
        ->and($ui->text('Estate name', 'Test Estate'))->toBe('Test Estate');
});

it('keeps what the setup did, to make it again', function () {
    $grid = new GridPlan();
    $grid->gridName = 'Test Grid';
    $grid->gridNick = 'test_grid';
    $grid->dbName = 'test_grid_robust';
    $grid->dbUser = 'opensim';
    $grid->dbPass = 'secret1';
    $data = SetupFile::withGrid([], $grid);

    $sim = new SimPlan();
    $sim->simName = 'Welcome';
    $sim->regionName = 'Welcome';
    $sim->estateOwner = 'Jane Doe';
    $sim->dbName = 'welcome_db';
    $sim->estateName = 'Test Estate';
    $sim->createOwner = true;
    $sim->ownerPassword = 'hunter22';
    $data = SetupFile::withSim($data, $sim);
    // one more region, later
    $more = new SimPlan();
    $more->simName = 'Welcome';
    $more->regionName = 'Second';
    $more->dbUser = '';
    $data = SetupFile::withSim($data, $more);

    expect($data['grid']['database']['name'])->toBe('test_grid_robust')
        ->and($data['owner']['name'])->toBe('Jane Doe')
        ->and($data['owner'])->not->toHaveKey('password')
        ->and($data['owner']['password_hash'])->toBe(OpenSim\Installer\Grid\AccountWriter::passwordHash('hunter22', $data['owner']['password_salt']))
        ->and(array_column($data['simulators'][0]['regions'], 'name'))->toBe(['Welcome', 'Second'])
        ->and(count($data['simulators']))->toBe(1)
        // a region added later, which knows nothing of the database, does not erase it
        ->and($data['simulators'][0]['database']['name'])->toBe('welcome_db')
        ->and($data['simulators'][0]['estate'])->toBe(['name' => 'Test Estate', 'owner' => 'Jane Doe']);
    // and what it kept is a setup that reads
    expect(SetupFile::problems($data))->toBe([]);
});

it('writes the setup file of a grid for its owner only', function () {
    $dir = sys_get_temp_dir() . '/setup-' . uniqid();
    mkdir($dir);
    SetupFile::record($dir, static fn(array $d): array => $d + ['version' => 1]);

    expect(fileperms("$dir/setup.json") & 0o777)->toBe(0o600)
        ->and(json_decode(file_get_contents("$dir/setup.json"), true))->toBe(['version' => 1]);
});

it('gives the users of a setup file to the bulk import', function () {
    $list = AccountList::parse(SETUP_YAML, 'yaml');

    expect(array_column($list['accounts'], 'first'))->toBe(['Bob', 'Cy'])
        ->and($list['errors'])->toBe([])
        ->and(AccountList::parse((string) json_encode(SetupFile::parse(SETUP_YAML)), 'json')['accounts'])->toHaveCount(2);
});
