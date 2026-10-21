<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\PublicApi\SoundProvider;
use Symfony\Component\Validator\Constraints as Assert;

/** A public music or sound effect (public API, read only). */
#[ApiResource(
    shortName: 'Sound',
    description: 'Une musique ou un bruitage fait dans l\'éditeur de sons du site, à la façon de la Master System. `data` est la '
        . 'partition (ou les réglages du bruitage), `links.audio` le son rendu en WAV.',
    operations: [
        new GetCollection(
            uriTemplate: '/sounds',
            openapi: new Operation(summary: 'Les musiques et bruitages publics, les derniers modifiés d\'abord.'),
            parameters: [
                'author' => new QueryParameter(description: 'Seulement ceux de ce joueur.',
                    schema: ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{1,8}$'], constraints: [new Assert\Regex(pattern: '/^[A-Za-z0-9]{1,8}$/')]),
                'kind' => new QueryParameter(description: '`music` ou `sfx` (bruitage).',
                    schema: ['type' => 'string', 'enum' => ['music', 'sfx']], constraints: [new Assert\Choice(choices: ['music', 'sfx'])]),
            ],
        ),
        new Get(uriTemplate: '/sounds/{id}', openapi: new Operation(summary: 'Une musique ou un bruitage public, ou ceux d\'un niveau public.')),
    ],
    provider: SoundProvider::class,
)]
final class Sound
{
    #[ApiProperty(identifier: true, description: 'Son numéro (/son/{id} sur le site).', example: 18)]
    public int $id;

    #[ApiProperty(description: 'Son nom.', example: 'BANQUISE')]
    public string $name;

    #[ApiProperty(description: '`music` ou `sfx` (bruitage).', schema: ['type' => 'string', 'enum' => ['music', 'sfx']], example: 'music')]
    public string $kind;

    #[ApiProperty(description: 'Le joueur qui l\'a créé.')]
    public Player $author;

    #[ApiProperty(description: 'Le nom de ce joueur.', example: 'SONIC')]
    public string $authorName;

    #[ApiProperty(description: 'S\'il est public (sinon, on le voit seulement parce qu\'un niveau public s\'en sert).', example: true)]
    public bool $public;

    #[ApiProperty(description: 'Sa durée en secondes (une musique tourne en boucle dans le jeu).', example: 15.0)]
    public float $duration;

    /** @var array<string, mixed> */
    #[ApiProperty(description: 'Une musique : `{tempo, bars, voices: [4 × {volume, notes: [[pas, hauteur MIDI, longueur], …]}]}` (mélodie, '
        . 'accompagnement, basse, batterie ; 16 pas par mesure). Un bruitage : `{wave, start, end, attack, sustain, decay, volume, vibrato, '
        . 'speed, repeat, gap}` (fréquences en Hz, durées en ms).', schema: [
        'type' => 'object', 'additionalProperties' => true,
        'example' => ['wave' => 'square', 'start' => 300, 'end' => 900, 'attack' => 0, 'sustain' => 60, 'decay' => 120, 'volume' => 6,
            'vibrato' => 0, 'speed' => 0, 'repeat' => 1, 'gap' => 0],
    ])]
    public array $data;

    #[ApiProperty(description: 'Le son dont il est une copie (un fork), si on peut le voir.')]
    public ?Sound $forkedFrom = null;

    #[ApiProperty(description: 'Quand il a été créé.')]
    public \DateTimeImmutable $createdAt;

    #[ApiProperty(description: 'Quand il a été modifié pour la dernière fois.')]
    public \DateTimeImmutable $updatedAt;

    /** @var array<string, string> */
    #[ApiProperty(description: 'Les adresses utiles : sa page sur le site, le son en WAV (22 050 Hz, mono), l\'historique.', schema: [
        'type' => 'object',
        'properties' => [
            'page' => ['type' => 'string', 'format' => 'uri'], 'audio' => ['type' => 'string', 'format' => 'uri'],
            'history' => ['type' => 'string', 'format' => 'iri-reference'],
        ],
    ])]
    public array $links = [];

    public static function ref(int $id): self
    {
        $sound = new self();
        $sound->id = $id;

        return $sound;
    }
}
