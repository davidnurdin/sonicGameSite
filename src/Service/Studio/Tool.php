<?php

namespace App\Service\Studio;

/** One tool of the studio's API: what MCP's tools/list shows, and what does it (handler(Args, Context): Result). */
final class Tool
{
    /**
     * @param array $schema the JSON Schema of its arguments (an object)
     * @param bool $write it changes the player's creations (a read-only key cannot)
     * @param bool $heavy it draws, plays or renders (its own, smaller rate limit)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly array $schema,
        public readonly \Closure $handler,
        public readonly bool $write = false,
        public readonly bool $heavy = false,
        public readonly bool $destructive = false,
    ) {
    }

    /** As MCP's tools/list gives it. */
    public function definition(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->schema + ['type' => 'object'],
            'annotations' => [
                'title' => $this->title,
                'readOnlyHint' => !$this->write,
                'destructiveHint' => $this->destructive,
                'idempotentHint' => !$this->write,
                'openWorldHint' => false,
            ],
        ];
    }
}
