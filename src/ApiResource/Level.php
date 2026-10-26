<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\PublicApi\LevelProvider;
use Symfony\Component\Validator\Constraints as Assert;

/** A public level of the site's editor (public API, read only). */
#[ApiResource(
    shortName: 'Level',
    description: 'Un niveau public, fait dans l\'éditeur du site et jouable dans le jeu. Seuls les niveaux publics (et pas dans '
        . 'une corbeille) sont donnés. Ses blocs et ses objets sont dans `/levels/{id}/map`.',
    operations: [
        new GetCollection(
            uriTemplate: '/levels',
            openapi: new Operation(summary: 'Les niveaux publics, les derniers modifiés d\'abord (ou un autre ordre avec `order`).'),
            parameters: [
                'author' => new QueryParameter(description: 'Seulement ceux de ce joueur (son nom, par exemple SONIC).',
                    schema: ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,15}$'], constraints: [new Assert\Regex(pattern: '/^[A-Za-z0-9]{1,15}$/')]),
                'tileset' => new QueryParameter(description: 'Seulement ceux faits avec ces tiles (leur id).',
                    schema: ['type' => 'integer', 'minimum' => 1], constraints: [new Assert\Positive()]),
                'name' => new QueryParameter(description: 'Seulement ceux dont le nom contient ce texte.',
                    schema: ['type' => 'string', 'maxLength' => 40], constraints: [new Assert\Length(max: 40)]),
                'order' => new QueryParameter(description: '`updated` (défaut), `created` (les plus récents d\'abord), `name`, `top` (les mieux notés, '
                    . 'par `score`), `plays` (les plus joués) ou `favourites` (les plus souvent en favoris).',
                    schema: ['type' => 'string', 'enum' => ['updated', 'created', 'name', 'top', 'plays', 'favourites'], 'default' => 'updated'],
                    constraints: [new Assert\Choice(choices: ['updated', 'created', 'name', 'top', 'plays', 'favourites'])]),
            ],
        ),
        new Get(uriTemplate: '/levels/{id}', openapi: new Operation(summary: 'Un niveau public.')),
    ],
    provider: LevelProvider::class,
)]
final class Level
{
    #[ApiProperty(identifier: true, description: 'Son numéro, celui de l\'adresse du site (/niveau/{id}).', example: 12)]
    public int $id;

    #[ApiProperty(description: 'Son nom (40 caractères au plus).', example: 'BANQUISE 1')]
    public string $name;

    #[ApiProperty(description: 'Le joueur qui l\'a créé.')]
    public Player $author;

    #[ApiProperty(description: 'Le nom de ce joueur.', example: 'SONIC')]
    public string $authorName;

    #[ApiProperty(description: 'Les tiles (blocs du décor) avec lesquelles il est fait.')]
    public Tileset $tileset;

    #[ApiProperty(description: 'Les sprites avec lesquels il se joue (null : ceux du jeu). Ceux d\'un niveau public sont toujours visibles.')]
    public ?SpriteSet $sprites = null;

    #[ApiProperty(description: 'Sa musique faite sur le site, si elle est publique (null : la musique du jeu `gameMusic`, ou une musique privée).')]
    public ?Sound $music = null;

    #[ApiProperty(description: 'La musique du jeu qu\'il joue s\'il n\'a pas la sienne (son numéro dans le jeu).', example: 3)]
    public int $gameMusic;

    #[ApiProperty(description: 'Le nom de cette musique du jeu.', example: 'Labyrinth')]
    public string $gameMusicName;

