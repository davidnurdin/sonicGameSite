# sonicGameSite

Le site de [Sonic PHP](https://github.com/davidnurdin/sonicGamePhp), en ligne sur https://sonic.mimichat.fr :

- un gros bouton **JOUER**, qui ouvre le jeu (https://davidnurdin.github.io/php-wasm-sdl/). Si on est
  connecté, le jeu reçoit le nom du compte (`?name=`) et l'utilise dans les salons en ligne ;
- une **inscription rapide** : un nom de joueur (3 à 8 lettres ou chiffres, comme dans le jeu), un mot de
  passe, un email facultatif. Pas de mail de confirmation pour le moment. L'accueil montre le nombre de
  membres ;
- **connecté, l'accueil devient le hall des salons** : les salons publics du jeu (rafraîchis toutes les
  5 secondes), créer un salon public ou privé (mode et zone, niveaux bonus compris), rejoindre un salon par
  son code ;
- la **page d'un salon** (`/salon/CODE`) : qui est dans le jeu, qui est sur le site, la discussion (la même
  que dans les menus du jeu), le mode et la zone. **JOUER** ouvre le jeu directement dans le salon ; après le
  match, le jeu revient sur cette page. **Partager** passe par la feuille de partage du téléphone, sinon le
  lien est copié. Les Sonic du fond sont ceux du salon ;
- l'onglet **Statistiques** : temps de jeu (seul, en ligne, contre l'ordinateur), sauts, anneaux, ennemis,
  ressorts, vies perdues, actes, émeraudes, matchs et victoires, et le classement de ceux qui jouent le plus ;
- **invité sans compte** : JOUER, sur la page d'un salon, mène à l'inscription (ou à la connexion), puis
  revient directement dans le salon (`?next=`) ;
- la liste des **joueurs inscrits** (`/joueurs`, 30 par page) ;
- le nombre de **joueurs dans le jeu** dans l'en-tête de chaque page : les jeux ouverts (la page du jeu le
  dit au site toutes les 45 secondes, `POST /api/jeu`, gardé 2 minutes en cache), au moins les joueurs des
  salons du relais ;
- le **référencement** : description, Open Graph et Twitter sur chaque page, avec l'image
  `public/img/og-fr.png` / `og-en.png` (refaite par `tools/og-image.py tools/og-background.png <jeu>`) ;
  `robots.txt`, `/sitemap.xml`. Un lien de salon partagé porte `?lang=` : l'aperçu est dans la langue de
  celui qui partage ;
- le site en **français ou en anglais** : la langue du navigateur, ou FR / EN dans l'en-tête (cookie
  `lang`). Le jeu suit la langue du site (`?lang=`) ;
