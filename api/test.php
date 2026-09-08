<?php
// Fichier de test — a supprimer une fois la verification faite.
// Il verifie que l'hebergement peut faire tourner Trak, et rejoue l'arbitrage
// des conflits sur un compte jetable qu'il efface ensuite.

header('Content-Type: text/plain; charset=utf-8');

echo "== Test serveur Trak ==\n\n";
echo "PHP fonctionne. Version : " . PHP_VERSION . "\n";
line('Entiers 64 bits', PHP_INT_SIZE >= 8, 'PHP 32 bits : la synchro ne peut pas fonctionner');

$dir = __DIR__ . '/data';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
line('Dossier api/data', is_dir($dir), 'impossible a creer, verifiez les permissions du dossier api/');
line('Ecriture dans api/data', is_dir($dir) && is_writable($dir), 'le dossier existe mais PHP ne peut pas y ecrire');

if (!is_dir($dir) || !is_writable($dir)) {
    echo "\nSans droit d'ecriture, Trak ne peut pas enregistrer de comptes.\n";
    echo "Passez le dossier api/ (ou api/data/) en permissions 755 ou 775.\n";
    exit;
}

// --- Le coeur de la synchro : la valeur la plus recente doit gagner ----------
// On travaille sur un identifiant jetable, jamais sur de vraies donnees.
// store.php signale ses erreurs via fail() ; ici on la remplace par une version
// en texte brut, puisque cette page n'est pas une reponse JSON.
function fail(string $message, int $status = 400, string $code = 'error'): void {
    echo "\nECHEC du stockage : " . $message . "\n";
    exit;
}
require __DIR__ . '/store.php';

$testId = 'selftest-' . bin2hex(random_bytes(4));
$file   = $dir . '/user-' . $testId . '.php';
$ok     = true;

try {
    $day = '2026-01-01';
    $e = fn(int $u, int $done) => [['h' => 'test', 'd' => $day, 'done' => $done, 'note' => 'u' . $u, 'del' => 0, 'u' => $u]];

    syncMerge($testId, 1000, 0, [], $e(1000000000000, 1));
    $r = syncMerge($testId, 1001, 0, [], []);
    $ok = line('Premiere ecriture', ($r['entries'][0]['note'] ?? '') === 'u1000000000000') && $ok;

    syncMerge($testId, 1002, 0, [], $e(1000000002000, 0));
    $r = syncMerge($testId, 1003, 0, [], []);
    $ok = line('Une valeur plus recente remplace l ancienne', ($r['entries'][0]['note'] ?? '') === 'u1000000002000') && $ok;

    syncMerge($testId, 1004, 0, [], $e(1000000001000, 1));
    $r = syncMerge($testId, 1005, 0, [], []);
    $ok = line('Une valeur en retard est ignoree', ($r['entries'][0]['note'] ?? '') === 'u1000000002000',
               'ATTENTION : une vieille valeur a ecrase la recente') && $ok;

    $ok = line('Horodatage en millisecondes intact',
               (int) ($r['entries'][0]['u'] ?? 0) === 1000000002000,
               'la valeur a ete tronquee, les conflits seraient mal arbitres') && $ok;

    // Filtre "since" : seul ce qui a change apres le repere doit ressortir.
    syncMerge($testId, 5000, 0, [], $e(1000000009000, 1));
    $r = syncMerge($testId, 5001, 5000, [], []);
    $ok = line('Filtre des nouveautes (since)', count($r['entries']) === 0,
               'un appareil recevrait en boucle des donnees deja connues') && $ok;
} catch (Throwable $ex) {
    $ok = false;
    echo 'Test interrompu : ' . $ex->getMessage() . "\n";
}

@unlink($file);
line('Nettoyage du compte de test', !is_file($file));

// --- Etat -------------------------------------------------------------------
echo "\n";
$guard = $dir . '/.htaccess';
line('Protection de api/data (.htaccess)', is_file($guard), 'sera cree au premier compte');

$users = is_file($dir . '/users.php') ? count(storeRead('users')) : 0;
echo "Comptes enregistres : " . $users . "\n";
echo "Chemin de ce dossier : " . __DIR__ . "\n";

echo "\n" . ($ok
    ? ">>> Tout est bon. Supprimez ce fichier, puis ouvrez ../trak.html. <<<\n"
    : ">>> Une verification a echoue : ne vous servez pas encore de la synchro. <<<\n");

function line(string $label, bool $pass, string $hint = ''): bool {
    echo $label . ' : ' . ($pass ? 'OK' : 'ECHEC' . ($hint !== '' ? ' — ' . $hint : '')) . "\n";
    return $pass;
}