    /** @var list<array{gameSound: int, gameSoundName: string, sound: string}> */
    #[ApiProperty(description: 'Les bruitages du jeu qu\'il remplace par des sons publics du site.', schema: [
        'type' => 'array',
        'items' => ['type' => 'object', 'properties' => [
            'gameSound' => ['type' => 'integer', 'description' => 'Le numéro du son du jeu remplacé.', 'example' => 2],
            'gameSoundName' => ['type' => 'string', 'example' => 'Anneau'],
            'sound' => ['type' => 'string', 'format' => 'iri-reference', 'description' => 'Le son du site qui le remplace.', 'example' => '/api/public/sounds/19'],
        ]],
    ])]
    public array $soundEffects = [];

    #[ApiProperty(description: 'Sa largeur, en blocs de 32 × 32 pixels.', example: 160)]
    public int $width;

    #[ApiProperty(description: 'Sa hauteur, en blocs.', example: 20)]
    public int $height;

    /** @var array{x: int, y: int} */
    #[ApiProperty(description: 'Où Sonic commence (en blocs, depuis le haut à gauche).', schema: [
        'type' => 'object', 'properties' => ['x' => ['type' => 'integer'], 'y' => ['type' => 'integer']], 'example' => ['x' => 2, 'y' => 14],
    ])]
    public array $start;

    #[ApiProperty(description: 'Combien d\'objets il contient (badniks, moniteurs, plateformes, panneau d\'arrivée…).', example: 12)]
    public int $objectCount;

    #[ApiProperty(description: 'Combien d\'anneaux il contient.', example: 42)]
    public int $ringCount;

    #[ApiProperty(description: 'S\'il a un panneau d\'arrivée (sinon, il ne se termine pas).', example: true)]
    public bool $hasGoal;

    #[ApiProperty(description: 'Le niveau dont il est une copie (un fork), s\'il est encore public.')]
    public ?Level $forkedFrom = null;

    #[ApiProperty(description: 'Combien de parties enregistrées ont été jouées dessus.', example: 5)]
    public int $replayCount;

    #[ApiProperty(description: 'La moyenne des étoiles (1 à 5) que les joueurs lui ont données (null : pas encore noté). On ne note pas ses propres niveaux.', example: 4.2)]
    public ?float $rating = null;

    #[ApiProperty(description: 'Combien de joueurs l\'ont noté.', example: 12)]
    public int $votes;

    #[ApiProperty(description: 'Le score du classement « mieux notés » : la moyenne ramenée vers 3 tant qu\'il a peu de votes (comme s\'il avait 4 votes de 3 de plus).', example: 3.9)]
    public float $score;

    #[ApiProperty(description: 'Combien de fois le jeu l\'a lancé (une fois toutes les quelques heures par joueur).', example: 340)]
    public int $plays;

    #[ApiProperty(description: 'Combien de joueurs l\'ont mis dans leurs favoris.', example: 7)]
    public int $favourites;

    /** @var list<array{rank: int, player: string, time: float, date: \DateTimeImmutable}> */
    #[ApiProperty(description: 'Ses 10 meilleurs temps (un par joueur), sur le niveau tel qu\'il est : le classement repart de zéro quand ses blocs '
        . 'ou ses objets changent.', schema: [
        'type' => 'array',
        'items' => ['type' => 'object', 'properties' => [
            'rank' => ['type' => 'integer', 'example' => 1],
            'player' => ['type' => 'string', 'example' => 'SONIC'],
            'time' => ['type' => 'number', 'description' => 'En secondes, comme le chrono du jeu.', 'example' => 62.35],
            'date' => ['type' => 'string', 'format' => 'date-time'],
        ]],
    ])]
    public array $bestTimes = [];

    #[ApiProperty(description: 'Quand il a été créé.')]
    public \DateTimeImmutable $createdAt;

    #[ApiProperty(description: 'Quand il a été modifié pour la dernière fois.')]
    public \DateTimeImmutable $updatedAt;

    /** @var array<string, string> */
    #[ApiProperty(description: 'Les adresses utiles : sa page sur le site, le jouer, sa miniature (PNG), son historique.', schema: [
        'type' => 'object',
        'properties' => [
            'page' => ['type' => 'string', 'format' => 'uri'], 'play' => ['type' => 'string', 'format' => 'uri'],
            'thumbnail' => ['type' => 'string', 'format' => 'uri'], 'map' => ['type' => 'string', 'format' => 'iri-reference'],
            'history' => ['type' => 'string', 'format' => 'iri-reference'],
        ],
    ])]
    public array $links = [];

    public static function ref(int $id): self
    {
        $level = new self();
        $level->id = $id;

        return $level;
    }
}
