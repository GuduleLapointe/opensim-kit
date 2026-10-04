<?php

declare(strict_types=1);

namespace OpenSim\Installer\Grid;

/**
 * The accounts of a grid written directly in its database, the way Robust writes them when `create user` is typed in
 * its console (UserAccountService.CreateUser, XInventoryService.CreateUserInventory), without the console: for a list
 * of hundreds of accounts it is too slow and too fragile, and no live answer is needed for each one.
 *
 * What is written, in one transaction per account: the account (`UserAccounts`), its password (`auth`), its home when
 * the grid has a default region (`GridUser`), its inventory (`inventoryfolders`: the root folder, the system folders)
 * and the default appearance items with their links in the Current Outfit (`inventoryitems`). The names of the tables
 * and the columns are the ones Robust creates, case included. The appearance itself (`Avatars`) is not written: an
 * avatar with none gets the default one when it enters a region.
 *
 * Written from the code of OpenSimulator, to check on a test grid before a list is trusted to it: the account must
 * log in, show its inventory and its default outfit.
 */
final class AccountWriter
{
    /** The permissions of PermissionMask.All and PermissionMask.Copy. */
    private const ALL = 2147483647;
    private const COPY = 32768;

    private const ZERO = '00000000-0000-0000-0000-000000000000';

    /** AssetType and InventoryType numbers of the core. */
    private const ASSET_BODYPART = 13;
    private const ASSET_CLOTHING = 5;
    private const ASSET_LINK = 24;
    private const INV_WEARABLE = 18;

    /**
     * The system folders under the root, in the order Robust makes them: name, type (FolderType of the core),
     * and the folders under it.
     *
     * @var list<array{0:string,1:int,2?:list<mixed>}>
     */
    private const FOLDERS = [
        ['Animations', 20],
        ['Body Parts', 13],
        ['Calling Cards', 2, [['Friends', 2, [['All', 2]]]]],
        ['Clothing', 5],
        ['Current Outfit', 46],
        ['Favorites', 23],
        ['Gestures', 21],
        ['Landmarks', 3],
        ['Lost And Found', 16],
        ['Notecards', 7],
        ['Objects', 6],
        ['Photo Album', 15],
        ['Scripts', 10],
        ['Sounds', 1],
        ['Textures', 0],
        ['Trash', 14],
        ['Settings', 56],
        ['Materials', 57],
    ];

    /**
     * The default appearance of a new account (CreateDefaultAppearanceEntries): the item, the asset of the core,
     * the folder it is put in, the type of the asset, and the type of wearable (WearableType) kept in its flags.
     *
     * @var list<array{0:string,1:string,2:string,3:int,4:int}>
     */
    private const OUTFIT = [
        ['Default Eyes', '4bb6fa4d-1cd2-498a-a84c-95c1a0e745a7', 'Body Parts', self::ASSET_BODYPART, 3],
        ['Default Shape', '66c41e39-38f9-f75a-024e-585989bfab73', 'Body Parts', self::ASSET_BODYPART, 0],
        ['Default Skin', '77c41e39-38f9-f75a-024e-585989bbabbb', 'Body Parts', self::ASSET_BODYPART, 1],
        ['Default Hair', 'd342e6c0-b9d2-11dc-95ff-0800200c9a66', 'Body Parts', self::ASSET_BODYPART, 2],
        ['Default Shirt', '00000000-38f9-1111-024e-222222111110', 'Clothing', self::ASSET_CLOTHING, 4],
        ['Default Pants', '00000000-38f9-1111-024e-222222111120', 'Clothing', self::ASSET_CLOTHING, 5],
    ];

    /**
     * @param \Closure():string $uuid     a new UUID
     * @param \Closure():int    $now      the unix time
     * @param \Closure():string $salt     a new salt, 32 characters
     */
    public function __construct(private \Closure $uuid, private \Closure $now, private \Closure $salt) {}

    /** The writer of real accounts: random ids, the time, a random salt. */
    public static function standard(): self
    {
        return new self(
            static function (): string {
                $bytes = random_bytes(16);
                $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
                $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

                return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
            },
            static fn(): int => time(),
            static fn(): string => bin2hex(random_bytes(16)),
        );
    }

    /** What Robust keeps of a password: MD5(MD5(password) + ':' + salt). */
    public static function passwordHash(string $password, string $salt): string
    {
        return md5(md5($password) . ':' . $salt);
    }

