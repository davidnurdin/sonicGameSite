# Éditeur de niveau : les étapes

But : sur https://sonic.mimichat.fr/editeur, un joueur connecté dessine une zone, la teste dans le jeu, la
publie. Les autres y jouent, seuls ou en ligne. Aujourd'hui, la page dit « en construction ».

## Ce qu'est un niveau aujourd'hui (jeu, `assets/levels/`)

| Fichier | Contenu |
|---|---|
| `levelN.bin` | la carte : `FW × FH` octets, un numéro de **bloc** de 32×32 px par case, ligne par ligne |
| `levelN.meta` | tableau PHP lu par `eval` : départ de Sonic (`SX`, `SY` en blocs), taille (`FW`, `FH`), jeu de collisions (`SP`), limites de caméra et autres champs de la ROM |
| `levelN.objects.php` | tableau PHP lu par `require` : les objets `{type, x, y}` (types de la ROM : moniteurs, badniks, plateformes, panneau…) |
| `levelN.solidity` | la forme de collision de chaque bloc du jeu de blocs (bit 7 : bloc devant les sprites) |
| `tileset/levels/tilesetT*.png` | les images des blocs (bandes de 32 px de haut), avec les variantes de cycle de palette (`-cycleC`) et de premier plan (`-fg`) |

Points importants :
- **les anneaux sont des blocs** : les blocs `$79`-`$7B` avec les bits 0 et 1 (anneau gauche / droit). On
  peint donc les anneaux comme des blocs ;
- **ressorts, pics, tapis roulants, ponts, eau** sont des *comportements de blocs* (table de la ROM,
  `blockBehaviour.data.php`). Ils viennent avec le bloc, rien à placer à part ;
- le **jeu de blocs** dépend de la zone (`LevelManager::convertLevelToTileSet`) : un niveau perso choisit
  une zone de base (Green Hill, Bridge, Jungle…) et n'utilise que ses blocs.

## Étape 1 : un format de niveau perso, sûr

- [ ] Définir un format JSON versionné :
  `{ "v": 1, "zone": 1, "w": 128, "h": 16, "blocks": "<base64 de w×h octets>", "start": [x, y], "objects": [{ "type": 16, "x": 18, "y": 11 }], "title": "…" }`.
- [ ] Dans le jeu : charger un niveau depuis ce JSON (`Level::fromCustom()`), en reprenant le jeu de
  blocs, les collisions et le reste de la zone de base. **Jamais `eval` ni `require` sur un fichier de
  joueur** : aujourd'hui `.meta` et `.objects.php` sont exécutés, ce qui va pour les fichiers du jeu mais
  serait une faille pour un niveau envoyé par quelqu'un.
- [ ] Valider partout, à l'enregistrement (site) comme au chargement (jeu) :
  - taille maximale (par exemple 512 × 64 blocs) ;
  - numéros de blocs existants dans la zone ;
  - types d'objets autorisés (pas de boss, pas de téléporteurs vers les niveaux du jeu) ;
  - un départ, un panneau d'arrivée (mode course).
- [ ] Un convertisseur pour repartir d'un acte du jeu (`levelN.*` vers JSON) : point de départ de
  l'éditeur, et test du chargeur (le niveau converti doit se jouer comme l'original).

## Étape 2 : le jeu lit un niveau perso

- [ ] `index.html` (php-wasm-sdl) : `?level=<id>` → la page télécharge
  `https://sonic.mimichat.fr/api/niveaux/<id>.json` et le passe à PHP (fichier dans le système de fichiers
  wasm, ou constante en base64 comme `SONIC_PLAYER_NAME`).
