<?php
// Trak - stockage sur fichiers. Aucune base de donnees requise.
//
// Chaque fichier de donnees commence par une balise PHP qui sort immediatement :
// meme si le serveur venait a le servir directement, il n'en sortirait rien.
// (Ne jamais ecrire la balise fermante en toutes lettres dans un commentaire :
// elle ferait sortir PHP du mode code des cette ligne.)
// Les ecritures passent par un fichier temporaire puis un rename (atomique),
// et l'ensemble des acces
// est serialise par un verrou unique : deux appareils qui synchronisent en meme
// temps ne peuvent pas s'ecraser mutuellement.
declare(strict_types=1);

// Assemblee morceau par morceau pour qu'aucune balise litterale n'apparaisse ici.
const STORE_GUARD = '<' . '?php exit;' . ' ?' . ">\n";

function dataDir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;

    $dir = __DIR__ . '/data';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail('Impossible de creer le dossier api/data (permissions).', 500, 'storage');
    }
    if (!is_writable($dir)) {
        fail('Le dossier api/data n est pas accessible en ecriture.', 500, 'storage');
    }

    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    if (!file_exists($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');

    return $dir;
}

// --- Verrou global -----------------------------------------------------------
// Un seul verrou pour tout le stockage : les volumes sont minuscules et cela
// evite tout risque d'interblocage entre plusieurs fichiers.
function lockAcquire(): void {
    static $handle = null;
    static $depth  = 0;

    if ($depth === 0) {
        $handle = @fopen(dataDir() . '/.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            fail('Stockage momentanement indisponible.', 503, 'locked');
        }
        $GLOBALS['trak_lock_handle'] = $handle;
    }
    $depth++;
    $GLOBALS['trak_lock_depth'] = $depth;
}

function lockRelease(): void {
    $depth = (int) ($GLOBALS['trak_lock_depth'] ?? 0);
    if ($depth <= 0) return;
    $depth--;
    $GLOBALS['trak_lock_depth'] = $depth;
    if ($depth === 0 && isset($GLOBALS['trak_lock_handle'])) {
        flock($GLOBALS['trak_lock_handle'], LOCK_UN);
        fclose($GLOBALS['trak_lock_handle']);
        unset($GLOBALS['trak_lock_handle']);
    }
}

/** Execute $fn en tenant le verrou, meme si $fn leve une exception. */
function withLock(callable $fn) {
    lockAcquire();
    try {
        return $fn();
    } finally {
        lockRelease();
    }
}

// --- Lecture / ecriture ------------------------------------------------------
function storeRead(string $name): array {
    $file = dataDir() . '/' . $name . '.php';
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false) return [];
    $nl = strpos($raw, "\n");
    if ($nl === false) return [];
    $data = json_decode(substr($raw, $nl + 1), true);
    return is_array($data) ? $data : [];
}

function storeWrite(string $name, array $data): void {
    $file = dataDir() . '/' . $name . '.php';
    $tmp  = $file . '.' . getmypid() . '.tmp';
    $body = STORE_GUARD . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (@file_put_contents($tmp, $body) === false) {
        fail('Ecriture impossible dans api/data.', 500, 'storage');
    }
    // rename est atomique : jamais de fichier a moitie ecrit, meme en cas de coupure.
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        fail('Ecriture impossible dans api/data.', 500, 'storage');
    }
}

// --- Comptes -----------------------------------------------------------------
function userByEmail(string $email): ?array {
    $users = storeRead('users');
    if (!isset($users[$email]) || !is_array($users[$email])) return null;
    return $users[$email] + ['email' => $email];
}

function userCreate(string $email, string $hash): array {
    return withLock(function () use ($email, $hash) {
        $users = storeRead('users');
        if (isset($users[$email])) return [];   // cree entre-temps par une autre requete
        $user = [
            'id'         => bin2hex(random_bytes(8)),
            'pass_hash'  => $hash,
            'created_at' => time(),
        ];
        $users[$email] = $user;
        storeWrite('users', $users);
        return $user + ['email' => $email];
    });
}

function userSetPassword(string $email, string $hash): void {
    withLock(function () use ($email, $hash) {
        $users = storeRead('users');
        if (!isset($users[$email])) return;
        $users[$email]['pass_hash'] = $hash;
        storeWrite('users', $users);
    });
}

