<?php

namespace App\Service\Studio;

/**
 * A test run of a level (a "fake game"), played by a bot with the physics of the game (sonicGamePhp Player and
 * CollisionSystem: the same sizes, speeds, jump, gravity, sides of the blocks, springs, spikes...), so that an AI
 * finds what is wrong in a level it made before the player tries it: a wall too high, a pit too wide, Sonic stuck,
 * a level that never ends. It is an approximation: the badniks do not move here, the platforms stand still, and
 * a bot plays (it holds towards the end, jumps in front of a wall, a pit or a danger, backs up to take a run).
 */
final class Simulator
{
    private const DT = 1 / 60;
    private const SIZE = 32;
    private const W = 23;
    private const H = 32;
    // Player (ROM values at this engine's scale)
    private const MAX_SPEED = 500.0;
    private const GROUND_ACCEL = 0x10 * (3600 / 256) * (500 / 180) ** 2;
    private const AIR_ACCEL = 0x10 / 256 * 3600 * (500 / 180);
    private const GRAVITY = 1400.0;
    private const JUMP_SPEED = 0x380 / 256 * 80;
    private const JUMP_HOLD = 16 / 60 / (80 / 60);
    private const MAX_FALL = 8 * 80.0;
    private const RINGS = [0x78, 0x79, 0x7A, 0x7B];
    // behaviours (CollisionSystem::B_*)
    private const B_SPIKES = 1, B_SPRING_LEFT = 3, B_SPRING_UP_HALF = 4, B_SPRING_RIGHT = 5, B_CONVEYOR_LEFT = 6,
        B_CONVEYOR_RIGHT = 7, B_WATER = 8, B_SPRING_UP = 9, B_SPRING_10 = 18, B_SPRING_12 = 19, B_SPRING_14 = 20, B_ELECTRIC = 23;

    /** The objects the bot must not touch unless it is a ball (EditorData::OBJECTS families) and those to stand on. */
    public const ENEMIES = [8, 14, 16, 17, 27, 31, 38, 45, 53, 60, 68, 58];
    public const HAZARDS = [22, 26, 61, 62, 63, 57];
    public const PLATFORMS = [9, 11, 12, 15, 41, 56, 59, 69];
    public const MONITORS = [1, 2, 3, 4, 5, 81, 82];
    public const GOAL = 7;

    private array $level;
    /** @var list<array> */
    private array $blocks;
    private array $behaviour;
    private array $tops = [];

    // Sonic
    private float $x, $y, $vx = 0, $vy = 0, $jumpHold = 0, $hurtClock = 0;
    private bool $grounded = false, $ball = false;
    private int $rings = 0;

    /**
     * @param array{width: int, height: int, blocks: string, start: array, objects: list<array>} $level
     * @param list<array> $blocks Solidity::blocks() of its set
     * @param list<int> $behaviour of its set
     */
    public function __construct(array $level, array $blocks, array $behaviour)
    {
        $this->level = $level;
        $this->blocks = $blocks;
        $this->behaviour = $behaviour;
    }

    /**
     * Plays the level: a few attempts (strategies), the best first.
     *
     * @return array{finished: bool, attempts: list<array>}
     */
    public function play(float $seconds = 240): array
    {
        $attempts = [];
        foreach (['fast' => self::MAX_SPEED, 'careful' => 300.0] as $name => $speed) {
            $attempts[] = $this->attempt($name, $speed, $seconds);
            if ($attempts[0]['result'] === 'finished') break;
        }
        usort($attempts, fn(array $a, array $b) => [$b['result'] === 'finished', $b['progress']] <=> [$a['result'] === 'finished', $a['progress']]);

        return ['finished' => $attempts[0]['result'] === 'finished', 'attempts' => $attempts];
    }

