<?php

declare(strict_types=1);

namespace App\Support;

/** Preserve existing dedicated or JWT hash keys; refuse public fallback keys. */
final class HashSecret
{
    public static function fromEnvironment(string $dedicatedVariable): string
    {
        $secret = getenv($dedicatedVariable) ?: getenv('WAASEYAA_JWT_SECRET') ?: getenv('WAASEYAA_APP_SECRET');
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new \RuntimeException('Configure a private hash secret before using member forms or analytics.');
        }

        return $secret;
    }
}
