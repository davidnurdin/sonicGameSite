<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\State\PublicApi\TilesetBlocksProvider;

/** Each block of a set of tiles: where it is in the image, its collision, its behaviour (public API). */
#[ApiResource(
    shortName: 'TilesetBlocks',
    description: 'Chaque bloc de tiles : sa place dans l\'image, sa forme de collision, s\'il est dessiné devant Sonic, ce qu\'il lui fait.',
    operations: [
        new Get(uriTemplate: '/tilesets/{id}/blocks', uriVariables: ['id' => new Link(fromClass: Tileset::class)],
            openapi: new Operation(summary: 'Le détail des blocs de tiles visibles.')),
    ],
    provider: TilesetBlocksProvider::class,
)]
final class TilesetBlocks
{
    #[ApiProperty(identifier: true, description: 'Le numéro des tiles.', example: 1)]
    public int $id;

    #[ApiProperty(description: 'Les tiles.')]
    public Tileset $tileset;

    #[ApiProperty(description: 'L\'adresse de leur image (PNG, 16 blocs de 32 pixels par ligne).')]
    public string $image;

    /** @var list<array<string, mixed>> */
    #[ApiProperty(description: 'Les blocs, dans l\'ordre. `shape` : la forme de collision (0 vide, 22 plein, 16 moitié basse, 1 à 4 des '
        . 'pentes…), `solidFloor` / `solidWalls` / `solidCeiling` : de quels côtés il arrête Sonic, `front` : dessiné devant Sonic, '
        . '`behaviour` : ce qu\'il fait (1 pics, 9 ressort…), `customCollision` : une collision dessinée pixel par pixel, '
        . '`ring` : un anneau (blocs 120 à 123).', schema: [
        'type' => 'array',
        'items' => ['type' => 'object', 'properties' => [
            'index' => ['type' => 'integer', 'example' => 1],
            'x' => ['type' => 'integer', 'description' => 'Sa place dans l\'image, en pixels.', 'example' => 32],
            'y' => ['type' => 'integer', 'example' => 0],
            'shape' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 46, 'example' => 16],
            'solidFloor' => ['type' => 'boolean'], 'solidWalls' => ['type' => 'boolean'], 'solidCeiling' => ['type' => 'boolean'],
            'front' => ['type' => 'boolean'],
            'behaviour' => ['type' => 'integer', 'example' => 0], 'behaviourName' => ['type' => 'string', 'example' => 'Rien'],
            'customCollision' => ['type' => 'boolean'], 'ring' => ['type' => 'boolean'],
        ]],
    ])]
    public array $blocks;
}
