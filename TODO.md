# Ce qui reste à faire

État au 21/10/2026. Le détail de ce qui marche est dans le [README](README.md). Ce fichier liste ce qui manque,
du plus urgent au moins urgent. Quand une ligne est faite, l'enlever d'ici et la décrire dans le README.

## Fait (pour mémoire)

- Éditeurs de niveaux, de tiles (collisions dessinées, blocs animés), de sprites, de sons et de musiques ;
  forks, travail à plusieurs, historique, corbeille de 30 jours (purgée chaque jour).
- Niveaux du site dans le jeu : bouton Jouer / Tester, menu NIVEAUX DU SITE (tris, notes), en match contre
  l'ordinateur et en ligne, replays.
- Catalogue `/niveaux` (mieux notés, nouveautés, plus joués, coups de cœur, favoris, recherche, pages), notes de
  1 à 5 étoiles, favoris, parties comptées.
- Meilleurs temps par niveau (le classement repart de zéro quand le niveau change), fantôme du record dans le jeu.
- Cycles de couleurs des zones aussi pour les forks modifiés, éclairs de Sky Base, ligne d'eau dans tout niveau.
- Serveur MCP et API REST pour les IA, API publique en lecture (Swagger).

## 1. Sécurité (urgent)

- [ ] Finir le nettoyage de l'incident de sécurité GitHub du 21/10 (le détail n'est pas dans ce dépôt public).
- [ ] Sauvegarde automatique de la base, chaque nuit, avec une copie hors du VPS (aujourd'hui : des dumps faits à
  la main, rangés sur le VPS lui-même).

## 2. Comptes et RGPD

- [ ] « Mot de passe oublié » : il faut un service d'envoi d'e-mails (aucun aujourd'hui).
- [ ] Vérifier l'adresse e-mail à l'inscription.
- [ ] Supprimer son compte soi-même (obligatoire avec le RGPD), exporter ses données.

## 3. Modération (dès qu'il y aura du monde)

- [ ] Signaler un niveau, une création ou un joueur ; un rôle `ROLE_ADMIN` et une page pour masquer.
- [ ] Filtre de mots sur les noms de niveaux, de comptes et de créations (affichés dans le jeu).
- [ ] Votes : rien n'empêche plusieurs comptes de gonfler une note.
- [ ] Parties : le compteur prend aussi les essais de l'auteur (« Tester ») et ne voit qu'une partie pour une même
  connexion partagée.
- [ ] Meilleurs temps : ils viennent du jeu sans contrôle ; vérifier un replay (le parcours tient-il dans le
  niveau ?) avant de l'accepter comme record.

## 4. Communauté

- [ ] Commentaires sur un niveau (ou un livre d'or).
- [ ] Prévenir l'auteur quand son niveau est noté, mis en favori, forké ou que son record tombe.
- [ ] Profil : note moyenne de ses niveaux, parties au total, records détenus, favoris visibles.
- [ ] Dans le jeu : chercher un niveau, le mettre en favori depuis la liste (aujourd'hui : après l'avoir joué),
  voir le classement d'un niveau.
- [ ] Publier seulement un niveau terminé au moins une fois par son auteur (comme Mario Maker) ; le robot
  `test_level` du MCP peut aider.
- [ ] `sitemap.xml` : y mettre `/niveaux`, et dépasser 1000 niveaux (`publicPage`).

## 5. Éditeurs

- [ ] Éditer sur téléphone ou tablette (aujourd'hui : ordinateur seulement, la vue sur téléphone).
- [ ] Enregistrement automatique des brouillons (aujourd'hui : une alerte avant de quitter seulement).
- [ ] La ligne d'eau monte et descend comme celle du Labyrinth ; pas encore d'eau à hauteur fixe ni de réglage de
  son mouvement.

## 6. Technique

- [ ] Tests automatiques du site et du jeu (aucun aujourd'hui) et un workflow qui les lance (seul le déploiement
  de la page sur GitHub Pages en a un).
- [ ] Le jeu natif n'a pas de compte : `SONIC_STATS_TOKEN` se donne à la main ; une connexion depuis le jeu.
- [ ] TODO du code du jeu : `Window.php:42` (en web), la vsync (`Game.php`), `SDL_SetRenderTarget` en web,
  la caméra de fin du niveau 19 (`Camera.php`), Sonic qui « tremble » (`Camera.php`), la pause quand l'onglet perd
  le focus (`index.html` de la page).

## Mémo : un niveau du jeu (`assets/levels/` de sonicGamePhp)

| Fichier | Contenu |
|---|---|
| `levelN.bin` | la carte : `FW × FH` octets, un numéro de bloc de 32×32 px par case, ligne par ligne |
| `levelN.meta` | départ (`SX`, `SY`), taille (`FW`, `FH`), collisions (`SP`), cycles de couleurs (`CC`, `CS`), `TL`… |
| `levelN.objects.php` | les objets `{type, x, y}` (types de la ROM) |
| `levelN.solidity` | la forme de collision de chaque bloc (bit 7 : devant les sprites) |
| `tileset/levels/tilesetT*.png` | les images des blocs, avec `-cycleK`, `-lightL-cycleK`, `-water`, `-flash` et `-fg` |

Un niveau du site n'utilise jamais `eval` ni `require` : il arrive en JSON (`/niveau/{id}/jeu.json`) et le jeu le
lit avec `Level::loadCustom`.
