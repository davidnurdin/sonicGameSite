# sonicGameSite

Le site de [Sonic PHP](https://github.com/davidnurdin/sonicGamePhp), en ligne sur https://sonic.mimichat.fr :

- un gros bouton **JOUER**, qui ouvre le jeu (https://davidnurdin.github.io/php-wasm-sdl/). Si on est
  connecté, le jeu reçoit le nom du compte (`?name=`) et l'utilise dans les salons en ligne ;
- une **inscription rapide** : un nom de joueur (3 à 8 lettres ou chiffres, comme dans le jeu), un mot de
  passe, un email facultatif. Pas de mail de confirmation pour le moment ;
- les **visiteurs en direct** : un Sonic par personne sur le site, sur le fond de la page, chacun avec son
  comportement (coureur, sauteur, spin dash, flâneur, suiveur). Le sien est bleu, marqué « TOI », et court
  après la souris (sur téléphone : là où on touche l'écran). Les nuages sont ceux de Green Hill ;
- l'**éditeur de niveau**, en construction : les étapes sont dans
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

Après un test, effacer les comptes créés (`delete from user`) : la base de production doit rester propre.

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
| `RELAY_HEALTH_URL` | `/health` du serveur multijoueur : joueurs et salons en ligne, affichés sur l'accueil |
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