    /** A value of a statement, quoted: backslashes and quotes escaped, nothing else can end the string. */
    public static function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'", "\0"], ['\\\\', "\\'", ''], $value) . "'";
    }

    /**
     * The statements that make an account, in one transaction.
     *
     * @param ?string $homeRegionId the default region of the grid, home of the account, when it has one
     * @param ?string $hash the hash of the password and its salt, as Robust keeps them (an account that is
     *                      moved from a grid or from a file): then the password is not needed
     * @return array{id:string,sql:string} the id of the account and the statements
     */
    public function statements(
        string $first,
        string $last,
        string $email,
        string $password,
        ?string $homeRegionId = null,
        ?array $hash = null,
    ): array {
        $q = [self::class, 'quote'];
        $id = ($this->uuid)();
        $now = ($this->now)();
        $salt = $hash !== null ? $hash['salt'] : ($this->salt)();
        $passwordHash = $hash !== null ? $hash['hash'] : self::passwordHash($password, $salt);
        $sql = ['START TRANSACTION'];

        $sql[] =
            'INSERT INTO UserAccounts (PrincipalID, ScopeID, FirstName, LastName, Email, ServiceURLs, Created, UserLevel, UserFlags, UserTitle, active) VALUES (' .
            implode(', ', [
                $q($id),
                $q(self::ZERO),
                $q($first),
                $q($last),
                $q($email),
                $q('HomeURI= InventoryServerURI= AssetServerURI='),
                $now,
                0,
                0,
                $q(''),
                1,
            ]) .
            ')';
        $sql[] =
            'INSERT INTO auth (UUID, passwordHash, passwordSalt, webLoginKey, accountType) VALUES (' .
            implode(', ', [$q($id), $q($passwordHash), $q($salt), $q(self::ZERO), $q('UserAccount')]) .
            ')';
        if ($homeRegionId !== null) {
            $sql[] = GridAccounts::homeSql($id, $homeRegionId);
        }

        $folders = [];
        $root = ($this->uuid)();
        $sql[] = $this->folder($id, $root, self::ZERO, 'My Inventory', 8);
        $this->folders($sql, $folders, $id, $root, self::FOLDERS);

        foreach (self::OUTFIT as [$name, $asset, $folder, $assetType, $wearable]) {
            $item = ($this->uuid)();
            $sql[] = $this->item($id, $item, $asset, $assetType, $name, self::ALL, $wearable, $folders[$folder], $now);
            $sql[] = $this->item(
                $id,
                ($this->uuid)(),
                $item,
                self::ASSET_LINK,
                $name,
                self::COPY,
                $wearable,
                $folders['Current Outfit'],
                $now,
            );
        }
        $sql[] = 'COMMIT';

        return ['id' => $id, 'sql' => implode(";\n", $sql) . ';'];
    }

    /**
     * @param list<string>                                         $sql
     * @param array<string,string>                                 $folders name => id, for the top folders
     * @param list<array{0:string,1:int,2?:list<mixed>}>           $tree
     */
    private function folders(
        array &$sql,
        array &$folders,
        string $user,
        string $parent,
        array $tree,
        bool $top = true,
    ): void {
        foreach ($tree as $entry) {
            [$name, $type] = $entry;
            $id = ($this->uuid)();
            if ($top) {
                $folders[$name] = $id;
            }
            $sql[] = $this->folder($user, $id, $parent, $name, $type);
            if (isset($entry[2])) {
                $this->folders($sql, $folders, $user, $id, $entry[2], false);
            }
        }
    }

    private function folder(string $user, string $id, string $parent, string $name, int $type): string
    {
        return 'INSERT INTO inventoryfolders (folderName, type, version, folderID, agentID, parentFolderID) VALUES (' .
            implode(', ', [self::quote($name), $type, 1, self::quote($id), self::quote($user), self::quote($parent)]) .
            ')';
    }

    private function item(
        string $user,
        string $id,
        string $asset,
        int $assetType,
        string $name,
        int $permissions,
        int $flags,
        string $folder,
        int $now,
    ): string {
        return 'INSERT INTO inventoryitems (assetID, assetType, inventoryName, inventoryDescription, inventoryNextPermissions, inventoryCurrentPermissions, invType, creatorID, inventoryBasePermissions, inventoryEveryOnePermissions, salePrice, saleType, creationDate, groupID, groupOwned, flags, inventoryID, avatarID, parentFolderID, inventoryGroupPermissions) VALUES (' .
            implode(', ', [
                self::quote($asset),
                $assetType,
                self::quote($name),
                self::quote(''),
                $permissions,
                $permissions,
                self::INV_WEARABLE,
                self::quote($user),
                $permissions,
                $permissions,
                0,
                0,
                $now,
                self::quote(self::ZERO),
                0,
                $flags,
                self::quote($id),
                self::quote($user),
                self::quote($folder),
                $permissions,
            ]) .
            ')';
    }
}