// --- Sessions ----------------------------------------------------------------
function sessionCreate(string $token, array $user, string $label): void {
    withLock(function () use ($token, $user, $label) {
        $sessions = storeRead('sessions');
        $sessions[$token] = [
            'id'         => $user['id'],
            'email'      => $user['email'],
            'label'      => $label,
            'created_at' => time(),
            'last_seen'  => time(),
        ];
        storeWrite('sessions', $sessions);
    });
}

function sessionGet(string $token): ?array {
    $sessions = storeRead('sessions');
    return isset($sessions[$token]) && is_array($sessions[$token]) ? $sessions[$token] : null;
}

/** Ne reecrit le fichier que si la derniere visite date de plus d'une minute. */
function sessionTouch(string $token): void {
    $sessions = storeRead('sessions');
    if (!isset($sessions[$token])) return;
    if (time() - (int) ($sessions[$token]['last_seen'] ?? 0) < 60) return;

    withLock(function () use ($token) {
        $sessions = storeRead('sessions');
        if (!isset($sessions[$token])) return;
        $sessions[$token]['last_seen'] = time();
        storeWrite('sessions', $sessions);
    });
}

function sessionDelete(string $token): void {
    withLock(function () use ($token) {
        $sessions = storeRead('sessions');
        if (!isset($sessions[$token])) return;
        unset($sessions[$token]);
        storeWrite('sessions', $sessions);
    });
}

/** @return array<string,array> jeton => session */
function sessionsOfUser(string $userId): array {
    $out = [];
    foreach (storeRead('sessions') as $token => $s) {
        if (is_array($s) && ($s['id'] ?? '') === $userId) $out[$token] = $s;
    }
    return $out;
}

function sessionsDeleteOthers(string $userId, string $keep): int {
    return withLock(function () use ($userId, $keep) {
        $sessions = storeRead('sessions');
        $n = 0;
        foreach ($sessions as $token => $s) {
            if (is_array($s) && ($s['id'] ?? '') === $userId && !hash_equals($keep, (string) $token)) {
                unset($sessions[$token]);
                $n++;
            }
        }
        if ($n) storeWrite('sessions', $sessions);
        return $n;
    });
}

// --- Tentatives de connexion -------------------------------------------------
function attemptsCount(string $ip, string $email, int $since): int {
    $n = 0;
    foreach (storeRead('attempts') as $a) {
        if (!is_array($a) || (int) ($a['at'] ?? 0) < $since) continue;
        if (($a['ip'] ?? '') === $ip || ($a['email'] ?? '') === $email) $n++;
    }
    return $n;
}

function attemptAdd(string $ip, string $email, int $since): void {
    withLock(function () use ($ip, $email, $since) {
        $kept = [];
        foreach (storeRead('attempts') as $a) {
            if (is_array($a) && (int) ($a['at'] ?? 0) >= $since) $kept[] = $a;
        }
        $kept[] = ['ip' => $ip, 'email' => $email, 'at' => time()];
        storeWrite('attempts', array_slice($kept, -200));
    });
}

function attemptsClear(string $ip, string $email): void {
    withLock(function () use ($ip, $email) {
        $kept = [];
        foreach (storeRead('attempts') as $a) {
            if (!is_array($a)) continue;
            // Une connexion reussie efface les echecs, jamais le compteur
            // d'inscriptions : sinon il suffirait de se connecter pour le remettre a zero.
            if (($a['kind'] ?? '') !== '') { $kept[] = $a; continue; }
            if (($a['ip'] ?? '') !== $ip && ($a['email'] ?? '') !== $email) $kept[] = $a;
        }
        storeWrite('attempts', $kept);
    });
}

// --- Inscriptions ------------------------------------------------------------
function userCount(): int {
    return count(storeRead('users'));
}

function registerCount(string $ip, int $since): int {
    $n = 0;
    foreach (storeRead('attempts') as $a) {
        if (!is_array($a) || (int) ($a['at'] ?? 0) < $since) continue;
        if (($a['kind'] ?? '') === 'register' && ($a['ip'] ?? '') === $ip) $n++;
    }
    return $n;
}

function registerRecord(string $ip, int $since): void {
    withLock(function () use ($ip, $since) {
        $kept = [];
        foreach (storeRead('attempts') as $a) {
            if (is_array($a) && (int) ($a['at'] ?? 0) >= $since) $kept[] = $a;
        }
        $kept[] = ['kind' => 'register', 'ip' => $ip, 'email' => '', 'at' => time()];
        storeWrite('attempts', array_slice($kept, -200));
    });
}

