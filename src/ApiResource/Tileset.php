<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\PublicApi\TilesetProvider;
use Symfony\Component\Validator\Constraints as Assert;

/** A public set of tiles (public API, read only). */
#[ApiResource(
    shortName: 'Tileset',
    description: 'Des tiles : jusqu\'à 256 blocs de 32 × 32 pixels (une image PNG de 16 blocs par ligne), chacun avec sa forme de '
        . 'collision. Ce sont les 7 zones du jeu (Green Hill, Bridge…), les tiles publiques des joueurs, et celles des niveaux '
        . 'publics. Le détail de chaque bloc est dans `/tilesets/{id}/blocks`.',
    operations: [
        new GetCollection(
            uriTemplate: '/tilesets',
            openapi: new Operation(summary: 'Les zones du jeu et les tiles publiques des joueurs, les dernières modifiées d\'abord.'),
            parameters: [
                'author' => new QueryParameter(description: 'Seulement celles de ce joueur.',
                    schema: ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,8}$'], constraints: [new Assert\Regex(pattern: '/^[A-Za-z0-9]{1,8}$/')]),
                'zone' => new QueryParameter(description: '`true` : seulement les zones du jeu ; `false` : seulement celles des joueurs.',
                    schema: ['type' => 'boolean'], constraints: [new Assert\Choice(choices: ['true', 'false', '1', '0'])]),
            ],
        ),
        new Get(uriTemplate: '/tilesets/{id}', openapi: new Operation(summary: 'Des tiles publiques, une zone du jeu, ou les tiles d\'un niveau public.')),
    ],
    provider: TilesetProvider::class,
)]
final class Tileset
{
    #[ApiProperty(identifier: true, description: 'Leur numéro (/tiles/{id} sur le site).', example: 1)]
    public int $id;

    #[ApiProperty(description: 'Leur nom.', example: 'Green Hill')]
    public string $name;

    #[ApiProperty(description: 'Le joueur qui les a créées (null : une zone du jeu).')]
    public ?Player $author = null;

    #[ApiProperty(description: 'Le nom de ce joueur (null : une zone du jeu).', example: 'SONIC')]
    public ?string $authorName = null;

    #[ApiProperty(description: 'La zone du jeu (null : des tiles d\'un joueur).', example: 'green-hill')]
    public ?string $zone = null;

    #[ApiProperty(description: 'Si elles sont publiques (sinon, on les voit seulement parce qu\'un niveau public s\'en sert).', example: true)]
    public bool $public;

    #[ApiProperty(description: 'Combien de blocs elles ont.', example: 184)]
    public int $blockCount;

    #[ApiProperty(description: 'Les tiles dont elles sont une copie (un fork), si on peut les voir.')]
    public ?Tileset $forkedFrom = null;

    #[ApiProperty(description: 'Quand elles ont été créées.')]
    public \DateTimeImmutable $createdAt;

    #[ApiProperty(description: 'Quand elles ont été modifiées pour la dernière fois.')]
    public \DateTimeImmutable $updatedAt;

    /** @var array<string, string> */
    #[ApiProperty(description: 'Les adresses utiles : leur page, leur image (PNG, 512 pixels de large, 16 blocs par ligne), le détail des '
        . 'blocs, l\'historique.', schema: [
        'type' => 'object',
        'properties' => [
            'page' => ['type' => 'string', 'format' => 'uri'], 'image' => ['type' => 'string', 'format' => 'uri'],
            'blocks' => ['type' => 'string', 'format' => 'iri-reference'], 'history' => ['type' => 'string', 'format' => 'iri-reference'],
        ],
    ])]
    public array $links = [];

    public static function ref(int $id): self
    {
        $tileset = new self();
        $tileset->id = $id;

        return $tileset;
    }
}
