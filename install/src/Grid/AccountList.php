<?php

declare(strict_types=1);

namespace OpenSim\Installer\Grid;

/**
 * A list of accounts to make, given as CSV (first, last, email, password, with or without a header line) or
 * as JSON (a list of objects with the same keys), as a third party gives it.
 */
final class AccountList
{
    /**
     * @return array{accounts:list<array{first:string,last:string,email:string,password:string,password_hash:string,password_salt:string,line:int}>,errors:list<string>}
     */
    public static function parse(string $text, ?string $format = null): array
    {
        $text = ltrim($text, "\xEF\xBB\xBF"); // the byte order mark of a spreadsheet's export
        $format ??= str_starts_with(ltrim($text), '[') || str_starts_with(ltrim($text), '{') ? 'json' : 'csv';
        $rows = match ($format) {
            'json' => self::jsonRows($text),
            'yaml' => self::dataRows(self::yaml($text)),
            default => self::csvRows($text),
        };

        $accounts = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $line => $row) {
            $first = trim((string) ($row['first'] ?? ''));
            $last = trim((string) ($row['last'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            $problem = null;
            $hash = strtolower(trim((string) ($row['password_hash'] ?? '')));
            $salt = trim((string) ($row['password_salt'] ?? ''));
            if (
                $hash !== '' &&
                (preg_match('/^[0-9a-f]{32}$/', $hash) !== 1 || preg_match('/^[0-9a-zA-Z]{1,64}$/', $salt) !== 1)
            ) {
                $problem = 'password_hash is the 32 hexadecimal characters Robust keeps, with its password_salt';
            } elseif (!GridAccounts::validName("$first $last")) {
                $problem = "name \"$first $last\" must be a first and a last name of letters, digits, . _ and -";
            } elseif ($email !== '' && preg_match('/^[^\s\'"\\\;]+@[^\s\'"\\\;]+$/', $email) !== 1) {
                $problem = "email \"$email\" is not an address";
            } elseif (isset($seen[strtolower("$first $last")])) {
                $problem = "$first $last is in the list twice";
            }
            if ($problem !== null) {
                $errors[] = "line $line: $problem";
                continue;
            }
            $seen[strtolower("$first $last")] = true;
            $accounts[] = [
                'first' => $first,
                'last' => $last,
                'email' => $email,
                'password' => (string) ($row['password'] ?? ''),
                // The password as Robust keeps it: given instead of the password, it is written as it is
                'password_hash' => $hash,
                'password_salt' => $salt,
                'line' => $line,
            ];
        }

        return ['accounts' => $accounts, 'errors' => $errors];
    }

    /** @return array<int,array<string,mixed>> line number => values */
    private static function jsonRows(string $text): array
    {
        return self::dataRows(json_decode($text, true));
    }

    /** The accounts of a list, or of the `users` (or `accounts`) of a setup file. */
    private static function dataRows(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $list = array_is_list($data) ? $data : $data['accounts'] ?? ($data['users'] ?? []);
        $rows = [];
        foreach (is_array($list) ? $list : [] as $i => $entry) {
            if (is_array($entry)) {
                $rows[$i + 1] = self::keys($entry);
            }
        }

        return $rows;
    }

    private static function yaml(string $text): mixed
    {
        try {
            return \Symfony\Component\Yaml\Yaml::parse($text);
        } catch (\Symfony\Component\Yaml\Exception\ParseException) {
            return null;
        }
    }

    /** @return array<int,array<string,mixed>> line number => values */
    private static function csvRows(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $first = trim((string) ($lines[0] ?? ''));
        $delimiter =
            substr_count($first, ';') > substr_count($first, ',') ? ';' : (str_contains($first, "\t") ? "\t" : ',');
        $names = ['first', 'last', 'email', 'password'];
        $header = null;
        if (preg_match('/^"?(first|firstname|first name)"?\s*[' . preg_quote($delimiter, '/') . ']/i', $first) === 1) {
            $header = array_map(
                static fn(string $cell): string => self::normalize($cell),
                str_getcsv($first, $delimiter, '"', ''),
            );
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            if (($i === 0 && $header !== null) || trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter, '"', '');
            $rows[$i + 1] =
                $header !== null
                    ? self::keys(
                        array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), '')),
                    )
                    : array_combine($names, array_pad(array_slice($cells, 0, 4), 4, ''));
        }

        return $rows;
    }

    /**
     * The keys a list may use for a value, brought to first, last, email, password.
     *
     * @param array<string|int,mixed> $entry
     * @return array<string,mixed>
     */
    private static function keys(array $entry): array
    {
        $values = [];
        foreach ($entry as $key => $value) {
            $values[self::normalize((string) $key)] = $value;
        }

        return $values;
    }

    private static function normalize(string $key): string
    {
        return match (strtolower(trim(preg_replace('/[^A-Za-z]/', '', $key) ?? ''))) {
            'first', 'firstname' => 'first',
            'last', 'lastname', 'surname' => 'last',
            'email', 'mail' => 'email',
            'password', 'pass', 'pwd' => 'password',
            'passwordhash', 'hash' => 'password_hash',
            'passwordsalt', 'salt' => 'password_salt',
            default => strtolower(trim($key)),
        };
    }
}