- [ ] `Game` : lancer directement ce niveau en solo (sans carte du monde ni enchaînement d'actes), avec un
  retour au site à la fin.
- [ ] Meilleurs temps et fantômes par niveau perso (`BestTimes` et `GhostRecorder`, clé = id du niveau).
- [ ] Version desktop : `./start.sh --level fichier.json`.

## Étape 3 : le site stocke les niveaux

- [ ] Entité `Level` :
  - `author` (User), `title`, `zone`, `data` (JSON validé) ;
  - `status` (brouillon / publié / masqué) ;
  - `createdAt`, `updatedAt`, `plays`, `likes`.
- [ ] API JSON :
  - `GET /api/niveaux/{id}.json` (publié, ou brouillon de l'auteur) ;
  - `POST /api/niveaux` et `PUT /api/niveaux/{id}` (auteur connecté, CSRF, taille limitée, limite de débit) ;
  - `GET /api/niveaux` (liste).
- [ ] Pages : « Mes niveaux », « Niveaux des joueurs » (récents, populaires), fiche d'un niveau avec un
  bouton JOUER (`/jouer?level=<id>`).
- [ ] Migrations Doctrine ; sauvegarde régulière de la base MariaDB du VPS (`mysqldump`, voir le README).

## Étape 4 : l'éditeur (dans le navigateur, sur le site)

- [ ] Exporter pour le web, depuis le jeu, pour chaque zone :
  - l'atlas des blocs (PNG) ;
  - la forme de collision de chaque bloc (pour l'afficher) ;
  - la liste des objets plaçables avec une vignette (sprites tirés des planches du jeu).
- [ ] Une page `/editeur/{id}` :
  - un canvas avec grille, déplacement et zoom ;
  - la palette des blocs de la zone, et celle des objets ;
  - les outils : pinceau, gomme, remplissage, rectangle, pipette, sélection avec copier-coller ;
  - annuler / rétablir ;
  - l'affichage des collisions et des comportements (ressorts, pics, eau) en surimpression ;
  - le départ de Sonic et le panneau d'arrivée à placer ;
  - les anneaux comme des blocs, avec un outil dédié qui choisit le bon bloc `$79`-`$7B`.
- [ ] Tactile : déplacement et zoom à deux doigts, palette en tiroir, gros boutons (le site est aussi
  joué sur téléphone).
- [ ] Enregistrement automatique du brouillon (`localStorage`, puis API), avec un indicateur « enregistré ».
- [ ] JavaScript sans framework lourd (AssetMapper de Symfony, modules ES) ; le rendu de la carte réutilise
  les mêmes règles que `Level::drawnBlock()`.

## Étape 5 : tester tout de suite

- [ ] Bouton TESTER : enregistre le brouillon puis ouvre le jeu sur `?level=<id>&test=1`, dans un nouvel
  onglet ou une fenêtre intégrée.
- [ ] Le départ peut être placé n'importe où pour tester un passage précis.
- [ ] Le jeu remonte les problèmes au site : Sonic coincé, chute hors carte, arrivée impossible
  (au moins un test réussi exigé avant de publier, comme dans Mario Maker).

## Étape 6 : publier et partager

- [ ] Publication : titre (filtre de mots), vérification du format, « niveau terminé par l'auteur ».
- [ ] Classement : nombre de parties, « j'aime », meilleur temps et son auteur.
- [ ] Signalement d'un niveau et modération (rôle `ROLE_ADMIN`, page d'administration simple).
- [ ] Lien de partage : `https://sonic.mimichat.fr/niveau/<id>`.

## Étape 7 : niveaux perso en ligne

- [ ] Dans le salon EN LIGNE, l'hôte choisit un niveau publié (ZONE → « NIVEAU DES JOUEURS »).
- [ ] Le START de la partie porte l'id du niveau ; chaque joueur le télécharge (avec un cache) avant le
  compte à rebours. L'hôte attend que tous soient prêts.
- [ ] `Versus` en tient compte :
  - points d'apparition tirés des anneaux, comme aujourd'hui ;
  - arrivée = panneau ;
  - bases du drapeau aux extrémités.

  Vérifier que le niveau convient au mode choisi (assez large pour le drapeau…).
- [ ] Monter `Online::VERSION` quand les messages changent.

## Plus tard

- [ ] Eau (ligne d'eau de la zone Labyrinth), cycles de palette, musique au choix.
- [ ] Défilement automatique, niveaux verticaux (Jungle 2), téléporteurs internes.
- [ ] Blocs personnalisés ? Probablement non : les images viennent du jeu d'origine (droits de SEGA), et il
  faudrait aussi éditer les collisions.