    /** One run: until the goal, 3 deaths, stuck for good, or the time is up. */
    private function attempt(string $strategy, float $topSpeed, float $seconds): array
    {
        $goal = null;
        foreach ($this->level['objects'] as [$type, $ox, $oy]) if ($type === self::GOAL) $goal = [$ox * self::SIZE + 16, $oy * self::SIZE];
        $startX = $this->level['start'][0] * self::SIZE;
        $direction = $goal !== null && $goal[0] < $startX ? -1 : 1;
        $taken = [];
        $hit = [];
        $events = [];
        $path = [];
        $deaths = 0;
        $jumps = 0;
        $best = 0.0;
        $stuckClock = 0.0;
        $backClock = 0.0;
        $lastBack = 0.0;
        $lastProgressX = 0.0;
        $result = 'timeout';
        $this->spawn();
        $wasJumpHeld = false;
        $frames = (int) ($seconds / self::DT);
        $at = fn() => [round(($this->x + self::W / 2) / self::SIZE, 1), round(($this->y + self::H) / self::SIZE, 1)];
        $event = function (string $type, string $what = '') use (&$events, $at, &$frame) {
            if (count($events) < 60) $events[] = ['t' => round($frame * self::DT, 1), 'type' => $type, 'at' => $at()] + ($what !== '' ? ['what' => $what] : []);
        };

        for ($frame = 0; $frame < $frames; $frame++) {
            // ---- the bot
            $toward = $direction;
            if ($backClock > 0) {
                $toward = -$direction;
                $backClock -= self::DT;
            }
            $jump = false;
            if ($this->grounded && $backClock <= 0) {
                $ahead = $direction * (abs($this->vx) * ($strategy === 'careful' ? 0.25 : 0.12) + 18);
                $jump = $this->wallAhead($direction, $ahead) || $this->pitAhead($ahead) || $this->dangerAhead($direction, $ahead + 24, $hit)
                    || $stuckClock > 0.4;
            }
            $held = $jump || ($wasJumpHeld && $this->jumpHold > 0);
            if ($jump && $this->grounded) {
                $this->vy = -self::JUMP_SPEED;
                $this->jumpHold = self::JUMP_HOLD;
                $this->grounded = false;
                $this->ball = true;
                $jumps++;
            }
            $wasJumpHeld = $held;

            // ---- physics (Player::update, CollisionSystem::checkCollisions)
            $accel = $this->grounded ? self::GROUND_ACCEL : self::AIR_ACCEL;
            $limit = $toward === $direction ? $topSpeed : 200.0;
            $this->vx += $toward * $accel * self::DT;
            if ($this->vx * $toward > $limit) $this->vx = $toward * $limit;
            if (!$this->grounded) $this->vy = min(self::MAX_FALL, $this->vy + self::GRAVITY * self::DT);
            if ($this->jumpHold > 0) {
                if (!$held || $this->vy >= 0 || $this->grounded) {
                    $this->jumpHold = 0;
                } else {
                    $this->jumpHold -= self::DT;
                    $this->vy = -self::JUMP_SPEED;
                }
            }
            $this->x += $this->vx * self::DT;
            $this->y += $this->vy * self::DT;
            $wasGrounded = $this->grounded;
            $this->collide($wasGrounded);
            $this->behaviours($event);
            if ($this->grounded) $this->ball = false;
            if ($this->hurtClock > 0) $this->hurtClock -= self::DT;

            // ---- rings, objects
            foreach ($this->touchedTiles() as [$tx, $ty]) {
                $block = ord($this->level['blocks'][$ty * $this->level['width'] + $tx]);
                if (in_array($block, self::RINGS, true) && !isset($taken["$tx,$ty"])) {
                    $taken["$tx,$ty"] = true;
                    $this->rings++;
                }
            }
            $dead = false;
            foreach ($this->level['objects'] as $i => [$type, $ox, $oy]) {
                if (isset($hit[$i]) || !$this->touches($ox, $oy)) continue;
                if ($type === self::GOAL) {
                    $result = 'finished';
                    $event('finish');
                    break 2;
                }
                if (in_array($type, self::MONITORS, true) && $this->ball) {
                    $hit[$i] = true;
                    if ($type === 1) $this->rings += 10;
                    $event('monitor', (string) $type);
                } elseif (in_array($type, self::ENEMIES, true)) {
                    $hit[$i] = true;
                    if ($this->ball) {
                        $event('badnik destroyed', (string) $type);
                        $this->vy = min($this->vy, -self::JUMP_SPEED * 0.8);
                    } else {
                        $dead = $this->hurt($event, 'badnik ' . $type) || $dead;
                    }
                } elseif (in_array($type, self::HAZARDS, true) && $this->hurtClock <= 0) {
                    $dead = $this->hurt($event, 'hazard ' . $type) || $dead;
                }
            }
            if ($this->hurtClock <= 0 && $this->hazard) {
                $dead = $this->hurt($event, 'spikes') || $dead;
            }
            $this->hazard = false;
            if ($this->y > $this->level['height'] * self::SIZE) {
                $dead = true;
                $event('death', 'fell into a pit');
            }
            if ($dead) {
                $deaths++;
                if ($deaths >= 3) {
                    $result = 'died';
                    break;
                }
                $this->spawn();
                $stuckClock = 0;
                continue;
            }

            // ---- progress, stuck
            $progress = $direction > 0 ? $this->x - $startX : $startX - $this->x;
            if ($progress > $best + 1) {
                $best = $progress;
                $stuckClock = 0;
                $lastBack = 0;
                $lastProgressX = $this->x;
            } else {
                $stuckClock += self::DT;
            }
            if ($stuckClock > 2.5 && $backClock <= 0 && $this->grounded && $stuckClock - $lastBack > 2.5) {
                $backClock = 0.7;
                $lastBack = $stuckClock;
                $event('stuck, backs up to take a run');
            }
            if ($stuckClock > 12) {
                $result = 'stuck';
                $event('stuck for good');
                break;
            }
            if ($frame % 6 === 0) $path[] = [round($this->x + self::W / 2), round($this->y + self::H / 2)];
        }
        $path[] = [round($this->x + self::W / 2), round($this->y + self::H / 2)];

        $total = $goal !== null ? abs($goal[0] - $startX) : null;

        return [
            'strategy' => $strategy,
            'result' => $goal === null && $result === 'timeout' ? 'no goal' : $result,
            'seconds' => round(min($frame, $frames) * self::DT, 1),
            'progress' => round($best / self::SIZE, 1),
            'goal_distance' => $total !== null ? round($total / self::SIZE, 1) : null,
            'furthest_block' => [round($lastProgressX / self::SIZE, 1)],
            'ended_at' => $at(),
            'deaths' => $deaths,
            'jumps' => $jumps,
            'rings' => $this->rings,
            'events' => $events,
            'path' => $path,
        ];
    }