// --- Synchronisation ---------------------------------------------------------
/**
 * Fusionne ce que l'appareil envoie, puis renvoie tout ce qui a change depuis sa
 * derniere synchro. Lecture, arbitrage et ecriture se font sous un seul verrou.
 *
 * Arbitrage : la valeur dont l'horodatage d'appareil (cu) est le plus recent
 * gagne. A egalite, la derniere arrivee gagne — c'est le meme comportement que
 * la version SQL de ce projet.
 *
 * @return array{habits:array,entries:array}
 */
function syncMerge(string $userId, int $now, int $since, array $habits, array $entries): array {
    return withLock(function () use ($userId, $now, $since, $habits, $entries) {
        $name = 'user-' . $userId;
        $data = storeRead($name);
        $H = isset($data['habits']) && is_array($data['habits']) ? $data['habits'] : [];
        $E = isset($data['entries']) && is_array($data['entries']) ? $data['entries'] : [];
        $changed = false;

        foreach ($habits as $h) {
            if (!is_array($h)) continue;
            $id = trim((string) ($h['id'] ?? ''));
            if ($id === '' || strlen($id) > 64) continue;

            $cu = max(0, (int) ($h['u'] ?? 0));
            if (isset($H[$id]) && $cu < (int) ($H[$id]['cu'] ?? 0)) continue;

            $H[$id] = [
                'name' => mb_substr((string) ($h['name'] ?? ''), 0, 120),
                'goal' => max(1, min(7, (int) ($h['goal'] ?? 5))),
                'pos'  => max(0, min(9999, (int) ($h['pos'] ?? 0))),
                'del'  => !empty($h['del']) ? 1 : 0,
                'cu'   => $cu,
                'ua'   => $now,
            ];
            $changed = true;
        }

        foreach ($entries as $e) {
            if (!is_array($e)) continue;
            $hid = trim((string) ($e['h'] ?? ''));
            $day = trim((string) ($e['d'] ?? ''));
            if ($hid === '' || strlen($hid) > 64) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) continue;

            $k  = $hid . '|' . $day;
            $cu = max(0, (int) ($e['u'] ?? 0));
            if (isset($E[$k]) && $cu < (int) ($E[$k]['cu'] ?? 0)) continue;

            $E[$k] = [
                'done' => !empty($e['done']) ? 1 : 0,
                'note' => mb_substr((string) ($e['note'] ?? ''), 0, 2000),
                'del'  => !empty($e['del']) ? 1 : 0,
                'cu'   => $cu,
                'ua'   => $now,
            ];
            $changed = true;
        }

        if ($changed) {
            storeWrite($name, ['habits' => $H, 'entries' => $E]);
        }

        // Tout ce qui a bouge depuis la derniere synchro de cet appareil.
        $outH = [];
        foreach ($H as $id => $h) {
            if ((int) ($h['ua'] ?? 0) <= $since) continue;
            $outH[] = [
                'id'   => (string) $id,
                'name' => (string) ($h['name'] ?? ''),
                'goal' => (int) ($h['goal'] ?? 5),
                'pos'  => (int) ($h['pos'] ?? 0),
                'del'  => (int) ($h['del'] ?? 0),
                'u'    => (int) ($h['cu'] ?? 0),
            ];
        }
        usort($outH, fn($a, $b) => ($a['pos'] <=> $b['pos']) ?: strcmp($a['id'], $b['id']));

        $outE = [];
        foreach ($E as $k => $e) {
            if ((int) ($e['ua'] ?? 0) <= $since) continue;
            $sep = strrpos((string) $k, '|');
            if ($sep === false) continue;
            $outE[] = [
                'h'    => substr((string) $k, 0, $sep),
                'd'    => substr((string) $k, $sep + 1),
                'done' => (int) ($e['done'] ?? 0),
                'note' => (string) ($e['note'] ?? ''),
                'del'  => (int) ($e['del'] ?? 0),
                'u'    => (int) ($e['cu'] ?? 0),
            ];
        }
        usort($outE, fn($a, $b) => strcmp($a['d'], $b['d']));

        return ['habits' => $outH, 'entries' => $outE];
    });
}
