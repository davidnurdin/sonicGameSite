<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\PublicApi\ReplayProvider;
use Symfony\Component\Validator\Constraints as Assert;

/** A game recorded on the site, as the history page shows it (public API). */
#[ApiResource(
    shortName: 'Replay',
    description: 'Une partie jouée depuis le site, telle que la montre la page Historique : le mode, le niveau, les joueurs, le '
        . 'résultat. `links.watch` la rejoue dans le jeu, `links.file` est l\'enregistrement (gzip). Une partie en ligne n\'est '
        . 'donnée qu\'une fois.',
    operations: [
        new GetCollection(
            uriTemplate: '/replays',
            openapi: new Operation(summary: 'Les parties, les plus récentes d\'abord.'),
            parameters: [
                'player' => new QueryParameter(description: 'Seulement celles enregistrées par ce joueur.',
                    schema: ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,15}$'], constraints: [new Assert\Regex(pattern: '/^[A-Za-z0-9]{1,15}$/')]),
                'kind' => new QueryParameter(description: '`solo`, `versus` (contre l\'ordinateur) ou `online` (en ligne).',
                    schema: ['type' => 'string', 'enum' => ['solo', 'versus', 'online']], constraints: [new Assert\Choice(choices: ['solo', 'versus', 'online'])]),
                'level' => new QueryParameter(description: 'Seulement celles jouées sur ce niveau public du site (son id).',
                    schema: ['type' => 'integer', 'minimum' => 1], constraints: [new Assert\Positive()]),
            ],
        ),
        new Get(uriTemplate: '/replays/{id}', openapi: new Operation(summary: 'Une partie.')),
    ],
    provider: ReplayProvider::class,
)]
final class Replay
{
    #[ApiProperty(identifier: true, description: 'Son numéro.', example: 31)]
    public int $id;

    #[ApiProperty(description: '`solo`, `versus` (contre l\'ordinateur) ou `online` (en ligne).', schema: ['type' => 'string', 'enum' => ['solo', 'versus', 'online']])]
    public string $kind;

    #[ApiProperty(description: 'Le mode, à plusieurs : `race` (course), `flag` (drapeau), `rings` (anneaux), `deathmatch` (null seul).', example: 'race')]
    public ?string $mode = null;

    #[ApiProperty(description: 'Le niveau du jeu (son numéro ; 36 : un niveau du site).', example: 1)]
    public int $gameLevel;

    #[ApiProperty(description: 'Son nom.', example: 'Green Hill 1')]
    public string $levelName;

    #[ApiProperty(description: 'Le niveau du site joué, s\'il est public (null sinon, ou pour un niveau du jeu).')]
    public ?Level $level = null;

    #[ApiProperty(description: 'Le joueur qui l\'a enregistrée.')]
    public Player $player;

    /** @var list<string> */
    #[ApiProperty(description: 'Les noms des joueurs de la partie (ordinateur compris).', example: ['SONIC', 'CPU'])]
    public array $players;

    /** @var list<array{player: string, result: string}> */
    #[ApiProperty(description: 'Le classement, du premier au dernier, avec le résultat de chacun (un temps, un score…).', schema: [
        'type' => 'array',
        'items' => ['type' => 'object', 'properties' => ['player' => ['type' => 'string'], 'result' => ['type' => 'string']]],
        'example' => [['player' => 'SONIC', 'result' => '1:02'], ['player' => 'MEG', 'result' => '1:10']],
    ])]
    public array $ranking;

    #[ApiProperty(description: 'Sa durée, en secondes.', example: 62.5)]
    public float $duration;

    #[ApiProperty(description: 'Quand elle a été jouée.')]
    public \DateTimeImmutable $playedAt;

    /** @var array<string, string> */
    #[ApiProperty(description: 'Les adresses utiles : la revoir dans le jeu, le fichier de l\'enregistrement (gzip).', schema: [
        'type' => 'object',
        'properties' => ['watch' => ['type' => 'string', 'format' => 'uri'], 'file' => ['type' => 'string', 'format' => 'uri']],
    ])]
    public array $links = [];
}