    private bool $hazard = false;

    private function spawn(): void
    {
        $this->x = $this->level['start'][0] * self::SIZE;
        $this->y = $this->level['start'][1] * self::SIZE;
        $this->vx = $this->vy = $this->jumpHold = 0;
        $this->grounded = $this->ball = false;
        $this->rings = 0;
        $this->hurtClock = 0;
    }

    /** Touched: rings lost and thrown back, or dead without rings. True: dead. */
    private function hurt(callable $event, string $what): bool
    {
        if ($this->hurtClock > 0) return false;
        if ($this->rings === 0) {
            $event('death', $what);

            return true;
        }
        $event('hurt', $what);
        $this->rings = 0;
        $this->hurtClock = 2.0;
        $this->vx = -$this->vx * 0.5;
        $this->vy = -200;
        $this->grounded = false;

        return false;
    }

    // ---- the level

    private function block(int $tx, int $ty): ?array
    {
        if ($tx < 0 || $ty < 0 || $tx >= $this->level['width'] || $ty >= $this->level['height']) return null;

        return $this->blocks[ord($this->level['blocks'][$ty * $this->level['width'] + $tx])] ?? null;
    }

    private function solidAt(int $px, int $py, string $side = ''): bool
    {
        $b = $this->block($px >> 5, $py >> 5);
        if ($b === null || ($side !== '' && !$b[$side])) return false;

        return (($b['rows'][$py & 31] >> (31 - ($px & 31))) & 1) === 1;
    }

    /** The first solid row of a column of a block that can be stood on (CollisionSystem::findSurfaceYForGround). */
    private function surface(int $tx, int $ty, int $pixelX): ?float
    {
        $b = $this->block($tx, $ty);
        if ($b === null || !$b['floor']) return null;
        for ($y = 0; $y < self::SIZE; $y++) if ((($b['rows'][$y] >> (31 - $pixelX)) & 1) === 1) return (float) ($ty * self::SIZE + $y);

        return null;
    }

