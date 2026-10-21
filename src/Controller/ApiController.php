<?php

namespace App\Controller;

use App\Entity\ApiKey;
use App\Service\Studio\Context;
use App\Service\Studio\Tool;
use App\Service\Studio\ToolError;
use App\Service\Studio\ToolRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The same tools as the MCP server, for scripts and AIs without MCP: GET /api/v1 lists them (with the JSON Schema
 * of their arguments), POST /api/v1/{tool} with the arguments in JSON calls one. Same key, same limits.
 */
class ApiController extends AbstractController
{
    public function __construct(private ToolRegistry $tools)
    {
    }

    #[Route('/api/v1', name: 'api_v1', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'about' => 'Sonic PHP studio API: POST /api/v1/{tool} with the arguments as a JSON object, header Authorization: Bearer <key>.',
            'docs' => $this->generateUrl('ai', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'openapi' => $this->generateUrl('api_v1_openapi', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'tools' => array_values(array_map(fn(Tool $t) => ['name' => $t->name, 'title' => $t->title, 'description' => $t->description,
                'input_schema' => $t->schema + ['type' => 'object'], 'writes' => $t->write], $this->tools->all())),
        ]);
    }

    #[Route('/api/v1/openapi.json', name: 'api_v1_openapi', methods: ['GET'], priority: 10)]
    public function openapi(): JsonResponse
    {
        $paths = [];
        foreach ($this->tools->all() as $tool) {
            $paths['/api/v1/' . $tool->name] = ['post' => [
                'operationId' => $tool->name, 'summary' => $tool->title, 'description' => $tool->description,
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $tool->schema + ['type' => 'object']]]],
                'responses' => ['200' => ['description' => '{ok: true, data, images: [base64 PNG]}'], '400' => ['description' => '{ok: false, error}'],
                    '401' => ['description' => 'No or wrong key']],
            ]];
        }

        return $this->json([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Sonic PHP studio', 'version' => '1.0.0'],
            'servers' => [['url' => $this->generateUrl('home', [], UrlGeneratorInterface::ABSOLUTE_URL)]],
            'components' => ['securitySchemes' => ['key' => ['type' => 'http', 'scheme' => 'bearer']]],
            'security' => [['key' => []]],
            'paths' => $paths,
        ]);
    }

    #[Route('/api/v1/{tool}', name: 'api_v1_call', requirements: ['tool' => '[a-z_]{1,40}'], methods: ['POST'])]
    public function call(string $tool, Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 8 * 1024 * 1024) return $this->json(['ok' => false, 'error' => 'Request too big.'], 413);
        $arguments = $request->getContent() === '' ? [] : json_decode($request->getContent(), true);
        $key = $request->attributes->get('api_key');
        assert($key instanceof ApiKey);
        try {
            $result = $this->tools->call($tool, $arguments, new Context($key->getUser(), $key));
        } catch (ToolError $error) {
            return $this->json(['ok' => false, 'error' => $error->getMessage()], 400);
        }

        return $this->json(['ok' => true, 'data' => $result->data, 'images' => array_map('base64_encode', $result->images)]);
    }
}
