<?php

declare(strict_types=1);

namespace OpenSim\Installer\Setup;

use OpenSim\Installer\Config;
use OpenSim\Installer\Grid\AccountImporter;
use OpenSim\Installer\Grid\AccountList;
use OpenSim\Installer\Grid\Database;
use OpenSim\Installer\Grid\GridInfo;
use OpenSim\Installer\Grid\NewGrid;
use OpenSim\Installer\Grid\NewSim;
use OpenSim\Installer\Grid\SimConfig;
use OpenSim\Installer\SetupFailed;
use OpenSim\Installer\Ui\EditConfig;
use OpenSim\Installer\Ui\InstallerUi;
use OpenSim\Installer\Ui\PresetUi;

/**
 * Makes a setup that is described in a file (see SetupFile): the grid, its simulators and their regions,
 * then the accounts. It is the setup wizard, answered from the file: the same questions, the same checks,
 * the same files, with nobody to type.
 */
final class SetupRunner
{
    /** The questions the user still answers when asked to (the plan to accept, the administrator of the database). */
    public const ASK = ['Enter the credentials', 'Administrator user', 'Password of ', 'Try again'];

    public function __construct(private InstallerUi $ui) {}

    /**
     * The quick setup: the plan of the grid and of its first simulator are made first, shown together, and nothing
     * is written until the user accepts them (or edits them: EditConfig).
     *
     * @param array<string,mixed> $data  a setup (SetupFile::parse): a grid to make, its owner, one simulator
     * @return ?array{0:string,1:string} the nick of the grid, the instance of its simulator; null when abandoned
     * @throws EditConfig when the user does not accept the plan and wants to edit it
     */
    public function runQuick(array $data): ?array
    {
        $gridUi = new PresetUi($this->ui, SetupFile::gridAnswers($data), self::ASK);
        $prepared = (new NewGrid($gridUi))->prepare();
        if ($prepared === null) {
            return null;
        }
        [$gridPlan, $profile] = $prepared;

        // The first simulator is planned for the grid as it will be: its ports, its database, its first region
        $simUi = new PresetUi($this->ui, SetupFile::simAnswers($data, 0), self::ASK);
        $simPlan = (new NewSim($simUi))->prepare(GridInfo::fromPlan($gridPlan), $profile);
        if ($simPlan === null) {
            return null;
        }

        $this->ui->note(
            (new NewGrid($gridUi))->describe($gridPlan) . "\n\n" . (new NewSim($simUi))->describe($simPlan),
        );
        if (
            $this->ui->choose(
                _('Apply this configuration?'),
                ['yes' => _('Continue'), 'no' => _('Edit config')],
                'yes',
            ) !== 'yes'
        ) {
            throw new EditConfig();
        }

        $nick = (new NewGrid($gridUi))->complete($gridPlan, $profile, true);
        $grid = $nick === null ? null : GridInfo::load($profile, $nick);

        return $grid === null ? null : (new NewSim($simUi))->complete($simPlan, $grid, $profile, true);
    }