    private function collide(bool $wasGrounded): void
    {
        $this->grounded = false;
        $sensors = [(int) floor($this->x) + 4, (int) floor($this->x + self::W / 2), (int) floor($this->x + self::W) - 4];
        // ground (falling or standing)
        if ($this->vy >= 0) {
            $feet = $this->y + self::H;
            $best = null;
            foreach ($sensors as $sx) {
                $tx = $sx >> 5;
                $tyFeet = (int) floor($feet / self::SIZE);
                for ($ty = max(0, $tyFeet - 1); $ty <= $tyFeet + 1; $ty++) {
                    $s = $this->surface($tx, $ty, $sx & 31);
                    if ($s === null) continue;
                    $pen = $feet - $s;
                    if ($pen > 1 && !$this->solidAt($sx, (int) floor($feet) - 1)) continue;
                    if ($pen >= 0 && $pen <= self::SIZE) $best = $best === null ? $s : min($best, $s);
                }
            }
            $platform = $this->platformUnder($feet);
            if ($platform !== null && ($best === null || $platform < $best)) $best = $platform;
            if ($best !== null) {
                $this->y = $best - self::H;
                $this->grounded = true;
                $this->vy = 0;
            }
        }
        // ceiling
        if ($this->vy < 0) {
            $head = (int) floor($this->y);
            foreach ($sensors as $sx) {
                for ($ty = max(0, ($head >> 5) - 1); $ty <= $head >> 5; $ty++) {
                    $b = $this->block($sx >> 5, $ty);
                    if ($b === null || !$b['ceiling']) continue;
                    for ($row = 31; $row >= 0; $row--) {
                        if ((($b['rows'][$row] >> (31 - ($sx & 31))) & 1) === 1) {
                            $bottom = $ty * self::SIZE + $row + 1;
                            if ($head < $bottom && $bottom - $head <= self::SIZE) {
                                $this->y = $bottom;
                                $this->vy = 0;
                                $this->jumpHold = 0;
                            }
                            break;
                        }
                    }
                }
            }
        }
        // walls
        foreach ([(int) floor($this->y + self::H * 0.25), (int) floor($this->y + self::H * 0.5)] as $sy) {
            if ($this->vx > 0) {
                $edge = (int) floor($this->x + self::W);
                if ($this->solidAt($edge, $sy, 'walls')) {
                    $b = $this->block($edge >> 5, $sy >> 5);
                    for ($px = 0; $px < self::SIZE; $px++) {
                        if ((($b['rows'][$sy & 31] >> (31 - $px)) & 1) === 1) {
                            $this->x = ($edge >> 5) * self::SIZE + $px - self::W;
                            $this->vx = 0;
                            break;
                        }
                    }
                }
            } elseif ($this->vx < 0) {
                $edge = (int) floor($this->x);
                if ($edge >= 0 && $this->solidAt($edge, $sy, 'walls')) {
                    $b = $this->block($edge >> 5, $sy >> 5);
                    for ($px = 31; $px >= 0; $px--) {
                        if ((($b['rows'][$sy & 31] >> (31 - $px)) & 1) === 1) {
                            $this->x = ($edge >> 5) * self::SIZE + $px + 1;
                            $this->vx = 0;
                            break;
                        }
                    }
                }
            }
        }
        // down a slope or off a step: stay on the ground (snapToGround)
        if (!$this->grounded && $wasGrounded && $this->vy >= 0) {
            $feet = (int) floor($this->y + self::H);
            $reach = min(self::SIZE * 2, max(self::SIZE, (int) ceil(abs($this->vx) * self::DT * 2)));
            $best = null;
            foreach ($sensors as $sx) {
                for ($dy = 0; $dy <= $reach; $dy++) {
                    $s = $this->surface($sx >> 5, ($feet + $dy) >> 5, $sx & 31);
                    if ($s !== null && $feet + $dy >= $s) {
                        $best = $best === null ? $s : min($best, $s);
                        break;
                    }
                }
            }
            if ($best !== null) {
                $this->y = $best - self::H;
                $this->grounded = true;
                $this->vy = 0;
            }
        }
        // the sides of the level
        $max = $this->level['width'] * self::SIZE - self::W;
        if ($this->x < 0 || $this->x > $max) {
            $this->x = max(0, min($max, $this->x));
            $this->vx = 0;
        }
    }

    /** The top of a platform object under the feet (they stand still here: the game moves some). */
    private function platformUnder(float $feet): ?float
    {
        $cx = $this->x + self::W / 2;
        $best = null;
        foreach ($this->level['objects'] as [$type, $ox, $oy]) {
            if (!in_array($type, self::PLATFORMS, true)) continue;
            $top = $oy * self::SIZE + 16;
            if (abs($cx - ($ox * self::SIZE + 16)) <= 28 && $feet >= $top && $feet - $top <= 12) $best = $best === null ? $top : min($best, $top);
        }

        return $best;
    }

