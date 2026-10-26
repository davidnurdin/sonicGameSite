<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\PublicApi\SpriteSetProvider;
use Symfony\Component\Validator\Constraints as Assert;

/** A public set of sprites (public API, read only). */
#[ApiResource(
    shortName: 'SpriteSet',
    description: 'Des sprites : des planches du jeu redessinées (Sonic, les badniks, les objets…). Les planches qui ne sont pas '
        . 'redessinées restent celles du jeu. Un niveau qui s\'en sert se joue avec.',
    operations: [
        new GetCollection(
            uriTemplate: '/sprites',
            openapi: new Operation(summary: 'Les sprites publics, les derniers modifiés d\'abord.'),
            parameters: [
                'author' => new QueryParameter(description: 'Seulement ceux de ce joueur.',
                    schema: ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,15}$'], constraints: [new Assert\Regex(pattern: '/^[A-Za-z0-9]{1,15}$/')]),
            ],
        ),
        new Get(uriTemplate: '/sprites/{id}', openapi: new Operation(summary: 'Des sprites publics, ou ceux d\'un niveau public.')),
    ],
    provider: SpriteSetProvider::class,
)]
final class SpriteSet
{
    #[ApiProperty(identifier: true, description: 'Leur numéro (/sprites/{id} sur le site).', example: 6)]
    public int $id;

    #[ApiProperty(description: 'Leur nom.', example: 'SONIC GIVRE')]
    public string $name;

    #[ApiProperty(description: 'Le joueur qui les a créés.')]
    public Player $author;

    #[ApiProperty(description: 'Le nom de ce joueur.', example: 'SONIC')]
    public string $authorName;

    #[ApiProperty(description: 'S\'ils sont publics (sinon, on les voit seulement parce qu\'un niveau public s\'en sert).', example: true)]
    public bool $public;

    /** @var list<array{sheet: string, label: string, width: int, height: int, redrawn: bool, image: string}> */
    #[ApiProperty(description: 'Les planches : leur nom, leur taille, si elles sont redessinées, et l\'adresse de leur image (PNG).', schema: [
        'type' => 'array',
        'items' => ['type' => 'object', 'properties' => [
            'sheet' => ['type' => 'string', 'enum' => ['sonic', 'enemies', 'objects', 'rom', 'eggman', 'boss'], 'example' => 'sonic'],
            'label' => ['type' => 'string', 'example' => 'Sonic'],
            'width' => ['type' => 'integer', 'example' => 435], 'height' => ['type' => 'integer', 'example' => 128],
            'redrawn' => ['type' => 'boolean', 'example' => true],
            'image' => ['type' => 'string', 'format' => 'uri'],
        ]],
    ])]
    public array $sheets = [];

    #[ApiProperty(description: 'Les sprites dont ils sont une copie (un fork), si on peut les voir.')]
    public ?SpriteSet $forkedFrom = null;

    #[ApiProperty(description: 'Quand ils ont été créés.')]
    public \DateTimeImmutable $createdAt;

    #[ApiProperty(description: 'Quand ils ont été modifiés pour la dernière fois.')]
    public \DateTimeImmutable $updatedAt;

    /** @var array<string, string> */
    #[ApiProperty(description: 'Les adresses utiles : leur page sur le site, l\'historique.', schema: [
        'type' => 'object',
        'properties' => ['page' => ['type' => 'string', 'format' => 'uri'], 'history' => ['type' => 'string', 'format' => 'iri-reference']],
    ])]
    public array $links = [];

    public static function ref(int $id): self
    {
        $set = new self();
        $set->id = $id;

        return $set;
    }
}
