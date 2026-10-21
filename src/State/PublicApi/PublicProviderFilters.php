<?php

namespace App\State\PublicApi;

/** A filter of the query string of the public API (already checked by the parameters of the operation). */
final class PublicProviderFilters
{
    public static function get(array $context, string $name): ?string
    {
        $value = $context['filters'][$name] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
