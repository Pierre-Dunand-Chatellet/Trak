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
        fail('Impossible de créer le dossier api/data (permissions).', 500, 'storage');
    }
    if (!is_writable($dir)) {
        fail("Le dossier api/data n'est pas accessible en écriture.", 500, 'storage');
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
            fail('Stockage momentanément indisponible.', 503, 'locked');
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
        fail('Écriture impossible dans api/data.', 500, 'storage');
    }
    // rename est atomique : jamais de fichier a moitie ecrit, meme en cas de coupure.
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        fail('Écriture impossible dans api/data.', 500, 'storage');
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

/**
 * Supprime un compte : son fichier de donnees, l'utilisateur, toutes ses sessions et les
 * tentatives de connexion ratees notees a son adresse. Tout se fait sous le verrou, et le
 * fichier de donnees part en premier : s'il ne peut pas etre efface, rien d'autre ne bouge
 * et l'on peut reessayer. Renvoie false si le compte n'existe pas (ou plus) sous cet identifiant.
 */
function userDelete(string $email, string $userId): bool {
    if (!preg_match('/^[a-f0-9]{1,64}$/', $userId)) return false;
    return withLock(function () use ($email, $userId) {
        $users = storeRead('users');
        if (!isset($users[$email]) || !is_array($users[$email]) || ($users[$email]['id'] ?? '') !== $userId) return false;

        $file = dataDir() . '/user-' . $userId . '.php';
        if (is_file($file) && !@unlink($file)) {
            fail('Suppression impossible dans api/data. Réessayez.', 500, 'storage');
        }

        unset($users[$email]);
        storeWrite('users', $users);

        $sessions = sessionsRead();
        $n = 0;
        foreach ($sessions as $key => $s) {
            if (is_array($s) && ($s['id'] ?? '') === $userId) { unset($sessions[$key]); $n++; }
        }
        if ($n) storeWrite('sessions', $sessions);

        $kept = [];
        $attempts = storeRead('attempts');
        foreach ($attempts as $a) {
            if (is_array($a) && ($a['email'] ?? '') !== $email) $kept[] = $a;
        }
        if (count($kept) !== count($attempts)) storeWrite('attempts', $kept);
        return true;
    });
}

// --- Sessions ----------------------------------------------------------------
// Le jeton ne vit que chez l'appareil. Le serveur n'en garde que l'empreinte (sha256) : une fuite de
// sessions.php (sauvegarde, dossier mal protege) ne donne aucune session utilisable. Le jeton a 256
// bits au hasard : un simple sha256 suffit, inutile d'un hachage lent comme pour un mot de passe.
// Les cles hachees portent le prefixe "h:". Les sessions creees avant ce changement (jeton en clair)
// sont converties une seule fois, sous verrou, a la premiere lecture : personne n'est deconnecte.
const SESSION_PREFIX = 'h:';

function sessionKey(string $token): string {
    return SESSION_PREFIX . hash('sha256', $token);
}

/** @return array<string,array> empreinte du jeton => session (convertit au passage les anciennes) */
function sessionsRead(): array {
    $sessions = storeRead('sessions');
    foreach (array_keys($sessions) as $key) {
        if (strncmp((string) $key, SESSION_PREFIX, strlen(SESSION_PREFIX)) !== 0) return sessionsMigrate();
    }
    return $sessions;
}

function sessionsMigrate(): array {
    return withLock(function () {
        $old = storeRead('sessions');
        $new = [];
        foreach ($old as $key => $s) {
            $key = (string) $key;
            $new[strncmp($key, SESSION_PREFIX, strlen(SESSION_PREFIX)) === 0 ? $key : sessionKey($key)] = $s;
        }
        if ($new !== $old) storeWrite('sessions', $new);
        return $new;
    });
}