- les **visiteurs en direct** : un Sonic par personne sur le site, sur le fond de la page, chacun avec son
  comportement (coureur, sauteur, spin dash, flâneur, suiveur). Le sien est bleu, marqué « TOI », et court
  après la souris (sur téléphone : là où on touche l'écran). Les nuages sont ceux de Green Hill ;
- les **éditeurs** : niveaux, tiles (dessin et collisions), sprites, sons et musiques, publics ou privés. Les niveaux se
  jouent dans le jeu (bouton Jouer, ou menu NIVEAUX DU SITE du jeu). Ce qui reste à faire est dans
  [`TODO-editeur-de-niveau.md`](TODO-editeur-de-niveau.md).

Symfony 8.1, PHP 8.4, MariaDB 11.4, FrankenPHP (avec son hub Mercure). Pas de framework JavaScript : un seul
script, `public/js/sonics.js`.

## Comment marchent les visiteurs en direct

Le hub Mercure de FrankenPHP (`docker/Caddyfile`) a l'option `subscriptions` : il annonce lui-même chaque
abonnement qui s'ouvre ou se ferme.

1. La page appelle `/presence` (`PresenceController`). Le site lui donne un cookie signé, valable une heure,
   qui l'autorise à s'abonner au sujet `urn:sonicphp:presence`, et à voir qui d'autre y est abonné.
2. La page s'abonne à ce sujet. Elle reçoit aussi les arrivées et les départs, et demande la liste de
   départ à l'API du hub.
3. Une personne = une page ouverte. Chaque abonnement porte le nom du compte (ou rien), affiché au-dessus du
   Sonic.

Rien n'est publié et le hub ne garde aucun historique (`transport local` : pas de base sur disque).

## Comment marchent les salons

La page d'un salon (`public/js/room.js`) se connecte au relais du jeu (`RELAY_WS_URL`) comme **spectateur**
(voir le guide de php-wasm-network) : elle voit les joueurs, la discussion et le match, sans jouer. Elle
parle le protocole du jeu (`Online.php` de sonicGamePhp) : HELLO pour dire son nom, CHAT pour la discussion,
et SITE (type 8, entre pages du site seulement) pour le mode et la zone choisis tant que personne n'est
dans le jeu. Un salon créé ici l'est par la page (`create` avec `watch`) ; le premier joueur qui arrive
du jeu en devient l'hôte. Le relais garde un salon vide 90 secondes : le temps que le jeu se charge.

JOUER ouvre le jeu avec `?room=CODE&mode=…&level=…&back=<page du salon>` en plus du nom, de la langue et du
jeton des statistiques. La liste des salons publics vient de `GET /health?rooms` du relais
(`OnlineStats`, `/salons.json`).

## Comment marchent les statistiques

Le jeu compte dans `var/stats.json` (dans le navigateur : IndexedDB). JOUER donne à la page du jeu un jeton
`st` (l'id du compte signé avec `APP_SECRET`, `StatsToken`) et l'adresse du site (`site`). La page garde le
jeton, puis envoie chaque minute, et à la fermeture, ce qui a été gagné depuis le dernier envoi :
`POST /api/stats` en `text/plain` (pas de requête CORS préalable ; `sendBeacon` à la fermeture). Le site
plafonne chaque envoi et en limite le nombre (240 par heure et par compte).

## Comment marchent les éditeurs

- `/editeur` : mes niveaux, mes tiles, mes sprites, les niveaux publics, les tiles (les zones du jeu et celles
  des joueurs) et les sprites publics.
  Un niveau public se regarde sans compte (`/niveau/{id}`, image `/niveau/{id}.png` pour les liens). L'édition
  demande un ordinateur : sur un téléphone, la page le dit et ne montre que la vue.
- Un niveau a le format du jeu : largeur × hauteur blocs de 32 px, un octet par bloc (un bloc de son jeu de
  tiles), le départ de Sonic et les objets (`[type, x, y]` en blocs, types de `EditorData::OBJECTS`, ceux des
  `level<N>.objects.php` du jeu), sa musique et, s'il en a, son jeu de sprites. Le jeu le télécharge par
  `/niveau/{id}/jeu.json` (un niveau privé avec la clé `lt` de `LevelToken`) : bouton Jouer / Tester, ou son
  menu NIVEAUX DU SITE, qui lit `POST /api/niveaux` (les niveaux publics, et les siens avec le compte du joueur).
- Dans l'éditeur de niveau, les objets sont dessinés avec leur sprite du jeu : `public/img/objects.png` et
  `public/data/object-icons.json`, refaits par `DISPLAY=:77 python3 tools/object-icons.py <jeu>` (le jeu natif
  dans un écran X, en mode `SONIC_OBJECT_ICONS`).
- Un jeu de tiles : jusqu'à 256 blocs, 16 par ligne dans un PNG, et un octet de collision par bloc (comme
  `level<N>.solidity` : les 6 bits du bas sont une des 47 formes de `public/data/solidity-shapes.json`, le bit 7
  dessine le bloc devant Sonic). On peut copier ceux des autres s'ils sont publics. Un jeu utilisé par un niveau
  ne peut pas être supprimé, et seul son dernier bloc peut être enlevé (les niveaux visent les blocs par leur
  numéro). La zone rouge (collision) d'un bloc peut aussi être dessinée au pixel, avec ses côtés (sol, murs,
  plafond) : `tileset.masks`, appliqué par le jeu (`Level::applyMask`).
- Un jeu de sprites (`SpriteSet`, éditeur `/sprites/{id}/editer`) : des planches du jeu redessinées (Sonic,
  badniks, objets, boss…, `SpriteSet::SHEETS`), chacune un PNG de la taille de celle du jeu, transparent là où
  le jeu a sa couleur de transparence. Un niveau qui le choisit se joue avec : le jeu met ces planches à la
  place des siennes pendant le niveau (`LevelManager::loadCustom`). Les planches de départ sont
  `public/img/sheets/`, refaites par `python3 tools/sprite-sheets.py <jeu>`.
