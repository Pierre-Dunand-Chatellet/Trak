<?php
// Trak - noyau commun de l'API : reponses JSON, authentification.
// Le stockage lui-meme est dans store.php (fichiers, sans base de donnees).
declare(strict_types=1);

mb_internal_encoding('UTF-8');

const TRAK_TOKEN_TTL    = 60 * 60 * 24 * 365; // 1 an
const TRAK_MIN_PASSWORD = 8;

// Nombre maximum de comptes sur ce serveur. Une fois vos comptes crees, mettez
// cette valeur au nombre exact de comptes existants : les inscriptions sont
// alors fermees, et personne ne peut plus en creer.
const TRAK_MAX_ACCOUNTS = 15;

// --- CORS -------------------------------------------------------------------
// L'authentification passe par un jeton (pas de cookie), donc autoriser
// l'origine appelante n'expose pas la session a une attaque CSRF.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Max-Age: 86400');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// --- Reponses ---------------------------------------------------------------
function jsonOut(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400, string $code = 'error'): void {
    jsonOut(['ok' => false, 'code' => $code, 'error' => $message], $status);
}

function body(): array {
    static $cache = null;
    if (is_array($cache)) return $cache;
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return $cache = [];
    $data = json_decode($raw, true);
    return $cache = (is_array($data) ? $data : []);
}

function nowMs(): int {
    return (int) round(microtime(true) * 1000);
}

// Les horodatages sont en millisecondes : ils depassent la capacite d'un entier
// 32 bits et seraient tronques, faussant l'arbitrage des conflits.
if (PHP_INT_SIZE < 8) {
    fail('PHP 32 bits non supporte : la synchronisation exige des entiers 64 bits.', 500, 'php32');
}

// Refuse l'API en http : un mot de passe y circulerait en clair. La redirection
// https du .htaccess racine ne s'applique pas ici (api/.htaccess a son propre
// RewriteEngine), et rediriger un POST lui ferait perdre son corps.
// 127.0.0.1 reste permis pour tester en local.
$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
    || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
if (!$https && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    fail('Connexion non chiffree refusee : utilisez https.', 403, 'https_required');
}

require __DIR__ . '/store.php';

// --- Authentification -------------------------------------------------------
function bearerToken(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $header = (string) $v; break; }
        }
    }
    if (preg_match('/Bearer\s+([A-Za-z0-9._-]+)/i', $header, $m)) return $m[1];

    // Repli : beaucoup d'hebergements Apache ne transmettent pas l'en-tete
    // Authorization a PHP. L'application envoie donc aussi le jeton dans le corps.
    $fromBody = body()['token'] ?? '';
    return is_string($fromBody) ? $fromBody : '';
}

function newToken(): string {
    return bin2hex(random_bytes(32));
}

/** @return array{id:string,email:string,token:string} */
function requireUser(): array {
    $token = bearerToken();
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        fail('Non connecte.', 401, 'unauthorized');
    }

    $session = sessionGet($token);
    if (!$session) fail('Session expiree, reconnectez-vous.', 401, 'unauthorized');

    if (time() - (int) ($session['created_at'] ?? 0) > TRAK_TOKEN_TTL) {
        sessionDelete($token);
        fail('Session expiree, reconnectez-vous.', 401, 'unauthorized');
    }

    sessionTouch($token);

    return [
        'id'    => (string) ($session['id'] ?? ''),
        'email' => (string) ($session['email'] ?? ''),
        'token' => $token,
    ];
}

function clientIp(): string {
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function deviceLabel(): string {
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    foreach ([
        'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android',
        'Macintosh' => 'Mac', 'Windows' => 'Windows', 'Linux' => 'Linux',
    ] as $needle => $label) {
        if (stripos($ua, $needle) !== false) return $label;
    }
    return 'Appareil';
}
