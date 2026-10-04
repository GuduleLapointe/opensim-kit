<?php
/**
 * The accounts made from a list, directly in the database of a grid.
 */

use OpenSim\Installer\Grid\AccountImporter;
use OpenSim\Installer\Grid\AccountList;
use OpenSim\Installer\Grid\AccountWriter;
use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Grid\GridPlan;

/** A writer with ids, a time and a salt that follow one another, so the statements can be compared. */
function accounts_writer(): AccountWriter
{
    $n = 0;

    return new AccountWriter(
        function () use (&$n): string {
            $n++;

            return sprintf('00000000-0000-4000-8000-%012d', $n);
        },
        static fn(): int => 1700000000,
        static fn(): string => str_repeat('ab', 16),
    );
}

describe('A list of accounts', function () {
    test('reads a CSV, with or without header', function () {
        $with = AccountList::parse("First Name;Last Name;E-mail;Password\nJane;Doe;jane@example.org;pw1\nJohn;Roe;;\n");
        $without = AccountList::parse("Jane,Doe,jane@example.org,pw1\n\"John\",Roe\n");

        expect($with['errors'])->toBe([]);
        expect($with['accounts'][0])->toBe([
            'first' => 'Jane',
            'last' => 'Doe',
            'email' => 'jane@example.org',
            'password' => 'pw1',
            'password_hash' => '',
            'password_salt' => '',
            'line' => 2,
        ]);
        expect($with['accounts'][1]['email'])->toBe('');
        expect(array_column($without['accounts'], 'last'))->toBe(['Doe', 'Roe']);
    });

    test('reads JSON, a list or an object', function () {
        $list = AccountList::parse('[{"firstname":"Jane","lastname":"Doe","mail":"jane@example.org"}]');
        $object = AccountList::parse('{"accounts":[{"first":"John","last":"Roe","password":"x"}]}');

        expect($list['accounts'][0]['first'])->toBe('Jane');
        expect($list['accounts'][0]['email'])->toBe('jane@example.org');
        expect($object['accounts'][0]['password'])->toBe('x');
    });

    test('tells each line that cannot be used, and keeps the others', function () {
        $list = AccountList::parse(
            "Jane,Doe\nBad\"Name,Doe\nJohn,Roe,not an email\nJANE,doe\nOk,Fine,ok@example.org\n",
        );

        expect(array_column($list['accounts'], 'first'))->toBe(['Jane', 'Ok']);
        expect($list['errors'])->toHaveCount(3);
        expect($list['errors'][0])->toContain('line 2');
        expect($list['errors'][2])->toContain('twice');
    });

    test('ignores the BOM of a spreadsheet', function () {
        $list = AccountList::parse("\xEF\xBB\xBFfirst,last\nJane,Doe\n");

        expect($list['accounts'][0]['first'])->toBe('Jane');
    });
});

describe('The statements of an account', function () {
    test('write it with the tables Robust has, in one transaction', function () {
        $made = accounts_writer()->statements(
            'Jane',
            'Doe',
            'jane@example.org',
            'secret',
            '607c44e9-3d01-45eb-a07e-937dc72dbadb',
        );

        expect($made['id'])->toBe('00000000-0000-4000-8000-000000000001');
        expect($made['sql'])->toStartWith('START TRANSACTION;');
        expect($made['sql'])->toEndWith('COMMIT;');
        foreach (['UserAccounts', 'auth', 'GridUser', 'inventoryfolders', 'inventoryitems'] as $table) {
            expect($made['sql'])->toContain("INSERT INTO $table ");
        }
        expect($made['sql'])->toContain("'Jane', 'Doe', 'jane@example.org'");
        expect($made['sql'])->toContain("'607c44e9-3d01-45eb-a07e-937dc72dbadb', '<128,128,0>'");
    });

    test('keeps the password as Robust does', function () {
        $salt = str_repeat('ab', 16);
        $made = accounts_writer()->statements('Jane', 'Doe', '', 'secret');

        expect(AccountWriter::passwordHash('secret', $salt))->toBe(md5(md5('secret') . ':' . $salt));
        expect($made['sql'])->toContain("'" . md5(md5('secret') . ':' . $salt) . "', '$salt'");
        expect($made['sql'])->not->toContain("'secret'");
    });

    test('makes the inventory', function () {
        $made = accounts_writer()->statements('Jane', 'Doe', '', 'secret');

        expect(substr_count($made['sql'], 'INSERT INTO inventoryfolders'))->toBe(1 + 18 + 2);
        expect($made['sql'])->toContain("'My Inventory', 8");
        expect($made['sql'])->toContain("'Current Outfit', 46");
        expect(substr_count($made['sql'], 'INSERT INTO inventoryitems'))->toBe(12);
        expect($made['sql'])->toContain("'Default Skin'");
        // The links point to the items, with the type of a link
        expect($made['sql'])->toMatch('/VALUES \(\'00000000-0000-4000-8000-0000000000(\d+)\', 24, \'Default Eyes\'/');
    });

    test('have no home when the grid has no default region yet', function () {
        expect(accounts_writer()->statements('Jane', 'Doe', '', 'x')['sql'])->not->toContain('GridUser');
    });

    test('escape what comes from the list', function () {
        expect(AccountWriter::quote("O'Hara\\"))->toBe("'O\\'Hara\\\\'");
        expect(AccountWriter::quote("a\0b"))->toBe("'ab'");
    });
});

