<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\PublicApi\PlayerProvider;

/** A member of the site, as his public profile shows him (public API). */
#[ApiResource(
    shortName: 'Player',
    description: 'Un joueur inscrit, tel que le montre son profil public (/joueur/{nom}) : son nom, depuis quand il est inscrit, le '
        . 'temps qu\'il a joué, ses créations publiques. Rien de privé (ni e-mail, ni statistiques détaillées).',
    operations: [
        new GetCollection(uriTemplate: '/players', openapi: new Operation(summary: 'Les joueurs inscrits, par nom.')),
        new Get(uriTemplate: '/players/{username}', openapi: new Operation(summary: 'Un joueur, par son nom (en majuscules ou non).')),
    ],
    provider: PlayerProvider::class,
)]
final class Player
{
    #[ApiProperty(identifier: true, description: 'Son nom de joueur (celui du jeu, 15 caractères au plus).', example: 'SONIC')]
    public string $username;

    #[ApiProperty(description: 'Depuis quand il est inscrit.')]
    public \DateTimeImmutable $memberSince;

    #[ApiProperty(description: 'Le temps qu\'il a joué, en secondes (parties lancées depuis le site).', example: 5400)]
    public int $playTime;

    /** @var array{levels: int, tilesets: int, sprites: int, sounds: int} */
    #[ApiProperty(description: 'Combien de créations publiques il a, par sorte.', schema: [
        'type' => 'object',
        'properties' => ['levels' => ['type' => 'integer'], 'tilesets' => ['type' => 'integer'], 'sprites' => ['type' => 'integer'],
            'sounds' => ['type' => 'integer']],
        'example' => ['levels' => 3, 'tilesets' => 1, 'sprites' => 1, 'sounds' => 2],
    ])]
    public array $publicCreations;

    #[ApiProperty(description: 'Combien de parties enregistrées il a.', example: 12)]
    public int $replayCount;

    /** @var array<string, string> */
    #[ApiProperty(description: 'Les adresses utiles : son profil, ses niveaux, ses tiles, ses sprites, ses sons et ses parties, dans l\'API.', schema: [
        'type' => 'object',
        'properties' => [
            'profile' => ['type' => 'string', 'format' => 'uri'], 'levels' => ['type' => 'string', 'format' => 'iri-reference'],
            'tilesets' => ['type' => 'string', 'format' => 'iri-reference'], 'sprites' => ['type' => 'string', 'format' => 'iri-reference'],
            'sounds' => ['type' => 'string', 'format' => 'iri-reference'], 'replays' => ['type' => 'string', 'format' => 'iri-reference'],
        ],
    ])]
    public array $links = [];

    public static function ref(string $username): self
    {
        $player = new self();
        $player->username = $username;

        return $player;
    }
}
