<?php

declare(strict_types=1);

it('translates through a compiled catalogue', function () {
    if (!shell_exec('command -v msgfmt') || !preg_match('/fr_FR\.utf-?8/i', (string) shell_exec('locale -a'))) {
        test()->markTestSkipped('msgfmt or the fr_FR locale is missing');
    }
    $dir = test_tmp() . '/i18n-' . uniqid();
    mkdir("$dir/fr_FR/LC_MESSAGES", 0777, true);
    file_put_contents("$dir/fr.po", "msgid \"\"\nmsgstr \"Content-Type: text/plain; charset=UTF-8\\n\"\n\nmsgid \"Grid name\"\nmsgstr \"Nom de la grille\"\n");
    shell_exec('msgfmt -o ' . escapeshellarg("$dir/fr_FR/LC_MESSAGES/opensim-kit.mo") . ' ' . escapeshellarg("$dir/fr.po"));
    $code = 'require "' . dirname(__DIR__, 2) . '/vendor/autoload.php"; OpenSim\\Installer\\I18n::init($argv[1]); echo _("Grid name");';
    $out = shell_exec('LC_ALL=fr_FR.UTF-8 LANGUAGE=fr_FR php -r ' . escapeshellarg($code) . ' ' . escapeshellarg($dir));
    expect($out)->toBe('Nom de la grille');
});
