<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A sound as the editor rendered it (SoundData::wav): what the game plays. Apart from Sound so that the lists of
 * sounds do not load it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sound_audio')]
class SoundAudio
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Sound::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Sound $sound;

    #[ORM\Column(type: Types::BLOB)]
    private $wav;

    public function __construct(Sound $sound, string $wav)
    {
        $this->sound = $sound;
        $this->wav = $wav;
    }

    public function getSound(): Sound { return $this->sound; }
    public function setWav(string $wav): void { $this->wav = $wav; }

    public function getWav(): string
    {
        return is_resource($this->wav) ? (string) stream_get_contents($this->wav, -1, 0) : (string) $this->wav;
    }
}
