# Trak

Suivi d'habitudes quotidien : un compte, plusieurs appareils, les mêmes données
partout. Fonctionne hors ligne et se resynchronise au retour du réseau.

En ligne : https://dunandchatellet.fr/Trak/trak.html

## Ce que ça fait

- Cocher ses habitudes du jour, suivre ses séries et son pourcentage de réussite
- Vues Aujourd'hui / Semaine / Année, et gestion des habitudes
- Comptes utilisateurs, synchronisation entre appareils, fonctionnement hors ligne

## Comment c'est fait

Front : une seule page HTML (`trak.html`) et `support.js`, sans framework.
Back : PHP sans base de données — les données vivent dans des fichiers JSON sous
`api/data/`, avec un verrou global et des écritures atomiques.
L'architecture est détaillée dans [`api/LISEZMOI.md`](api/LISEZMOI.md).

| Fichier | Rôle |
| --- | --- |
| `trak.html` | L'application |
| `trak-demo.html` | Démo sans compte |
| `support.js` | Fonctions d'appui du front |
| `api/` | Comptes, stockage, synchronisation |

## Honnêteté sur la fabrication

**Ce projet a été codé avec l'aide d'une IA.** Je ne maîtrise ni le PHP ni le
JavaScript à ce niveau-là. Ce qui vient de moi : l'idée et le cahier des charges,
les choix de fonctionnement et de design, les tests, les corrections et la mise en
ligne — et je continue de le faire évoluer. Je préfère le dire que le laisser
croire.

## Installation

Voir [`api/LISEZMOI.md`](api/LISEZMOI.md). En résumé : déposer `trak.html` et le
dossier `api/` côte à côte sur un hébergement PHP 7.4+, ouvrir `api/test.php` pour
vérifier, puis le supprimer.

Le dossier `api/data/` n'est pas versionné : il contient les comptes et les
données réelles.

---

Pierre Dunand-Chatellet
