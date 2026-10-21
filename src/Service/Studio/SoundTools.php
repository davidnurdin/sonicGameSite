<?php

namespace App\Service\Studio;

use App\Entity\Sound;
use App\Entity\SoundAudio;
use App\Service\SoundData;

/** The sounds: musics (4 voices, notes) and sound effects (a generator), rendered for the game on the server. */
final class SoundTools implements ToolProvider
{
    private const ID = ['type' => 'integer', 'minimum' => 1];

    public function __construct(private Studio $studio, private SoundRenderer $renderer)
    {
    }

    public function tools(): array
    {
        $data = ['type' => 'object', 'description' => 'music: {tempo, bars, voices: [4 x {volume, notes: [[step, pitch, length]...]}]}; '
            . 'sfx: {wave, start, end, attack, sustain, decay, volume, vibrato, speed, repeat, gap}. See get_reference "music" / "sfx".'];

        return [
            new Tool('get_sound', 'Read a sound', 'A music or a sound effect: its data (the format of get_reference "music" / "sfx") and its length.',
                ['properties' => ['id' => self::ID], 'required' => ['id']], $this->get(...)),
            new Tool('create_sound', 'Make a sound', 'A new music or sound effect on this account (private), made from its data; the site renders it as the game '
                . 'plays it. A level uses it with edit_level (set.music_sound_id, set.sfx).',
                ['properties' => ['name' => ['type' => 'string', 'maxLength' => 40], 'kind' => ['type' => 'string', 'enum' => ['music', 'sfx']], 'data' => $data],
                    'required' => ['name', 'kind', 'data']], $this->create(...), write: true, heavy: true),
            new Tool('edit_sound', 'Change a sound', 'Changes the data (all of it) and/or the name of a sound of this account; rendered again for the game.',
                ['properties' => ['id' => self::ID, 'data' => $data, 'name' => ['type' => 'string', 'maxLength' => 40]], 'required' => ['id']],
                $this->edit(...), write: true, heavy: true),
        ];
    }

    private function get(Args $args, Context $c): Result
    {
        /** @var Sound $sound */
        $sound = $this->studio->find('sound', $args->raw('id'), $c->user);

        return new Result($this->studio->summary($sound, $c->user) + ['data' => $sound->getData(), 'seconds' => $this->seconds($sound->getKind(), $sound->getData())]);
    }

    private function create(Args $args, Context $c): Result
    {
        $this->studio->checkRoom('sound', $c->user);
        $kind = $args->choice('kind', [Sound::MUSIC, Sound::SFX]);
        $data = $this->data($kind, $args->raw('data'));
        $sound = new Sound($c->user, Studio::name($args), $kind, $data);
        $this->studio->em->persist($sound);
        $this->studio->em->flush();
        $this->studio->em->persist(new SoundAudio($sound, $this->render($kind, $data)));
        $this->studio->em->flush();
        $this->studio->creations->created($sound, $c->user);

        return new Result(['created' => $this->studio->summary($sound, $c->user), 'seconds' => $this->seconds($kind, $data)]);
    }

    private function edit(Args $args, Context $c): Result
    {
        /** @var Sound $sound */
        $sound = $this->studio->find('sound', $args->raw('id'), $c->user, true);
        if ($args->has('name')) $sound->setName(Studio::name($args));
        if ($args->has('data')) {
            $data = $this->data($sound->getKind(), $args->raw('data'));
            $sound->setData($data);
            $wav = $this->render($sound->getKind(), $data);
            $stored = $this->studio->em->find(SoundAudio::class, $sound);
            $stored ? $stored->setWav($wav) : $this->studio->em->persist(new SoundAudio($sound, $wav));
        }
        $this->studio->em->flush();
        $this->studio->creations->saved($sound, $c->user, null);

        return new Result(['saved' => $this->studio->summary($sound, $c->user), 'seconds' => $this->seconds($sound->getKind(), $sound->getData())]);
    }

    private function data(string $kind, mixed $data): array
    {
        $checked = $kind === Sound::MUSIC ? SoundData::music($data) : SoundData::sfx($data);
        if ($checked === null) throw new ToolError("data: not a valid $kind (see get_reference \"" . ($kind === Sound::MUSIC ? 'music' : 'sfx') . '"): check every range.');

        return $checked;
    }

    private function render(string $kind, array $data): string
    {
        $wav = $kind === Sound::MUSIC ? $this->renderer->music($data) : $this->renderer->sfx($data);
        if (strlen($wav) > SoundData::MAX_WAV) throw new ToolError('Too long a sound for the game: fewer bars or a faster tempo.');

        return $wav;
    }

    private function seconds(string $kind, array $data): float
    {
        return round($kind === Sound::MUSIC ? $data['bars'] * 16 * 60 / $data['tempo'] / 4
            : ($data['repeat'] * ($data['attack'] + $data['sustain'] + $data['decay']) + ($data['repeat'] - 1) * $data['gap']) / 1000, 2);
    }
}
