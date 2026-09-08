<?php
// Trak - synchronisation bidirectionnelle des habitudes et des jours coches.
//
// Appel : POST sync.php  (jeton dans l'en-tete Authorization: Bearer ..., ou
//                         dans le champ "token" du corps)
//   { "since": 0,
//     "habits":  [ {"id":"sport","name":"Sport","goal":4,"pos":3,"del":0,"u":1757000000000} ],
//     "entries": [ {"h":"sport","d":"2026-09-06","done":1,"note":"","del":0,"u":1757000000000} ] }
//
// Reponse : { "ok":true, "now":<horloge serveur ms>, "habits":[...], "entries":[...] }
//   - "now" doit etre renvoye tel quel en "since" a la synchro suivante.
//   - Arbitrage des conflits : la valeur dont le "u" (horloge de l'appareil qui
//     l'a produite) est le plus recent gagne, par habitude et par jour.
declare(strict_types=1);
require __DIR__ . '/_core.php';

const TRAK_MAX_PUSH = 10000;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Methode non autorisee.', 405, 'method');
}

$user = requireUser();
$in   = body();

$since = isset($in['since']) ? (int) $in['since'] : 0;
if ($since < 0) $since = 0;

$pushHabits  = is_array($in['habits'] ?? null) ? $in['habits'] : [];
$pushEntries = is_array($in['entries'] ?? null) ? $in['entries'] : [];

if (count($pushHabits) > TRAK_MAX_PUSH || count($pushEntries) > TRAK_MAX_PUSH) {
    fail('Trop de modifications en une fois.', 413, 'too_large');
}

$now = nowMs();
$out = syncMerge($user['id'], $now, $since, $pushHabits, $pushEntries);

jsonOut([
    'ok'      => true,
    'now'     => $now,
    'first'   => $since === 0,
    'habits'  => $out['habits'],
    'entries' => $out['entries'],
]);
