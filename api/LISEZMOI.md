# API Trak — comptes et synchronisation

Backend minimal en PHP : un compte, plusieurs appareils, les mêmes données partout.
**Aucune base de données** — les données vivent dans des fichiers JSON, sous `api/data/`.

## Fichiers

| Fichier | Rôle |
| --- | --- |
| `store.php` | Le stockage : fichiers, verrou, arbitrage des conflits. |
| `_core.php` | Réponses JSON, vérification du jeton. Pas appelé directement. |
| `auth.php` | Inscription, connexion, déconnexion, mot de passe, liste des appareils. |
| `sync.php` | Envoi et réception des habitudes et des jours cochés, en un aller-retour. |
| `.htaccess` | Rétablit l'en-tête `Authorization` que certains Apache suppriment. |
| `test.php` | Vérifie que l'hébergement est compatible. **À supprimer après vérification.** |
| `data/` | Créé automatiquement. Contient les comptes et les données. |

## Mise en ligne

1. Déposer `trak.html` et le dossier `api/` sur l'hébergement, `api/` **à côté** de `trak.html`.
2. Ouvrir `…/api/test.php` : tout doit être « OK ».
3. Supprimer `test.php`.
4. Ouvrir `trak.html`, cliquer **Se connecter** puis **En créer un**.

Prérequis : PHP 64 bits (7.4 ou plus) et un dossier `api/` accessible en écriture.
Rien d'autre — pas de base, pas d'identifiants à saisir.

Le formulaire ne demande qu'une adresse e-mail et un mot de passe : l'application
trouve `api/` toute seule dès lors qu'elle est servie en HTTP. Pour ouvrir
`trak.html` depuis le disque (`file://`) tout en synchronisant, remplir la constante
`SERVER_URL` en haut du script de la page.

## Le stockage

```
data/
├── .htaccess          refus d'accès web
├── .lock              verrou
├── users.php          adresse e-mail -> identifiant, empreinte du mot de passe
├── sessions.php       jeton -> appareil
├── attempts.php       tentatives de connexion ratées (anti-force brute)
└── user-<id>.php      les habitudes et les jours cochés d'un compte
```

Trois précautions, parce que ces fichiers sont dans l'arborescence web :

- Chaque fichier commence par `<?php exit; ?>` : même servi directement, il ne
  produit rien. C'est la protection qui compte, celle qui ne dépend d'aucun réglage.
- `data/.htaccess` refuse l'accès (Apache). Sur Nginx, ajouter soi-même une règle
  refusant `/api/data/`.
- Les écritures passent par un fichier temporaire puis un `rename`, qui est atomique :
  pas de fichier à moitié écrit si le serveur coupe au mauvais moment.

Un verrou unique (`flock` sur `data/.lock`) sérialise tous les accès. Deux appareils
qui synchronisent à la même seconde attendent l'un l'autre au lieu de s'écraser.

### Pourquoi des fichiers plutôt qu'une base

L'hébergement n'a pas SQLite, et MySQL demande un accès au panneau d'administration
dont l'utilisateur ne dispose pas. À cette échelle — un compte, deux ou trois
appareils, quelques milliers de petites lignes — un fichier JSON par compte relu en
entier à chaque synchro reste largement en dessous du millième de seconde.

Ce qui ferait changer d'avis : plusieurs dizaines de comptes actifs simultanément
(le verrou global deviendrait un goulot), ou des années de données par compte (le
fichier serait relu en entier à chaque fois). L'API et le client n'auraient pas à
bouger : seul `store.php` serait à réécrire.

## Comment la synchronisation fonctionne

Chaque valeur — une habitude, une case cochée d'un jour — porte l'horodatage de
l'appareil qui l'a écrite (`u`, en millisecondes). Le serveur garde la valeur dont
l'horodatage est le plus récent, habitude par habitude et jour par jour : deux
appareils ne s'écrasent donc jamais entièrement, seule la case réellement en conflit
est arbitrée.

Le serveur note aussi, avec sa propre horloge, la date de chaque écriture (`ua`).
C'est ce repère (`now`, renvoyé à chaque synchro et à repasser en `since`) qui sert à
savoir ce qui est nouveau pour un appareil donné — l'horloge du serveur étant la
seule identique pour tout le monde.

Les suppressions laissent une trace (`del: 1`) au lieu de disparaître, sinon un
appareil pas encore au courant réinstallerait l'habitude supprimée à la synchro
suivante.

L'application reste utilisable hors ligne : les modifications s'empilent dans le
navigateur et partent à la reconnexion, au retour sur l'onglet, ou dans la minute.

## Protocole

Tous les appels sont en `POST`, corps et réponse en JSON. Le jeton se transmet dans
l'en-tête `Authorization: Bearer <jeton>` **et** dans le champ `token` du corps —
beaucoup d'hébergements Apache suppriment cet en-tête avant PHP, et c'était le cas
ici. Le `.htaccess` corrige la cause, le champ dans le corps couvre le reste.

```
POST auth.php   {"action":"register","email":"…","password":"…"}   -> {"ok":true,"token":"…"}
POST auth.php   {"action":"login","email":"…","password":"…"}      -> {"ok":true,"token":"…"}
POST auth.php   {"action":"logout"|"me"|"devices"|"revoke_others"}
POST auth.php   {"action":"password","current":"…","password":"…"}
POST sync.php   {"since":0,"habits":[…],"entries":[…]}             -> {"ok":true,"now":…,"habits":[…],"entries":[…]}
```

En cas d'échec : `{"ok":false,"code":"…","error":"message lisible"}` avec un statut
HTTP cohérent. Le code `unauthorized` déconnecte l'appareil côté application.

## Sécurité

- Mots de passe hachés avec `password_hash` (bcrypt), jamais stockés en clair.
- Jetons de session de 256 bits, valables un an, révocables depuis le panneau Compte.
- Connexion limitée à 10 tentatives ratées par quart d'heure et par IP ou par adresse e-mail.
- Le message d'erreur de connexion ne distingue pas « compte inconnu » de « mauvais mot de passe ».
- Changer son mot de passe déconnecte les autres appareils.

**Sans HTTPS, le mot de passe circule en clair sur le réseau.** Un certificat sur le
domaine est le vrai correctif ; en attendant, utilisez un mot de passe qui ne sert
nulle part ailleurs.

Il n'y a pas de récupération de mot de passe par e-mail : un mot de passe perdu se
répare dans `data/users.php`, pas depuis l'application.
