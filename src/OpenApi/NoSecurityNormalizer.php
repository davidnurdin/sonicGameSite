<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/** The public API has no authentication: no (empty) security schemes, so Swagger UI shows no "Authorize" button. */
#[AsDecorator('api_platform.openapi.normalizer')]
final class NoSecurityNormalizer implements NormalizerInterface
{
    public function __construct(#[AutowireDecorated] private NormalizerInterface $decorated)
    {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $out = $this->decorated->normalize($data, $format, $context);
        if (is_array($out) && isset($out['components']) && is_array($out['components'])
            && (($out['components']['securitySchemes'] ?? null) == [] || ($out['components']['securitySchemes'] ?? null) == new \ArrayObject())) {
            unset($out['components']['securitySchemes']);
        }

        return $out;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->decorated->supportsNormalization($data, $format, $context);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [OpenApi::class => true];
    }
}
