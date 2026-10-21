<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\State\PublicApi\LevelMapProvider;

/** The blocks and objects of a public level (public API). */
#[ApiResource(
    shortName: 'LevelMap',
    description: 'Le contenu d\'un niveau public : sa grille de blocs, ses objets et le départ de Sonic. Chaque bloc est le '
        . 'numéro d\'un bloc de ses tiles (`/tilesets/{id}` donne leur image et leur collision).',
    operations: [
        new Get(uriTemplate: '/levels/{id}/map', uriVariables: ['id' => new Link(fromClass: Level::class)],
            openapi: new Operation(summary: 'Les blocs et les objets d\'un niveau public.')),
    ],
    provider: LevelMapProvider::class,
)]
final class LevelMap
{
    #[ApiProperty(identifier: true, description: 'Le numéro du niveau.', example: 12)]
    public int $id;

    #[ApiProperty(description: 'Le niveau.')]
    public Level $level;

    #[ApiProperty(description: 'Les tiles de ses blocs.')]
    public Tileset $tileset;

    #[ApiProperty(description: 'Largeur, en blocs.', example: 160)]
    public int $width;

    #[ApiProperty(description: 'Hauteur, en blocs.', example: 20)]
    public int $height;

    /** @var list<list<int>> */
    #[ApiProperty(description: 'Les blocs, ligne par ligne depuis le haut : `blocks[y][x]` est le numéro d\'un bloc de ses tiles (0 à 255). '
        . 'Les blocs 120 à 123 sont des anneaux.', schema: [
        'type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 255]],
        'example' => [[0, 0, 0], [1, 1, 1], [2, 2, 2]],
    ])]
    public array $blocks;

    /** @var array{x: int, y: int} */
    #[ApiProperty(description: 'Le départ de Sonic, en blocs.', schema: [
        'type' => 'object', 'properties' => ['x' => ['type' => 'integer'], 'y' => ['type' => 'integer']], 'example' => ['x' => 2, 'y' => 14],
    ])]
    public array $start;

    /** @var list<array{type: int, name: string, family: string, x: int, y: int}> */
    #[ApiProperty(description: 'Les objets, en blocs (leur pied est au milieu de leur bloc). `type` est le numéro de l\'objet dans le jeu.', schema: [
        'type' => 'array',
        'items' => ['type' => 'object', 'properties' => [
            'type' => ['type' => 'integer', 'example' => 7], 'name' => ['type' => 'string', 'example' => 'Panneau d\'arrivée'],
            'family' => ['type' => 'string', 'enum' => ['monitor', 'item', 'enemy', 'platform', 'hazard', 'other'], 'example' => 'item'],
            'x' => ['type' => 'integer', 'example' => 154], 'y' => ['type' => 'integer', 'example' => 15],
        ]],
    ])]
    public array $objects;
}
