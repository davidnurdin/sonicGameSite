<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Info;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Tag;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The documentation of the public API (Swagger UI, ReDoc, Scalar, /api/public/docs.jsonopenapi): what it is, how
 * to read it (formats, pages, errors, limits), each part of it, and what every list answers.
 */
#[AsDecorator('api_platform.openapi.factory')]
final class PublicApiDocs implements OpenApiFactoryInterface
{
    /** The parts of the API, in the order of the documentation: name => [title, description]. */
    private const TAGS = [
        'Level' => ['Niveaux', 'Les niveaux publics faits dans l\'éditeur du site : leurs réglages, leurs liens (page, jouer, miniature), '
            . 'leur contenu (`/levels/{id}/map`) et leur historique.'],
        'LevelMap' => ['Contenu d\'un niveau', 'La grille de blocs d\'un niveau, ses objets et le départ de Sonic : de quoi le dessiner '
            . 'ou l\'analyser (avec l\'image et les collisions de ses tiles).'],
        'Tileset' => ['Tiles', 'Les blocs du décor : les 7 zones du jeu, les tiles publiques des joueurs, et celles des niveaux publics.'],
        'TilesetBlocks' => ['Blocs de tiles', 'Pour chaque bloc : sa place dans l\'image, sa forme de collision, ses côtés solides, '
            . 's\'il passe devant Sonic, ce qu\'il fait (ressort, pics…).'],
        'SpriteSet' => ['Sprites', 'Les planches du jeu redessinées par les joueurs (Sonic, les badniks, les objets…).'],
        'Sound' => ['Musiques et sons', 'Les musiques et bruitages publics : la partition, la durée, et le son en WAV.'],
        'Player' => ['Joueurs', 'Les joueurs inscrits, tels que leur profil public les montre.'],
        'Replay' => ['Parties', 'Les parties jouées depuis le site, telles que la page Historique les montre, à revoir dans le jeu.'],
        'HistoryEntry' => ['Historique', 'L\'historique de chaque création : qui a fait quoi et quand.'],
    ];

    public function __construct(private OpenApiFactoryInterface $decorated, private RequestStack $requests)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $site = $this->requests->getCurrentRequest()?->getSchemeAndHttpHost() ?? 'https://sonic.mimichat.fr';
        $info = $openApi->getInfo();
        $openApi = $openApi
            ->withInfo(new Info($info->getTitle(), $info->getVersion(), self::about($site), null, null, null,
                'Tout ce qui est public sur Sonic PHP, en lecture seule : niveaux, tiles, sprites, sons, joueurs, parties.'))
            ->withExternalDocs(['description' => 'La page du site qui présente l\'API', 'url' => $site . '/developpeurs'])
            ->withTags(array_map(fn(string $name) => new Tag($name, self::TAGS[$name][1], null, self::TAGS[$name][0]), array_keys(self::TAGS)));

        // every list: how its pages work
        foreach ($openApi->getPaths()->getPaths() as $path => $item) {
            $get = $item->getGet();
            if (!$get instanceof Operation) continue;
            $list = str_contains((string) $get->getOperationId(), '_get_collection') || array_filter($get->getParameters() ?? [], fn($p) => $p->getName() === 'page');
            $description = $get->getSummary() . "\n\n" . ($list
                ? 'Une liste paginée : `page` (à partir de 1) et `itemsPerPage` (30 par défaut, 100 au plus). En JSON-LD, la réponse donne '
                    . 'le total (`totalItems`) et les liens des pages (`view.next`…) ; en JSON simple, seulement les éléments.'
                : 'Un élément : 404 s\'il n\'existe pas ou s\'il n\'est pas public.');
            $openApi->getPaths()->addPath($path, $item->withGet($get->withDescription($description)));
        }

        return $openApi;
    }

    private static function about(string $site): string
    {
        return <<<MD
            L'API publique de **Sonic PHP** : tout ce qu'un visiteur voit sur le site, en lecture seule, pour faire autre chose avec
            (une galerie de niveaux, un bot, des statistiques, un éditeur, un lecteur de musiques…).

            - **Sans compte et sans clé.** Elle ne donne que ce qui est public : les créations publiques (et les tiles, sprites
              et sons dont un niveau public se sert), les zones du jeu, les profils publics des joueurs, l'historique des parties.
              Jamais une création privée ou dans une corbeille, ni une adresse e-mail, ni des statistiques privées.
            - **Formats** : JSON-LD/Hydra (`Accept: application/ld+json`, par défaut : avec les liens et les pages) ou JSON simple
              (`Accept: application/json`). Les liens entre ressources sont des adresses de l'API (IRI), comme `/api/public/players/SONIC`.
            - **Pages** : `?page=2&itemsPerPage=50` (30 par défaut, 100 au plus).
            - **Erreurs** : au format *Problem Details* (RFC 9457) : 404 (inconnu ou pas public), 422 (paramètre invalide, `detail` dit
              lequel), 429 (trop de requêtes).
            - **Limite** par adresse IP : 600 requêtes d'un coup au plus, puis une par seconde (sinon 429, avec `Retry-After` en secondes).
            - **CORS** ouvert : utilisable depuis n'importe quelle page web (`fetch`), sans cookie.
            - **Cache** : les réponses peuvent être gardées 60 secondes.
            - **Les images et les sons** (miniatures, tiles, planches de sprites, WAV) sont des adresses du site dans `links`.

            Exemple : `curl -H "Accept: application/json" $site/api/public/levels?order=created`

            Pour **créer** des niveaux avec une IA (clé API, écriture), voir le serveur MCP : $site/ia
            MD;
    }
}
