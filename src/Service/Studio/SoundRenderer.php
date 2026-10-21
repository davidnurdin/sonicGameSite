<?php

namespace App\Service\Studio;

use App\Service\SoundData;

/**
 * The sounds of the sound editor rendered on the server, as public/js/sound-engine.js renders them in the browser
 * (forGame: a WAV, mono, 16 bits, SoundData::WAV_RATE; a music exactly once round, the game loops it): what the game
 * plays. For the sounds an AI makes through the API, without a browser. The same voices (square, pulse of 1/4,
 * triangle, noise for the drums), envelopes, pitch slides, vibrato and filters; close to the browser's, not the
 * same samples.
 */
final class SoundRenderer
{
    private const RATE = SoundData::WAV_RATE;
    private const CHUNK = 4096;
    private const VOICES = ['square', 'pulse', 'triangle', 'noise'];
    /** sound-engine.js DRUMS: filter, its frequency, length (s), gain */
    private const DRUMS = [['lowpass', 300, 0.18, 1.0], ['bandpass', 1800, 0.14, 0.8], ['highpass', 7000, 0.05, 0.5], ['highpass', 4000, 0.6, 0.45]];

    /** A music (SoundData::music) as the game plays it: a WAV. */
    public function music(array $music): string
    {
        $step = 60 / $music['tempo'] / 4;
        $events = [];
        foreach ($music['voices'] as $v => $voice) {
            $wave = self::VOICES[$v];
            $level = $voice['volume'] / 10 * ($wave === 'noise' ? 0.45 : ($wave === 'triangle' ? 0.32 : 0.14));
            if ($level <= 0) continue;
            foreach ($voice['notes'] as [$s, $pitch, $length]) {
                $t = $s * $step;
                if ($wave === 'noise') {
                    $drum = self::DRUMS[$pitch] ?? self::DRUMS[0];
                    $events[] = ['kind' => 'drum', 't' => $t, 'end' => $t + $drum[2], 'filter' => self::biquad($drum[0], $drum[1]),
                        'gain' => $level * $drum[3], 'len' => $drum[2]];
                    continue;
                }
                $events[] = ['kind' => 'tone', 't' => $t, 'end' => $t + $length * $step, 'wave' => $wave,
                    'hz' => 440 * 2 ** (($pitch - 69) / 12), 'level' => $level];
            }
        }

        return $this->wav($events, $music['bars'] * 16 * $step);
    }

    /** A sound effect (SoundData::sfx): a WAV. */
    public function sfx(array $p): string
    {
        $one = ($p['attack'] + $p['sustain'] + $p['decay']) / 1000;
        $peak = $p['volume'] / 10 * ($p['wave'] === 'noise' ? 0.5 : 0.3);
        $events = [];
        for ($r = 0; $r < $p['repeat']; $r++) {
            $t = $r * ($one + $p['gap'] / 1000);
            $events[] = ['kind' => 'sfx', 't' => $t, 'end' => $t + $one, 'p' => $p, 'peak' => $peak];
        }
        $length = ($p['repeat'] * ($p['attack'] + $p['sustain'] + $p['decay']) + ($p['repeat'] - 1) * $p['gap']) / 1000 + 0.05;

        return $this->wav($events, $length);
    }