describe('The import', function () {
    /**
     * An importer over a database that is a list of statements, and a grid.
     *
     * @param list<string> $names The accounts that exist.
     * @param bool $fail Whether the database refuses the writing.
     * @param list<string> $log Gets the statements that were run.
     */
    function accounts_importer(array $names, bool $fail, array &$log): AccountImporter
    {
        return new AccountImporter(function (GridPlan $plan, string $sql) use ($names, $fail, &$log): ?array {
            if (str_starts_with($sql, 'SELECT CONCAT')) {
                return $names;
            }
            if (str_starts_with($sql, 'SELECT uuid')) {
                return ['607c44e9-3d01-45eb-a07e-937dc72dbadb'];
            }
            $log[] = $sql;

            return $fail ? null : [];
        }, accounts_writer());
    }

    function accounts_to_make(): array
    {
        return AccountList::parse("Jane,Doe,,pw1\nJohn,Roe\nAnn,Poe,,\n")['accounts'];
    }

    test('writes nothing without --apply', function () {
        $log = [];
        $grid = new GridInfo();

        $done = accounts_importer(['jane doe'], false, $log)->run($grid, accounts_to_make(), false);

        expect($log)->toBe([]);
        expect(array_column($done['results'], 'status'))->toBe(['exists', 'would create', 'would create']);
    });

    test('writes each account, makes missing passwords', function () {
        $log = [];

        $done = accounts_importer([], false, $log)->run(new GridInfo(), accounts_to_make(), true);

        expect($log)->toHaveCount(3);
        expect(array_column($done['results'], 'status'))->toBe(['created', 'created', 'created']);
        expect($done['results'][0]['generated'])->toBeFalse();
        expect($done['results'][1]['generated'])->toBeTrue();
        expect(strlen($done['results'][1]['password']))->toBe(12);
        expect($log[1])->toContain(md5(md5($done['results'][1]['password']) . ':' . str_repeat('ab', 16)));
    });

    test('stops at the first failure', function () {
        $log = [];
        $stopped = accounts_importer([], true, $log)->run(new GridInfo(), accounts_to_make(), true);
        $log = [];
        $going = accounts_importer([], true, $log)->run(new GridInfo(), accounts_to_make(), true, true);

        expect(array_column($stopped['results'], 'status'))->toBe(['failed', 'not tried', 'not tried']);
        expect(array_column($going['results'], 'status'))->toBe(['failed', 'failed', 'failed']);
    });

    test('gives only the passwords it made', function () {
        $log = [];
        $done = accounts_importer([], false, $log)->run(new GridInfo(), accounts_to_make(), true);

        $csv = AccountImporter::resultCsv($done['results']);

        expect($csv)->toContain('first,last,email,status,password,detail');
        expect($csv)->not->toContain('pw1');
        expect($csv)->toContain($done['results'][1]['password']);
    });

    test('stops when the database of the grid cannot be read', function () {
        $importer = new AccountImporter(static fn(GridPlan $plan, string $sql): ?array => null, accounts_writer());

        $done = $importer->run(new GridInfo(), accounts_to_make(), true);

        expect($done['error'])->not->toBeNull();
        expect($done['results'])->toBe([]);
    });
});

describe('A password kept as Robust keeps it', function () {
    it('is read from a list, with its salt', function () {
        $hash = AccountWriter::passwordHash('secret', 'abc123');
        $list = AccountList::parse(
            (string) json_encode([
                ['first' => 'Ann', 'last' => 'Lee', 'password_hash' => $hash, 'password_salt' => 'abc123'],
                ['first' => 'Bob', 'last' => 'Roe', 'password_hash' => 'not-a-hash', 'password_salt' => 'x'],
            ]),
            'json',
        );

        expect($list['accounts'][0]['password_hash'])
            ->toBe($hash)
            ->and($list['accounts'][0]['password_salt'])
            ->toBe('abc123')
            ->and(count($list['accounts']))
            ->toBe(1)
            ->and($list['errors'][0])
            ->toContain('password_hash');
    });

    it('is written as it is, and the password is not made', function () {
        $hash = AccountWriter::passwordHash('secret', 'abc123');
        $writer = new AccountWriter(fn() => '11111111-2222-3333-4444-555555555555', fn() => 1, fn() => 'randomsalt');
        $sql = $writer->statements('Ann', 'Lee', '', '', null, ['hash' => $hash, 'salt' => 'abc123'])['sql'];

        expect($sql)
            ->toContain("'$hash', 'abc123'")
            ->not->toContain('randomsalt');
    });
});
