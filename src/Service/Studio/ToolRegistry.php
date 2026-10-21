<?php

namespace App\Service\Studio;

use App\Service\Creations;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Every tool of the studio, for the MCP server (/mcp) and the REST API (/api/v1): a call checks the key may do it
 * (a read-only key: no tool that writes), counts it (rate limits per key), notes the key in the history of what it
 * changes (Creations::setVia), and turns a mistake of the AI (ToolError) into an answer it can read.
 */
final class ToolRegistry
{
    /** @var array<string, Tool>|null */
    private ?array $tools = null;

    /** @param iterable<ToolProvider> $providers */
    public function __construct(
        #[AutowireIterator('app.studio_tools')] private iterable $providers,
        private Creations $creations,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        #[Autowire(service: 'limiter.api')] private RateLimiterFactoryInterface $limiter,
        #[Autowire(service: 'limiter.api_heavy')] private RateLimiterFactoryInterface $heavyLimiter,
    ) {
    }

    /** @return array<string, Tool> */
    public function all(): array
    {
        if ($this->tools === null) {
            $this->tools = [];
            foreach ($this->providers as $provider) foreach ($provider->tools() as $tool) $this->tools[$tool->name] = $tool;
        }

        return $this->tools;
    }

    public function get(string $name): ?Tool
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Calls a tool. A Result, or a ToolError (the AI's mistake, or what it may not do: said to it).
     *
     * @throws ToolError
     */
    public function call(string $name, mixed $arguments, Context $context): Result
    {
        $tool = $this->get($name) ?? throw new ToolError("Unknown tool \"$name\". tools/list (MCP) or GET /api/v1 gives them.");
        if ($arguments !== null && (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments)))) throw new ToolError('The arguments must be an object.');
        if ($tool->write && !$context->key->canWrite()) throw new ToolError("This API key is read only: \"$name\" changes things. Its owner can make a key that writes, on the site.");
        $id = (string) $context->key->getId();
        if (!$this->limiter->create($id)->consume()->isAccepted()) throw new ToolError('Too many calls with this key: wait a few minutes (240 calls every 10 minutes).');
        if ($tool->heavy && !$this->heavyLimiter->create($id)->consume()->isAccepted()) {
            throw new ToolError('Too many pictures, test runs or sounds with this key: wait a few minutes (60 every 10 minutes).');
        }
        $this->creations->setVia($context->key->getName());
        try {
            return ($tool->handler)(new Args($arguments ?? []), $context);
        } catch (ToolError $error) {
            // nothing half done: what it changed before the mistake is not saved
            $this->em->clear();
            throw $error;
        } catch (\Throwable $error) {
            $this->em->clear();
            $this->logger->error('studio tool {tool}: {error}', ['tool' => $name, 'error' => $error->getMessage(), 'exception' => $error]);
            throw new ToolError('The site could not do it (an error on its side). Try again, or differently.');
        } finally {
            $this->creations->setVia(null);
        }
    }
}
