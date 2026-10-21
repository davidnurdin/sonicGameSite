<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\QueryParameter;
use App\State\PublicApi\HistoryProvider;
use Symfony\Component\Validator\Constraints as Assert;

/** One line of the history of a creation (public API). */
#[ApiResource(
    shortName: 'HistoryEntry',
    description: 'Une ligne de l\'historique d\'une création : qui, quand, quoi (création, fork, enregistrement, version reprise, '
        . 'joueur ajouté, corbeille…) et ce qui a changé, en mots. Le contenu des versions n\'est pas donné.',
    operations: [
        new GetCollection(uriTemplate: '/levels/{id}/history', uriVariables: ['id' => new Link(fromClass: Level::class)],
            openapi: new Operation(summary: 'L\'historique d\'un niveau public, le plus récent d\'abord.'), parameters: ['lang' => new QueryParameter(description: 'La langue des changements : `fr` (défaut) ou `en`.',
                schema: ['type' => 'string', 'enum' => ['fr', 'en'], 'default' => 'fr'], constraints: [new Assert\Choice(choices: ['fr', 'en'])])]),
        new GetCollection(uriTemplate: '/tilesets/{id}/history', uriVariables: ['id' => new Link(fromClass: Tileset::class)],
            openapi: new Operation(summary: 'L\'historique de tiles visibles, le plus récent d\'abord.'), parameters: ['lang' => new QueryParameter(description: 'La langue des changements : `fr` (défaut) ou `en`.',
                schema: ['type' => 'string', 'enum' => ['fr', 'en'], 'default' => 'fr'], constraints: [new Assert\Choice(choices: ['fr', 'en'])])]),
        new GetCollection(uriTemplate: '/sprites/{id}/history', uriVariables: ['id' => new Link(fromClass: SpriteSet::class)],
            openapi: new Operation(summary: 'L\'historique de sprites visibles, le plus récent d\'abord.'), parameters: ['lang' => new QueryParameter(description: 'La langue des changements : `fr` (défaut) ou `en`.',
                schema: ['type' => 'string', 'enum' => ['fr', 'en'], 'default' => 'fr'], constraints: [new Assert\Choice(choices: ['fr', 'en'])])]),
        new GetCollection(uriTemplate: '/sounds/{id}/history', uriVariables: ['id' => new Link(fromClass: Sound::class)],
            openapi: new Operation(summary: 'L\'historique d\'un son visible, le plus récent d\'abord.'), parameters: ['lang' => new QueryParameter(description: 'La langue des changements : `fr` (défaut) ou `en`.',
                schema: ['type' => 'string', 'enum' => ['fr', 'en'], 'default' => 'fr'], constraints: [new Assert\Choice(choices: ['fr', 'en'])])]),
        new Get(uriTemplate: '/history/{id}', openapi: new Operation(summary: 'Une ligne d\'historique (d\'une création visible).'), parameters: ['lang' => new QueryParameter(description: 'La langue des changements : `fr` (défaut) ou `en`.',
                schema: ['type' => 'string', 'enum' => ['fr', 'en'], 'default' => 'fr'], constraints: [new Assert\Choice(choices: ['fr', 'en'])])]),
    ],
    provider: HistoryProvider::class,
)]
final class HistoryEntry
{
    #[ApiProperty(identifier: true, description: 'Son numéro.', example: 120)]
    public int $id;

    #[ApiProperty(description: 'La sorte de création : `level`, `tileset`, `sprites` ou `sound`.', schema: ['type' => 'string', 'enum' => ['level', 'tileset', 'sprites', 'sound']])]
    public string $kind;

    #[ApiProperty(description: 'L\'adresse de la création dans l\'API.', schema: ['type' => 'string', 'format' => 'iri-reference'], example: '/api/public/levels/12')]
    public string $creation;

    #[ApiProperty(description: 'Ce qui a été fait : `create` (création), `fork` (copie d\'une autre création), `forked` (copiée par '
        . 'quelqu\'un), `save` (enregistrement), `restore` (une ancienne version reprise), `member` / `unmember` (un joueur ajouté / '
        . 'retiré), `trash` / `untrash` (mise à la corbeille / restaurée), `delete` (supprimée pour de bon).', schema: [
        'type' => 'string', 'enum' => ['create', 'fork', 'forked', 'save', 'restore', 'member', 'unmember', 'trash', 'untrash', 'delete'], 'example' => 'save',
    ])]
    public string $action;

    #[ApiProperty(description: 'Qui l\'a fait (null : un compte supprimé, ou le site).')]
    public ?Player $by = null;

    #[ApiProperty(description: 'Quand.')]
    public \DateTimeImmutable $at;

    /** @var list<string> */
    #[ApiProperty(description: 'Ce qui a changé, en mots.', example: ['921 blocs changés', 'Musique changée'])]
    public array $changes = [];
}
