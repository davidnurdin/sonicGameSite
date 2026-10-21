<?php

namespace App\Service;

/**
 * What the sound editor sends (public/js/editor-music.js, editor-sfx.js), checked and brought within bounds.
 *
 * A music: {tempo: beats a minute, bars: 16 steps each, voices: 4 × {volume: 0-10, notes: [[step, pitch, length]]}}.
 * The voices are those of the Master System's chip: VOICES (pitch: a MIDI note, or for the noise one of the 4
 * drums, DRUMS).
 *
 * A sound effect: {wave, start, end (Hz: the pitch slides from one to the other), attack, sustain, decay (ms),
 * volume: 0-10, vibrato: depth 0-100 and speed 0-40 Hz, repeat: 1-8 times, gap between them (ms)}.
 *
 * Both are also saved rendered (wav): what the game plays. A level may take a music, and sound effects instead of
 * some of the game's (GAME_SFX).
 */
final class SoundData
{
    /** The 4 voices of a music: name, sound. */
    public const VOICES = [['Mélodie', 'square'], ['Accompagnement', 'pulse'], ['Basse', 'triangle'], ['Batterie', 'noise']];
    public const DRUMS = ['Grosse caisse', 'Caisse claire', 'Charleston', 'Cymbale'];
    public const WAVES = ['square', 'pulse', 'triangle', 'sawtooth', 'sine', 'noise'];
    public const MAX_NOTES = 2048;
    /** The sounds of the game a level may change: the game's SoundManager::SFX_* => name. */
    public const GAME_SFX = [
        0x00 => 'Saut', 0x02 => 'Anneau', 0x04 => 'Ressort', 0x01 => 'Explosion (badnik, moniteur)', 0x11 => 'Sonic touché',
        0x09 => 'Vie en plus', 0x06 => 'Boule (roulé)', 0x03 => 'Dérapage', 0x07 => 'Bumper', 0x0A => 'Tir des badniks',
        0x12 => 'Plouf', 0x1A => 'Interrupteur', 0x0B => 'Panneau qui tourne',
    ];
    /** The rendering of the editor: a PCM WAV, mono, 16 bits, at this rate. */
    public const WAV_RATE = 22050;
    public const MAX_WAV = 12 * 1024 * 1024;
    public const LOW = 24;
    public const HIGH = 96;

    /** A new music: 4 bars at 120, nothing in them. */
    public static function newMusic(): array
    {
        return ['tempo' => 120, 'bars' => 4, 'voices' => array_map(fn() => ['volume' => 7, 'notes' => []], self::VOICES)];
    }

    /** A new sound effect: a jump. */
    public static function newSfx(): array
    {
        return ['wave' => 'square', 'start' => 300, 'end' => 900, 'attack' => 0, 'sustain' => 60, 'decay' => 120,
            'volume' => 6, 'vibrato' => 0, 'speed' => 0, 'repeat' => 1, 'gap' => 0];
    }

    public static function music(mixed $data): ?array
    {
        if (!is_array($data) || !is_array($data['voices'] ?? null) || count($data['voices']) !== count(self::VOICES)) return null;
        $bars = self::int($data['bars'] ?? null, 1, 32);
        $tempo = self::int($data['tempo'] ?? null, 40, 300);
        if ($bars === null || $tempo === null) return null;
        $steps = $bars * 16;
        $voices = [];
        foreach (array_values($data['voices']) as $i => $voice) {
            if (!is_array($voice) || !is_array($voice['notes'] ?? null) || count($voice['notes']) > self::MAX_NOTES) return null;
            $volume = self::int($voice['volume'] ?? null, 0, 10);
            if ($volume === null) return null;
            $drums = self::VOICES[$i][1] === 'noise';
            $notes = [];
            foreach ($voice['notes'] as $note) {
                if (!is_array($note) || count($note) !== 3) return null;
                [$step, $pitch, $length] = array_values($note);
                $step = self::int($step, 0, $steps - 1);
                $pitch = $drums ? self::int($pitch, 0, count(self::DRUMS) - 1) : self::int($pitch, self::LOW, self::HIGH);
                $length = self::int($length, 1, 64);
                if ($step === null || $pitch === null || $length === null) return null;
                $notes[] = [$step, $pitch, min($length, $steps - $step)];
            }
            $voices[] = ['volume' => $volume, 'notes' => $notes];
        }

        return ['tempo' => $tempo, 'bars' => $bars, 'voices' => $voices];
    }

    public static function sfx(mixed $data): ?array
    {
        if (!is_array($data) || !in_array($data['wave'] ?? null, self::WAVES, true)) return null;
        $sfx = ['wave' => $data['wave']];
        foreach (['start' => [20, 8000], 'end' => [20, 8000], 'attack' => [0, 1000], 'sustain' => [0, 2000], 'decay' => [0, 2000],
                     'volume' => [0, 10], 'vibrato' => [0, 100], 'speed' => [0, 40], 'repeat' => [1, 8], 'gap' => [0, 1000]] as $key => [$min, $max]) {
            $value = self::int($data[$key] ?? null, $min, $max);
            if ($value === null) return null;
            $sfx[$key] = $value;
        }

        return $sfx;
    }

    /** A WAV as the editor renders it (PCM, mono, 16 bits, WAV_RATE), or null. */
    public static function wav(mixed $base64): ?string
    {
        if (!is_string($base64) || strlen($base64) > self::MAX_WAV * 4 / 3 + 4) return null;
        $wav = base64_decode($base64, true);
        if ($wav === false || strlen($wav) < 44 || strlen($wav) > self::MAX_WAV) return null;
        if (substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 8) !== 'WAVEfmt ' || substr($wav, 36, 4) !== 'data') return null;
        $f = unpack('vformat/vchannels/Vrate/Vbytes/vblock/vbits', substr($wav, 20, 16));
        $size = unpack('V', substr($wav, 40, 4))[1];
        if ($f['format'] !== 1 || $f['channels'] !== 1 || $f['rate'] !== self::WAV_RATE || $f['bits'] !== 16) return null;

        return $size === strlen($wav) - 44 ? $wav : null;
    }

    private static function int(mixed $value, int $min, int $max): ?int
    {
        if (is_float($value) && floor($value) === $value) $value = (int) $value;

        return is_int($value) && $value >= $min && $value <= $max ? $value : null;
    }
}