    /**
     * @param array<string,mixed> $data  a setup (SetupFile::parse)
     * @param list<string> $ask  the questions still asked of the user (self::ASK for the quick setup)
     * @param string $resultFile  where the passwords made for the accounts of the list are written
     * @param bool $prefill  every question is asked, the file giving the answers proposed (to edit a setup)
     * @return ?array{0:string,1:string} the nick of the grid, the instance of its last simulator; null when abandoned
     */
    public function run(array $data, array $ask = [], string $resultFile = '', bool $prefill = false): ?array
    {
        $profile = (new Config())->profile();
        $etcRoot = (string) ($profile['EtcRoot'] ?? '');
        $nick = (string) ($data['grid']['nick'] ?? '');

        if (trim((string) ($data['grid']['name'] ?? '')) !== '') {
            $answers = SetupFile::gridAnswers($data);
            if ($nick !== '' && is_dir("$etcRoot/grids/$nick")) {
                $this->ui->error(sprintf(_("The grid '%s' exists already: this setup makes new ones."), $nick));

                throw new SetupFailed('grid exists');
            }
            $nick = (new NewGrid(new PresetUi($this->ui, $answers, $ask, $prefill)))->run(null);
            if ($nick === null) {
                return null;
            }
        } elseif ($nick === '') {
            // No grid in the file: the simulators join the only grid there is
            $grids = array_map('basename', glob("$etcRoot/grids/*", GLOB_ONLYDIR) ?: []);
            $nick = count($grids) === 1 ? $grids[0] : '';
            if ($nick === '') {
                $this->ui->error(_('This setup has no grid, and there is not exactly one on this machine to use.'));

                throw new SetupFailed('no grid');
            }
        }

        // An owner whose password is given hashed is made in the database, as the accounts of a list are: the
        // console of Robust makes accounts from a password
        $owner = $data['owner'] ?? null;
        if (is_array($owner) && ($owner['password_hash'] ?? '') !== '' && ($owner['password'] ?? '') === '') {
            [$first, $lastName] = array_pad(explode(' ', trim((string) $owner['name']), 2), 2, '');
            $this->users(
                $profile,
                $nick,
                [
                    [
                        'first' => $first,
                        'last' => $lastName,
                        'email' => (string) ($owner['email'] ?? ''),
                        'password_hash' => (string) $owner['password_hash'],
                        'password_salt' => (string) ($owner['password_salt'] ?? ''),
                    ],
                ],
                $resultFile,
            );
        }

        $last = null;
        foreach ($data['simulators'] ?? [] as $i => $sim) {
            $made = (new NewSim(
                new PresetUi(
                    $this->ui,
                    SetupFile::simAnswers($data, $i) + ['Grid of the simulator' => $nick],
                    $ask,
                    $prefill,
                ),
            ))->run($nick);
            if ($made === null) {
                return null;
            }
            $last = $made;
            foreach (array_slice($sim['regions'] ?? [], 1, null, true) as $j => $_) {
                (new NewSim(
                    new PresetUi($this->ui, SetupFile::regionAnswers($data, $i, $j), $ask, $prefill),
                ))->addRegion($made[0], $this->simName($made));
            }
        }

        if (!empty($data['users'])) {
            $this->users($profile, $nick, $data['users'], $resultFile);
        }

        return [$nick, $last[1] ?? ''];
    }

    /** @param array{0:string,1:string} $made nick and instance of a simulator */
    private function simName(array $made): string
    {
        $etcRoot = (string) ((new Config())->profile()['EtcRoot'] ?? '');
        if (($name = SimConfig::readName("$etcRoot/grids/{$made[0]}/sims/{$made[1]}.ini")) !== null) {
            return $name;
        }
        $prefix = GridInfo::instanceName($made[0]) . '_';

        return str_starts_with($made[1], $prefix) ? substr($made[1], strlen($prefix)) : $made[1];
    }

    /** @param list<array<string,mixed>> $users */
    private function users(array $profile, string $nick, array $users, string $resultFile): void
    {
        $grid = GridInfo::load($profile, $nick);
        $list = AccountList::parse((string) json_encode(['accounts' => $users]), 'json');
        if ($grid === null || $grid->remote || $list['accounts'] === []) {
            $this->ui->warn(_('The accounts of the list were not made: no grid of this machine to make them in.'));

            return;
        }

        $done = AccountImporter::forDatabase(new Database($this->ui))->run($grid, $list['accounts'], true, true);
        if ($done['error'] !== null) {
            $this->ui->error($done['error']);

            return;
        }
        $counts = [];
        foreach ($done['results'] as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }
        $this->ui->note(
            sprintf(
                _('Accounts: %s'),
                implode(', ', array_map(static fn(string $s, int $n): string => "$n $s", array_keys($counts), $counts)),
            ),
        );

        $generated = array_filter(
            $done['results'],
            static fn(array $row): bool => $row['generated'] && $row['status'] !== AccountImporter::EXISTS,
        );
        if ($generated !== []) {
            $resultFile = $resultFile !== '' ? $resultFile : 'users-result-' . date('Ymd-His') . '.csv';
            $umask = umask(0o077);
            file_put_contents($resultFile, AccountImporter::resultCsv($done['results']));
            umask($umask);
            chmod($resultFile, 0o600);
            $this->ui->note(
                sprintf(_('The passwords made for the accounts are in %s (only you can read it).'), $resultFile),
            );
        }
    }
}
