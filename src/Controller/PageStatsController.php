<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analytics\AnalyticsReport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public, read-only per-page view count for on-page social proof.
 *
 * Returns only an aggregate count for a single path, no per-visitor data, so it
 * is safe to expose without auth. Short cache to soften load.
 */
final class PageStatsController
{
    public function __construct(private readonly AnalyticsReport $report) {}

    public function stats(Request $request): Response
    {
        $path = (string) $request->query->get('path', '');
        if ($path === '' || $path[0] !== '/' || strlen($path) > 255) {
            return new JsonResponse(['views' => 0], 400);
        }

        return new JsonResponse(
            ['views' => $this->report->viewsForPath($path)],
            200,
            ['Cache-Control' => 'public, max-age=60'],
        );
    }
}
