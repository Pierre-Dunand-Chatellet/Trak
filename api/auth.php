<?php
// Trak - comptes : inscription, connexion, session, appareils.
// Appel : POST auth.php  { "action": "register|login|logout|me|password|devices|revoke_others" }
declare(strict_types=1);
require __DIR__ . '/_core.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Methode non autorisee.', 405, 'method');
}

$in     = body();
$action = isset($in['action']) && is_string($in['action']) ? $in['action'] : '';

switch ($action) {
    case 'register':      register($in); break;
    case 'login':         login($in); break;
    case 'logout':        logout(); break;
    case 'me':            me(); break;
    case 'password':      changePassword($in); break;
    case 'devices':       devices(); break;
    case 'revoke_others': revokeOthers(); break;
    default:              fail('Action inconnue.', 400, 'bad_action');
}

// --- Actions ----------------------------------------------------------------

function register(array $in): void {
    // Sans ces deux limites, n'importe qui trouvant l'URL pourrait creer des
    // comptes en boucle et remplir le disque du serveur.
    if (userCount() >= TRAK_MAX_ACCOUNTS) {
        fail('Les inscriptions sont fermees sur ce serveur.', 403, 'closed');
    }
    if (registerCount(clientIp(), time() - 3600) >= 3) {
        fail('Trop de comptes crees depuis cette connexion. Reessayez plus tard.', 429, 'throttled');
    }

    [$email, $password] = credentials($in);

    if (mb_strlen($password) < TRAK_MIN_PASSWORD) {
        fail('Le mot de passe doit faire au moins ' . TRAK_MIN_PASSWORD . ' caracteres.', 400, 'weak_password');
    }
    if (userByEmail($email)) {
        fail('Un compte existe deja avec cette adresse.', 409, 'email_taken');
    }

    $user = userCreate($email, password_hash($password, PASSWORD_DEFAULT));
    if (!$user) fail('Un compte existe deja avec cette adresse.', 409, 'email_taken');

    registerRecord(clientIp(), time() - 3600);
    issueSession($user, true);
}

function login(array $in): void {
    [$email, $password] = credentials($in);
    throttle($email);

    $user = userByEmail($email);

    if (!$user) {
        // Calcul factice : sans lui, un compte inexistant repondrait bien plus
        // vite qu'un mot de passe faux et deviendrait devinable au chronometre.
        password_verify($password, password_hash($password, PASSWORD_DEFAULT));
        recordFailure($email);
        fail('Adresse ou mot de passe incorrect.', 401, 'bad_credentials');
    }

    $hash = (string) $user['pass_hash'];
    if (!password_verify($password, $hash)) {
        recordFailure($email);
        fail('Adresse ou mot de passe incorrect.', 401, 'bad_credentials');
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        userSetPassword($email, password_hash($password, PASSWORD_DEFAULT));
    }

    clearFailures($email);
    issueSession($user, false);
}

function logout(): void {
    $token = bearerToken();
    if ($token !== '') sessionDelete($token);
    jsonOut(['ok' => true]);
}

function me(): void {
    $user = requireUser();
    jsonOut(['ok' => true, 'user' => ['email' => $user['email']], 'now' => nowMs()]);
}

function changePassword(array $in): void {
    $user    = requireUser();
    $current = isset($in['current']) && is_string($in['current']) ? $in['current'] : '';
    $next    = isset($in['password']) && is_string($in['password']) ? $in['password'] : '';

    if (mb_strlen($next) < TRAK_MIN_PASSWORD) {
        fail('Le nouveau mot de passe doit faire au moins ' . TRAK_MIN_PASSWORD . ' caracteres.', 400, 'weak_password');
    }

    $record = userByEmail($user['email']);
    if (!$record || !password_verify($current, (string) $record['pass_hash'])) {
        fail('Mot de passe actuel incorrect.', 401, 'bad_credentials');
    }

    userSetPassword($user['email'], password_hash($next, PASSWORD_DEFAULT));
    // Les autres appareils devront se reconnecter.
    sessionsDeleteOthers($user['id'], $user['token']);

    jsonOut(['ok' => true]);
}

function devices(): void {
    $user = requireUser();

    $list = [];
    foreach (sessionsOfUser($user['id']) as $token => $s) {
        $list[] = [
            'label'   => (string) ($s['label'] ?? 'Appareil'),
            'since'   => (int) ($s['created_at'] ?? 0),
            'seen'    => (int) ($s['last_seen'] ?? 0),
            'current' => hash_equals($user['token'], (string) $token),
        ];
    }
    usort($list, fn($a, $b) => $b['seen'] <=> $a['seen']);

    jsonOut(['ok' => true, 'devices' => $list]);
}

function revokeOthers(): void {
    $user = requireUser();
    jsonOut(['ok' => true, 'revoked' => sessionsDeleteOthers($user['id'], $user['token'])]);
}

// --- Utilitaires ------------------------------------------------------------

/** @return array{0:string,1:string} */
function credentials(array $in): array {
    $email    = isset($in['email']) && is_string($in['email']) ? mb_strtolower(trim($in['email'])) : '';
    $password = isset($in['password']) && is_string($in['password']) ? $in['password'] : '';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        fail('Adresse e-mail invalide.', 400, 'bad_email');
    }
    if ($password === '' || mb_strlen($password) > 200) {
        fail('Mot de passe invalide.', 400, 'bad_password');
    }
    return [$email, $password];
}

function issueSession(array $user, bool $created): void {
    $token = newToken();
    sessionCreate($token, $user, deviceLabel());

    jsonOut([
        'ok'      => true,
        'created' => $created,
        'token'   => $token,
        'user'    => ['email' => $user['email']],
        'now'     => nowMs(),
    ], $created ? 201 : 200);
}

function throttle(string $email): void {
    if (attemptsCount(clientIp(), $email, time() - 900) >= 10) {
        fail('Trop de tentatives. Reessayez dans quelques minutes.', 429, 'throttled');
    }
}

function recordFailure(string $email): void {
    attemptAdd(clientIp(), $email, time() - 900);
}

function clearFailures(string $email): void {
    attemptsClear(clientIp(), $email);
}