- Les zones du jeu (blocs, collisions, l'acte 1 de chacune comme modèle) sont dans `data/game/`, refaites par
  `python3 tools/game-data.py <jeu>`. `bin/console app:zones` les met en base (lancé au démarrage du conteneur).
- Un son (`Sound`, `/son/{id}/editer`) : une musique (un piano roll sur les 4 voix de la puce de la Master
  System : 2 carrés, une basse, le bruit pour la batterie ; une voix joue une note à la fois) ou un bruitage
  (les réglages d'un générateur : onde, hauteur qui glisse, enveloppe, vibrato, répétitions). Le format est
  décrit dans `SoundData`. `public/js/sound-engine.js` les joue (Web Audio) et en fait des WAV. Ils ne servent
  pas encore dans le jeu.
- Les éditeurs (`public/js/level-view.js`, `editor-level.js`, `editor-tiles.js`, `editor-sprites.js`,
  `editor-music.js`, `editor-sfx.js`) enregistrent en JSON avec le
  jeton CSRF `editor` (en-tête `X-CSRF-Token`) ; `EditorData` vérifie tout ce qu'ils envoient.

## Développer

```bash
composer install
# une MariaDB de développement
docker run -d --name sonic-mariadb-dev -p 127.0.0.1:13306:3306 \
  -e MARIADB_DATABASE=sonic -e MARIADB_USER=sonic -e MARIADB_PASSWORD=devpass \
  -e MARIADB_RANDOM_ROOT_PASSWORD=1 mariadb:11.4
cat > .env.local <<EOF
APP_SECRET=$(openssl rand -hex 16)
DATABASE_URL="mysql://sonic:devpass@127.0.0.1:13306/sonic?serverVersion=11.4.13-MariaDB&charset=utf8mb4"
EOF
php bin/console doctrine:migrations:migrate -n
php -S 127.0.0.1:8000 -t public          # le site, sans les visiteurs en direct (pas de hub Mercure)
```

Pour tout avoir, hub Mercure compris, lancer l'image de production en local :

```bash
docker build -t sonic-site:dev .
docker run --rm --network host \
  -e APP_SECRET=$(openssl rand -hex 16) \
  -e "DATABASE_URL=mysql://sonic:devpass@127.0.0.1:13306/sonic?serverVersion=11.4.13-MariaDB&charset=utf8mb4" \
  -e MERCURE_JWT_SECRET=$(openssl rand -base64 32) \
  -e MERCURE_PUBLIC_URL=http://127.0.0.1:8080/.well-known/mercure -e MERCURE_COOKIE_NAME=mercure_access_token \
  sonic-site:dev                         # http://127.0.0.1:8080/
```

Pour tester les salons en local : le relais (`php server/bin/server.php` dans php-wasm-network) et la page du
jeu (`./serve.sh root 8080` dans php-wasm-sdl), puis dans `.env.local` :

```bash
RELAY_HEALTH_URL=http://127.0.0.1:8090/health
RELAY_WS_URL=ws://127.0.0.1:8090/ws
GAME_URL=http://127.0.0.1:8080/?net=ws://127.0.0.1:8090/ws
```

Après un test, effacer les comptes créés (`delete from user`, les statistiques partent avec) : la base de
production doit rester propre.

La limite d'inscriptions (10 par heure et par adresse) se remet à zéro avec
`php bin/console cache:pool:clear cache.rate_limiter`.

Les images `public/img/` viennent du jeu. Elles sont refaites par les scripts de `tools/` (le chemin du jeu
est donné en argument). Après une modification d'un fichier de `public/`, monter `framework.assets.version`
(`config/packages/framework.yaml`) : ces fichiers sont gardés une heure en cache.

## Variables

| Variable | Rôle |
|---|---|
| `APP_SECRET` | secret de Symfony (sessions, « se souvenir de moi ») |
| `DATABASE_URL` | la base MariaDB |
| `GAME_URL` | l'adresse du jeu, ouverte par JOUER |
| `RELAY_HEALTH_URL` | `/health` du serveur multijoueur : joueurs et salons publics, affichés sur l'accueil |
| `RELAY_WS_URL` | le WebSocket du serveur multijoueur, vu du navigateur (pages des salons) |
| `TRUSTED_PROXIES` | les proxys dont on croit `X-Forwarded-For` (en production : `PRIVATE_SUBNETS`, le Caddy de devant) |
| `MERCURE_PUBLIC_URL` | l'adresse du hub vue du navigateur (`https://<domaine>/.well-known/mercure`) |
| `MERCURE_JWT_SECRET`, `MERCURE_JWT_ISSUER` | la signature des cookies du hub (les mêmes pour le site et le hub) |
| `MERCURE_COOKIE_NAME` | `__Secure-mercure_access_token` en HTTPS, sans préfixe en HTTP local |

## Production

Le site tourne sur le VPS avec le serveur multijoueur, dans `/mimichatPROD/sonicGameServerPhp`. Les
fichiers de déploiement sont dans
[php-wasm-network/server/deploy-traefik](https://github.com/davidnurdin/php-wasm-network/tree/main/server/deploy-traefik) :

- conteneurs `site` (ce dépôt, cloné dans `sonicGameSite/`) et `db` (MariaDB, sur un réseau où seul le site
  la voit) ;
- le Caddy de devant fait le HTTPS. Il envoie `/ws` et `/health` au relais, et tout le reste au site ;
- l'adresse réelle des visiteurs vient du proxy Cloudflare (`CF-Connecting-IP`, lue par Caddy, puis passée
  au site dans `X-Forwarded-For`). Elle sert à la limite d'inscriptions et à celle des essais de
  connexion. Sans Cloudflare, tout le monde aurait l'adresse de Traefik : il passe le TLS tel quel ;
- les secrets (`SITE_APP_SECRET`, `SITE_MERCURE_SECRET`, `SITE_DB_PASSWORD`, `SITE_DB_ROOT_PASSWORD`) sont
  dans le `.env` du serveur, jamais dans Git.

```bash
cd /mimichatPROD/sonicGameServerPhp
./update-site.sh                         # nouvelle version du site (migrations jouées au démarrage)
docker compose logs -f site
# sauvegarde de la base
docker compose exec -T db sh -c 'mariadb-dump -usonic -p"$MARIADB_PASSWORD" sonic' | gzip > backup/sonic-$(date +%F).sql.gz
```