    /** The events mixed chunk by chunk (a long music stays small in memory), clipped, as 16-bit PCM. */
    private function wav(array $events, float $seconds): string
    {
        $total = max(1, (int) ceil($seconds * self::RATE));
        mt_srand(1);
        foreach ($events as &$e) {
            $e += ['phase' => 0.0, 'z1' => 0.0, 'z2' => 0.0, 'from' => (int) floor($e['t'] * self::RATE),
                'to' => min($total, (int) ceil(($e['end'] + 0.01) * self::RATE))];
        }
        unset($e);
        usort($events, fn(array $a, array $b) => $a['from'] <=> $b['from']);
        $pcm = '';
        $next = 0;
        $active = [];
        for ($c0 = 0; $c0 < $total; $c0 += self::CHUNK) {
            $c1 = min($total, $c0 + self::CHUNK);
            while ($next < count($events) && $events[$next]['from'] < $c1) $active[] = $next++;
            $mix = array_fill(0, $c1 - $c0, 0.0);
            foreach ($active as $k => $i) {
                $this->play($events[$i], $mix, $c0, $c1);
                if ($events[$i]['to'] <= $c1) unset($active[$k]);
            }
            foreach ($mix as $sample) $pcm .= pack('v', (int) round(max(-1.0, min(1.0, $sample)) * 0x7FFF) & 0xFFFF);
        }

        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, self::RATE, self::RATE * 2, 2, 16)
            . 'data' . pack('V', strlen($pcm)) . $pcm;
    }

    /** One event added to the samples [$c0, $c1) of the mix. */
    private function play(array &$e, array &$mix, int $c0, int $c1): void
    {
        $from = max($c0, $e['from']);
        $to = min($c1, $e['to']);
        for ($n = $from; $n < $to; $n++) {
            $t = $n / self::RATE - $e['t'];
            switch ($e['kind']) {
                case 'tone':
                    $length = $e['end'] - $e['t'];
                    $hold = max(0.004, $length - 0.02);
                    $gain = $t < 0.004 ? $e['level'] * $t / 0.004 : ($t < $hold ? $e['level']
                        : ($t < $length ? $e['level'] * max(0, ($length - $t) / max(1e-6, $length - $hold)) : 0));
                    $e['phase'] = fmod($e['phase'] + $e['hz'] / self::RATE, 1.0);
                    $mix[$n - $c0] += $gain * self::wave($e['wave'], $e['phase']);
                    break;
                case 'drum':
                    $gain = $t < $e['len'] ? $e['gain'] * (0.001 / $e['gain']) ** ($t / $e['len']) : 0;
                    $mix[$n - $c0] += $gain * self::filter($e, $e['filter'], mt_rand() / mt_getrandmax() * 2 - 1);
                    break;
                case 'sfx':
                    $p = $e['p'];
                    $one = $e['end'] - $e['t'];
                    $attack = max(0.002, $p['attack'] / 1000);
                    $hold = ($p['attack'] + $p['sustain']) / 1000;
                    $gain = $t < $attack ? $e['peak'] * $t / $attack : ($t < $hold ? $e['peak']
                        : ($t < $one ? $e['peak'] * max(0, ($one - $t) / max(1e-6, $one - $hold)) : 0));
                    // the pitch slides exponentially from start to end
                    $hz = $p['start'] * ($p['end'] / $p['start']) ** (min($t, $one) / max(1e-6, $one));
                    if ($p['wave'] === 'noise') {
                        // a low-pass filter sliding like the pitch would (recomputed every 32 samples)
                        if (($n & 31) === 0 || !isset($e['coef'])) $e['coef'] = self::biquad('lowpass', $hz);
                        $sample = self::filter($e, $e['coef'], mt_rand() / mt_getrandmax() * 2 - 1);
                    } else {
                        if ($p['vibrato'] > 0 && $p['speed'] > 0) $hz *= 2 ** ($p['vibrato'] * 1.5 * sin(2 * M_PI * $p['speed'] * $t) / 1200);
                        $e['phase'] = fmod($e['phase'] + $hz / self::RATE, 1.0);
                        $sample = self::wave($p['wave'], $e['phase']);
                    }
                    $mix[$n - $c0] += $gain * $sample;
                    break;
            }
        }
    }

    private static function wave(string $wave, float $phase): float
    {
        return match ($wave) {
            'square' => $phase < 0.5 ? 1.0 : -1.0,
            // a pulse of 1/4 without its mean, at most 1 (the browser's periodic wave is normalised)
            'pulse' => $phase < 0.25 ? 1.0 : -1 / 3,
            'triangle' => 1 - 4 * abs($phase - 0.5),
            'sawtooth' => 2 * $phase - 1,
            'sine' => sin(2 * M_PI * $phase),
            default => mt_rand() / mt_getrandmax() * 2 - 1,
        };
    }

    /** The coefficients of a biquad filter (Web Audio's, Q = 1 / sqrt(2) for the low and high pass, 1 for the band). */
    private static function biquad(string $type, float $hz): array
    {
        $hz = max(10, min(self::RATE / 2 - 10, $hz));
        $w = 2 * M_PI * $hz / self::RATE;
        $q = $type === 'bandpass' ? 1.0 : M_SQRT1_2;
        $alpha = sin($w) / (2 * $q);
        $cos = cos($w);
        [$b0, $b1, $b2] = match ($type) {
            'lowpass' => [(1 - $cos) / 2, 1 - $cos, (1 - $cos) / 2],
            'highpass' => [(1 + $cos) / 2, -(1 + $cos), (1 + $cos) / 2],
            default => [$alpha, 0, -$alpha],
        };
        $a0 = 1 + $alpha;

        return [$b0 / $a0, $b1 / $a0, $b2 / $a0, -2 * $cos / $a0, (1 - $alpha) / $a0];
    }

    /** One sample through a biquad (transposed direct form II, its state in the event). */
    private static function filter(array &$e, array $c, float $x): float
    {
        $y = $c[0] * $x + $e['z1'];
        $e['z1'] = $c[1] * $x - $c[3] * $y + $e['z2'];
        $e['z2'] = $c[2] * $x - $c[4] * $y;

        return $y;
    }
}
