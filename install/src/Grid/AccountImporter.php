<?php

declare(strict_types=1);

namespace OpenSim\Installer\Grid;

/**
 * Makes the accounts of a list in the database of a grid (see AccountWriter), or tells what it would make: the
 * names that exist are skipped, an account is written in one transaction, a failure leaves nothing half made.
 */
final class AccountImporter
{
    public const CREATED = 'created';
    public const WOULD_CREATE = 'would create';
    public const EXISTS = 'exists';
    public const FAILED = 'failed';
    public const NOT_TRIED = 'not tried';

    /** @param \Closure(GridPlan,string):?list<string> $query runs a statement on the database of a grid, null when it cannot */
    public function __construct(private \Closure $query, private AccountWriter $writer) {}

    /** The importer of a real grid, through the client of its database. */
    public static function forDatabase(Database $database): self
    {
        return new self(
            static fn(GridPlan $plan, string $sql): ?array => $database->select($plan, $sql),
            AccountWriter::standard(),
        );
    }

    /**
     * @param list<array{first:string,last:string,email:string,password:string,line:int}> $accounts
     * @param bool $apply     write the accounts, else only tell what would be written
     * @param bool $keepGoing go on after an account that failed
     * @return array{error:?string,results:list<array{first:string,last:string,email:string,status:string,detail:string,password:string,generated:bool}>}
     */
    public function run(GridInfo $grid, array $accounts, bool $apply, bool $keepGoing = false): array
    {
        $plan = $grid->databasePlan();
        $names = ($this->query)($plan, "SELECT CONCAT(FirstName, ' ', LastName) FROM UserAccounts");
        if ($names === null) {
            return ['error' => 'the database of the grid cannot be read', 'results' => []];
        }
        $exists = array_fill_keys(array_map('strtolower', array_map('trim', $names)), true);
        // The home of the accounts: the default region, when the grid has one
        $home = ($this->query)($plan, 'SELECT uuid FROM regions WHERE (flags & 1) = 1 ORDER BY regionName LIMIT 1');
        $homeId =
            $home !== null && isset($home[0]) && preg_match('/^[0-9a-fA-F-]{36}$/', trim($home[0])) === 1
                ? trim($home[0])
                : null;

        $results = [];
        $stopped = false;
        foreach ($accounts as $account) {
            $row = [
                'first' => $account['first'],
                'last' => $account['last'],
                'email' => $account['email'],
                'status' => '',
                'detail' => '',
                'password' => $account['password'],
                'generated' => false,
            ];
            if ($stopped) {
                $row['status'] = self::NOT_TRIED;
                $results[] = $row;
                continue;
            }
            if (isset($exists[strtolower("{$account['first']} {$account['last']}")])) {
                $row['status'] = self::EXISTS;
                $results[] = $row;
                continue;
            }
            $hash =
                ($account['password_hash'] ?? '') !== ''
                    ? ['hash' => $account['password_hash'], 'salt' => $account['password_salt'] ?? '']
                    : null;
            if ($row['password'] === '' && $hash === null) {
                $row['password'] = self::password();
                $row['generated'] = true;
            }
            if (!$apply) {
                $row['status'] = self::WOULD_CREATE;
                $row['detail'] = $homeId === null ? 'no default region yet: no home' : '';
                $results[] = $row;
                continue;
            }

            $statements = $this->writer->statements(
                $row['first'],
                $row['last'],
                $row['email'],
                $row['password'],
                $homeId,
                $hash,
            );
            if (($this->query)($plan, $statements['sql']) === null) {
                $row['status'] = self::FAILED;
                $row['detail'] = 'the database refused it, nothing was written for this account';
                $stopped = !$keepGoing;
            } else {
                $row['status'] = self::CREATED;
                $exists[strtolower("{$account['first']} {$account['last']}")] = true;
            }
            $results[] = $row;
        }

        return ['error' => null, 'results' => $results];
    }

    /** A password for an account that was given none: letters and digits. */
    public static function password(int $length = 12): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    /**
     * The result as a CSV for whoever sent the list: the passwords that were generated are the only ones in it.
     *
     * @param list<array{first:string,last:string,email:string,status:string,detail:string,password:string,generated:bool}> $results
     */
    public static function resultCsv(array $results): string
    {
        $out = fopen('php://memory', 'w+');
        fputcsv($out, ['first', 'last', 'email', 'status', 'password', 'detail'], ',', '"', '');
        foreach ($results as $row) {
            fputcsv(
                $out,
                [
                    $row['first'],
                    $row['last'],
                    $row['email'],
                    $row['status'],
                    $row['generated'] ? $row['password'] : '',
                    $row['detail'],
                ],
                ',',
                '"',
                '',
            );
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }
}
