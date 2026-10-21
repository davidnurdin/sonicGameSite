<?php

namespace App\Service\Studio;

/** The arguments of a call, checked one by one: a wrong one is a ToolError that says what is expected. */
final class Args
{
    /** @param string $where where these arguments are, for the errors ("ops[3]." ...) */
    public function __construct(private array $values, private string $where = '')
    {
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values) && $this->values[$name] !== null;
    }

    public function raw(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function int(string $name, int $min, int $max, ?int $default = null): int
    {
        $value = $this->values[$name] ?? $default;
        if (is_float($value) && floor($value) === $value) $value = (int) $value;
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) $value = (int) $value;
        if (!is_int($value) || $value < $min || $value > $max) throw new ToolError("{$this->where}$name: an integer from $min to $max is expected.");

        return $value;
    }

    public function optionalInt(string $name, int $min, int $max): ?int
    {
        return $this->has($name) ? $this->int($name, $min, $max) : null;
    }

    public function string(string $name, int $max = 200, ?string $default = null): string
    {
        $value = $this->values[$name] ?? $default;
        if (!is_string($value) || $value === '' || mb_strlen($value) > $max) throw new ToolError("{$this->where}$name: a text of 1 to $max characters is expected.");

        return $value;
    }

    public function bool(string $name, ?bool $default = null): bool
    {
        $value = $this->values[$name] ?? $default;
        if (!is_bool($value)) throw new ToolError("{$this->where}$name: true or false is expected.");

        return $value;
    }

    /** @template T of string @param list<T> $choices @return T */
    public function choice(string $name, array $choices, ?string $default = null): string
    {
        $value = $this->values[$name] ?? $default;
        if (!in_array($value, $choices, true)) throw new ToolError("{$this->where}$name: one of " . implode(', ', $choices) . ' is expected.');

        return $value;
    }

    public function list(string $name, int $max, bool $required = true): array
    {
        $value = $this->values[$name] ?? ($required ? null : []);
        if (!is_array($value) || !array_is_list($value) || count($value) > $max) throw new ToolError("{$this->where}$name: a list of at most $max items is expected.");

        return $value;
    }

    public function object(string $name, bool $required = true): array
    {
        $value = $this->values[$name] ?? ($required ? null : []);
        if (!is_array($value) || ($value !== [] && array_is_list($value))) throw new ToolError("{$this->where}$name: an object is expected.");

        return $value;
    }

    /** The arguments of an item of a list (an operation of edit_level...), its errors prefixed by where it is. */
    public function item(string $name, int $index): self
    {
        $value = $this->values[$name][$index] ?? null;
        if (!is_array($value)) throw new ToolError("{$this->where}{$name}[$index]: an object is expected.");

        return new self($value, "{$this->where}{$name}[$index].");
    }

    public function where(): string
    {
        return $this->where;
    }
}