    private function behaviours(callable $event): void
    {
        $cx = (int) floor($this->x + self::W / 2);
        $feet = (int) floor($this->y + self::H - 1);
        $b = $this->behaviourAt($cx, $feet);
        $head = $this->behaviourAt($cx, (int) floor($this->y + 1));
        if ($head === self::B_SPIKES || $head === self::B_ELECTRIC) $b = $head;
        $inBlock = $cx & 31;
        switch ($b) {
            case self::B_SPIKES:
            case self::B_ELECTRIC:
                $this->hazard = true;
                break;
            case self::B_SPRING_UP:
                if ($inBlock >= 8 && $inBlock < 24 && $this->grounded) $this->spring(-12 * 80, $event);
                break;
            case self::B_SPRING_UP_HALF:
                if ($inBlock >= 16 && $this->grounded) $this->spring(-12 * 80, $event);
                break;
            case self::B_SPRING_10:
                $this->spring(-10 * 80, $event);
                break;
            case self::B_SPRING_12:
                $this->spring(-12 * 80, $event);
                break;
            case self::B_SPRING_14:
                $this->spring(-14 * 80, $event);
                break;
            case self::B_SPRING_LEFT:
                if ($inBlock >= 16) $this->vx = -900;
                break;
            case self::B_SPRING_RIGHT:
                if ($inBlock < 16) $this->vx = 900;
                break;
            case self::B_CONVEYOR_LEFT:
                if ($this->grounded) $this->x -= 1.5 * 60 * self::DT;
                break;
            case self::B_CONVEYOR_RIGHT:
                if ($this->grounded) $this->x += 2 * 60 * self::DT;
                break;
        }
    }

    private function spring(float $vy, callable $event): void
    {
        $this->vy = $vy;
        $this->grounded = false;
        $this->jumpHold = 0;
        $event('spring');
    }

    private function behaviourAt(int $px, int $py): int
    {
        if ($px < 0 || $py < 0 || $px >= $this->level['width'] * self::SIZE || $py >= $this->level['height'] * self::SIZE) return 0;

        return $this->behaviour[ord($this->level['blocks'][($py >> 5) * $this->level['width'] + ($px >> 5)])] ?? 0;
    }

    /** @return list<array{0: int, 1: int}> the blocks Sonic's box is over */
    private function touchedTiles(): array
    {
        $out = [];
        for ($ty = max(0, (int) floor($this->y / self::SIZE)); $ty <= min($this->level['height'] - 1, (int) floor(($this->y + self::H - 1) / self::SIZE)); $ty++) {
            for ($tx = max(0, (int) floor($this->x / self::SIZE)); $tx <= min($this->level['width'] - 1, (int) floor(($this->x + self::W - 1) / self::SIZE)); $tx++) $out[] = [$tx, $ty];
        }

        return $out;
    }

    /** Sonic's box over an object's (a block, its foot at y 16 of its block, as the game places them). */
    private function touches(int $ox, int $oy): bool
    {
        $left = $ox * self::SIZE + 4;
        $top = $oy * self::SIZE - 12;

        return $this->x < $left + 24 && $this->x + self::W > $left && $this->y < $top + 28 && $this->y + self::H > $top;
    }

    // ---- what the bot looks at

    private function wallAhead(int $direction, float $ahead): bool
    {
        $edge = $direction > 0 ? $this->x + self::W : $this->x;
        foreach ([0.25, 0.5, 0.9] as $f) {
            $sy = (int) floor($this->y + self::H * $f);
            for ($d = 4; $d <= abs($ahead); $d += 4) if ($this->solidAt((int) floor($edge + $direction * $d), $sy, 'walls')) return true;
        }

        // a step up: ground higher than the feet, just ahead
        $sx = (int) floor($edge + $direction * min(abs($ahead), 20));
        $feet = (int) floor($this->y + self::H);
        for ($y = $feet - 24; $y < $feet - 6; $y += 2) if ($this->solidAt($sx, $y, 'floor') && !$this->solidAt($sx, $y - 2)) return true;

        return false;
    }

    /** No ground ahead within 3 blocks down (a pit or a big drop). */
    private function pitAhead(float $ahead): bool
    {
        $sx = (int) floor($this->x + self::W / 2 + $ahead);
        $feet = (int) floor($this->y + self::H);
        for ($dy = -8; $dy <= 3 * self::SIZE; $dy += 2) if ($this->surface($sx >> 5, ($feet + $dy) >> 5, $sx & 31) !== null) return false;

        return true;
    }

    private function dangerAhead(int $direction, float $ahead, array $hit): bool
    {
        $cx = $this->x + self::W / 2;
        foreach ($this->level['objects'] as $i => [$type, $ox, $oy]) {
            if (isset($hit[$i]) || !in_array($type, [...self::ENEMIES, ...self::HAZARDS, ...self::MONITORS], true)) continue;
            $dx = ($ox * self::SIZE + 16 - $cx) * $direction;
            if ($dx > 0 && $dx < abs($ahead) && abs($oy * self::SIZE - $this->y) < 64) return true;
        }
        $sx = (int) floor($cx + $ahead);
        $b = $this->behaviourAt($sx, (int) floor($this->y + self::H + 2));

        return $b === self::B_SPIKES || $b === self::B_ELECTRIC;
    }
}
