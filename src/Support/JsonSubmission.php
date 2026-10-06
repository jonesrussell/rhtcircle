<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\Request;

/** The public forms accept a bounded JSON object with scalar field values. */
final class JsonSubmission
{
    /** @return array<string, scalar|null>|null */
    public static function read(Request $request, int $maximumBytes): ?array
    {
        $raw = $request->getContent();
        if ($raw === '' || strlen($raw) > $maximumBytes || !str_starts_with(ltrim($raw), '{')) {
            return null;
        }
        try {
            $data = $request->toArray();
        } catch (JsonException) {
            return null;
        }
        foreach ($data as $key => $value) {
            if (!is_string($key) || ($value !== null && !is_scalar($value))) {
                return null;
            }
        }

        return $data;
    }
}