function sessionCreate(string $token, array $user, string $label): void {
    withLock(function () use ($token, $user, $label) {
        $sessions = sessionsRead();
        $sessions[sessionKey($token)] = [
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
    $sessions = sessionsRead();
    $key = sessionKey($token);
    return isset($sessions[$key]) && is_array($sessions[$key]) ? $sessions[$key] : null;
}

/** Ne reecrit le fichier que si la derniere visite date de plus d'une minute. */
function sessionTouch(string $token): void {
    $key = sessionKey($token);
    $sessions = sessionsRead();
    if (!isset($sessions[$key])) return;
    if (time() - (int) ($sessions[$key]['last_seen'] ?? 0) < 60) return;

    withLock(function () use ($key) {
        $sessions = sessionsRead();
        if (!isset($sessions[$key])) return;
        $sessions[$key]['last_seen'] = time();
        storeWrite('sessions', $sessions);
    });
}

function sessionDelete(string $token): void {
    $key = sessionKey($token);
    withLock(function () use ($key) {
        $sessions = sessionsRead();
        if (!isset($sessions[$key])) return;
        unset($sessions[$key]);
        storeWrite('sessions', $sessions);
    });
}

/** @return array<string,array> empreinte du jeton => session */
function sessionsOfUser(string $userId): array {
    $out = [];
    foreach (sessionsRead() as $key => $s) {
        if (is_array($s) && ($s['id'] ?? '') === $userId) $out[$key] = $s;
    }
    return $out;
}

/** $keep = le jeton (en clair) de l'appareil a garder. */
function sessionsDeleteOthers(string $userId, string $keep): int {
    $keepKey = sessionKey($keep);
    return withLock(function () use ($userId, $keepKey) {
        $sessions = sessionsRead();
        $n = 0;
        foreach ($sessions as $key => $s) {
            if (is_array($s) && ($s['id'] ?? '') === $userId && !hash_equals($keepKey, (string) $key)) {
                unset($sessions[$key]);
                $n++;
            }
        }
        if ($n) storeWrite('sessions', $sessions);
        return $n;
    });
}

// --- Tentatives de connexion -------------------------------------------------
// Echecs de connexion recents pour CETTE IP seulement. Compter aussi par e-mail
// permettait a un tiers connaissant l'adresse de verrouiller le compte a distance
// (10 tentatives ratees suffisaient a bloquer la victime, meme depuis d'autres IP).
function attemptsCount(string $ip, int $since): int {
    $n = 0;
    foreach (storeRead('attempts') as $a) {
        if (!is_array($a) || (int) ($a['at'] ?? 0) < $since) continue;
        if (($a['ip'] ?? '') === $ip) $n++;
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
        storeWrite('attempts', array_slice($kept, -500));
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

// Inscriptions comptees dans leur propre fichier : une rafale d'echecs de connexion ne
// peut plus evincer ces enregistrements et diluer la limite de 3 inscriptions par heure.
function registerCount(string $ip, int $since): int {
    $n = 0;
    foreach (storeRead('registers') as $a) {
        if (!is_array($a) || (int) ($a['at'] ?? 0) < $since) continue;
        if (($a['ip'] ?? '') === $ip) $n++;
    }
    return $n;
}

function registerRecord(string $ip, int $since): void {
    withLock(function () use ($ip, $since) {
        $kept = [];
        foreach (storeRead('registers') as $a) {
            if (is_array($a) && (int) ($a['at'] ?? 0) >= $since) $kept[] = $a;
        }
        $kept[] = ['ip' => $ip, 'at' => time()];
        storeWrite('registers', array_slice($kept, -200));
    });
}

// --- Synchronisation ---------------------------------------------------------
/** Temps de passage d'un chrono : 30 entiers au plus (centiemes), chacun entre 1 et 360000. */
function passages($sp): array {
    if (!is_array($sp)) return [];
    $out = [];
    foreach (array_slice($sp, 0, 30) as $x) $out[] = max(1, min(360000, (int) $x));
    return $out;
}

/** Metres de chaque seance d'une journee : 3 nombres au plus, chacun entre 0 et 100 000. */
function seances($s): array {
    if (!is_array($s)) return [];
    $out = [];
    foreach (array_slice(array_values($s), 0, 3) as $x) {
        if (!is_int($x) && !is_float($x) && !(is_string($x) && is_numeric($x))) continue;
        $out[] = (int) round(max(0.0, min(100000.0, (float) $x)));
    }
    return $out;
}

/** Identifiant de competition rattache a un chrono (cid) : 64 caracteres sures au plus, sinon vide. */
function idCompet($v): string {
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^[A-Za-z0-9:_-]{1,64}$/', $s) ? $s : '';
}

/** Contexte d'un chrono : 0 entrainement, 1 competition, 2 relais (depart lance). */
function contexte($v): int {
    if (is_numeric($v) && (int) $v === 2) return 2;
    return !empty($v) ? 1 : 0;
}

function totalSeances(array $s): int {
    return min(100000, array_sum($s));
}

/**
 * Fusionne ce que l'appareil envoie, puis renvoie tout ce qui a change depuis sa
 * derniere synchro. Lecture, arbitrage et ecriture se font sous un seul verrou.
 *
 * Arbitrage : la valeur dont l'horodatage d'appareil (cu) est le plus recent
 * gagne. A egalite, la derniere arrivee gagne, c'est le meme comportement que
 * la version SQL de ce projet.
 *
 * Les chronos (temps de course) suivent la meme regle, temps par temps, et les
 * items (objectifs, competitions : un type + un petit JSON que le serveur ne lit pas) aussi.
 *
 * @return array{habits:array,entries:array,times:array,items:array}
 */
function syncMerge(string $userId, int $now, int $since, array $habits, array $entries, array $times = [], array $items = []): array {
    return withLock(function () use ($userId, $now, $since, $habits, $entries, $times, $items) {
        // Le compte a pu etre supprime entre la verification du jeton et ce verrou : sans ce
        // controle, une synchro partie juste avant recreerait son fichier de donnees.
        if (!sessionsOfUser($userId)) fail('Session expirée, reconnectez-vous.', 401, 'unauthorized');
        $name = 'user-' . $userId;
        $data = storeRead($name);
        $H = isset($data['habits']) && is_array($data['habits']) ? $data['habits'] : [];
        $E = isset($data['entries']) && is_array($data['entries']) ? $data['entries'] : [];
        $T = isset($data['times']) && is_array($data['times']) ? $data['times'] : [];
        $I = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
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
                'dist' => !empty($h['dist']) ? 1 : 0,
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

            // Seances (s) : les metres de chaque seance du jour, a partir de deux ; m reste
            // le total, seul lu par les anciens clients. Un ancien client n'envoie pas « s » :
            // s'il n'a pas touche au total, les seances deja connues sont gardees.
            $m = max(0, min(100000, (int) ($e['m'] ?? 0)));
            if (array_key_exists('s', $e)) {
                $s = seances($e['s']);
            } else {
                $s = isset($E[$k]['s']) ? seances($E[$k]['s']) : [];
                if (totalSeances($s) !== $m) $s = [];
            }
            if (count($s) > 1 && empty($e['del'])) $m = totalSeances($s); else $s = [];

            $E[$k] = [
                'done' => !empty($e['done']) ? 1 : 0,
                'note' => mb_substr((string) ($e['note'] ?? ''), 0, 2000),
                'm'    => $m,
                's'    => $s,
                'del'  => !empty($e['del']) ? 1 : 0,
                'cu'   => $cu,
                'ua'   => $now,
            ];
            $changed = true;
        }

        foreach ($times as $t) {
            if (!is_array($t)) continue;
            $id = trim((string) ($t['id'] ?? ''));
            if ($id === '' || strlen($id) > 64) continue;
            $del = !empty($t['del']) ? 1 : 0;
            $ev  = (string) ($t['ev'] ?? '');
            $day = (string) ($t['d'] ?? '');
            // Un temps supprime n'a plus besoin d'epreuve ni de date valides.
            if (!$del && (!preg_match('/^[a-z0-9-]{1,20}$/', $ev) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day))) continue;

            $cu = max(0, (int) ($t['u'] ?? 0));
            if (isset($T[$id]) && $cu < (int) ($T[$id]['cu'] ?? 0)) continue;

            // cid : la competition ou le temps a ete nage. Un ancien client ne l'envoie pas :
            // celui deja connu pour ce temps est garde.
            $cid = array_key_exists('cid', $t) ? idCompet($t['cid']) : idCompet($T[$id]['cid'] ?? '');

            $T[$id] = $del ? ['del' => 1, 'cu' => $cu, 'ua' => $now] : [
                'ev'   => $ev,
                'pool' => ((int) ($t['pool'] ?? 25)) === 50 ? 50 : 25,
                't'    => max(1, min(360000, (int) ($t['t'] ?? 0))),   // centiemes, 1 h au plus
                'd'    => $day,
                'comp' => contexte($t['comp'] ?? 0),                    // 2 = relais, hors record
                'lieu' => mb_substr((string) ($t['lieu'] ?? ''), 0, 120),
                'sp'   => passages($t['sp'] ?? []),
                'cid'  => $cid,
                'del'  => 0,
                'cu'   => $cu,
                'ua'   => $now,
            ];
            $changed = true;
        }

        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $id = trim((string) ($it['id'] ?? ''));
            if ($id === '' || strlen($id) > 64) continue;
            $del  = !empty($it['del']) ? 1 : 0;
            $kind = (string) ($it['k'] ?? '');
            $json = (string) ($it['data'] ?? '');
            // Le contenu reste opaque pour le serveur : on borne seulement sa taille et on exige du JSON.
            if (!$del && (!preg_match('/^[a-z]{1,12}$/', $kind) || strlen($json) > 4000 || !is_array(json_decode($json, true)))) continue;

            $cu = max(0, (int) ($it['u'] ?? 0));
            if (isset($I[$id]) && $cu < (int) ($I[$id]['cu'] ?? 0)) continue;

            $I[$id] = $del ? ['del' => 1, 'cu' => $cu, 'ua' => $now]
                : ['k' => $kind, 'data' => $json, 'del' => 0, 'cu' => $cu, 'ua' => $now];
            $changed = true;
        }

        if ($changed) {
            storeWrite($name, ['habits' => $H, 'entries' => $E, 'times' => $T, 'items' => $I]);
        }

        // Tout ce qui a bouge depuis la derniere synchro de cet appareil.
        $outH = [];
        foreach ($H as $id => $h) {
            if ((int) ($h['ua'] ?? 0) <= $since) continue;
            $outH[] = [
                'id'   => (string) $id,
                'name' => (string) ($h['name'] ?? ''),
                'goal' => (int) ($h['goal'] ?? 5),
                'dist' => (int) ($h['dist'] ?? 0),
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
                'm'    => (int) ($e['m'] ?? 0),
                's'    => seances($e['s'] ?? []),
                'del'  => (int) ($e['del'] ?? 0),
                'u'    => (int) ($e['cu'] ?? 0),
            ];
        }
        usort($outE, fn($a, $b) => strcmp($a['d'], $b['d']));

        $outT = [];
        foreach ($T as $id => $t) {
            if ((int) ($t['ua'] ?? 0) <= $since) continue;
            $outT[] = [
                'id'   => (string) $id,
                'ev'   => (string) ($t['ev'] ?? ''),
                'pool' => (int) ($t['pool'] ?? 25),
                't'    => (int) ($t['t'] ?? 0),
                'd'    => (string) ($t['d'] ?? ''),
                'comp' => contexte($t['comp'] ?? 0),
                'lieu' => (string) ($t['lieu'] ?? ''),
                'sp'   => passages($t['sp'] ?? []),
                'cid'  => idCompet($t['cid'] ?? ''),
                'del'  => (int) ($t['del'] ?? 0),
                'u'    => (int) ($t['cu'] ?? 0),
            ];
        }

        $outI = [];
        foreach ($I as $id => $it) {
            if ((int) ($it['ua'] ?? 0) <= $since) continue;
            $outI[] = [
                'id'   => (string) $id,
                'k'    => (string) ($it['k'] ?? ''),
                'data' => (string) ($it['data'] ?? ''),
                'del'  => (int) ($it['del'] ?? 0),
                'u'    => (int) ($it['cu'] ?? 0),
            ];
        }

        return ['habits' => $outH, 'entries' => $outE, 'times' => $outT, 'items' => $outI];
    });
}
