<?php

declare(strict_types=1);

namespace App\Analytics;

use Symfony\Component\HttpFoundation\Request;

final class TrackingPreference
{
    public static function allows(Request $request): bool
    {
        return $request->headers->get('DNT') !== '1'
            && $request->headers->get('Sec-GPC') !== '1';
    }
}
