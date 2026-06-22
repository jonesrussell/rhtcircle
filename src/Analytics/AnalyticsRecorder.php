<?php

declare(strict_types=1);

namespace App\Analytics;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Validates an incoming beacon and writes one append-only row.
 *
 * Privacy: raw IP and user-agent are never stored. A visitor is identified
 * only by a daily-rotating salted hash, so the same person on the same day
 * collapses to one hash and cannot be tracked across days or de-anonymised.
 */
final class AnalyticsRecorder
{
    private const MAX_DWELL_MS = 86_400_000; // 24h cap

    private const BOT_PATTERN =
        '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|preview|'
        . 'whatsapp|telegrambot|scrapy|curl|wget|python-requests|headless|lighthouse|monitor/i';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $secret,
    ) {}

    /**
     * @param array<string,mixed> $beacon decoded JSON beacon
     *
     * @return bool true if a row was stored, false if the beacon was rejected
     */
    public function record(array $beacon, ?string $ip, ?string $userAgent): bool
    {
        $type = is_string($beacon['t'] ?? null) ? $beacon['t'] : '';
        if ($type !== 'pageview' && $type !== 'engagement') {
            return false;
        }

        if ($userAgent !== null && $userAgent !== '' && preg_match(self::BOT_PATTERN, $userAgent) === 1) {
            return false;
        }

        $viewId = $this->str($beacon['v'] ?? null, 64);
        if ($viewId === null) {
            return false;
        }

        if ($type === 'pageview') {
            $path = $this->str($beacon['p'] ?? null, 255);
            if ($path === null) {
                return false;
            }
            $row = [
                'pageview',
                $path,
                $this->refHost($beacon['r'] ?? null),
                $viewId,
                $this->visitorHash($ip, $userAgent),
                $this->device($userAgent),
                null,
                null,
            ];
        } else {
            $row = [
                'engagement',
                null,
                null,
                $viewId,
                null,
                null,
                $this->intRange($beacon['s'] ?? null, 0, 100),
                $this->intRange($beacon['d'] ?? null, 0, self::MAX_DWELL_MS),
            ];
        }
        $row[] = gmdate('Y-m-d H:i:s');

        $this->db->query(
            'INSERT INTO ' . AnalyticsSchema::TABLE
            . ' (event_type, path, referrer_host, view_id, visitor_hash, device, scroll_pct, dwell_ms, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $row,
        );

        return true;
    }

    private function visitorHash(?string $ip, ?string $userAgent): string
    {
        $dailySalt = hash_hmac('sha256', gmdate('Y-m-d'), $this->secret);

        return hash('sha256', $dailySalt . '|' . ($ip ?? '') . '|' . ($userAgent ?? ''));
    }

    private function device(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i', $userAgent) === 1) {
            return 'tablet';
        }
        if (preg_match('/Mobi|iPhone|iPod|Windows Phone|BlackBerry|IEMobile/i', $userAgent) === 1) {
            return 'mobile';
        }

        return 'desktop';
    }

    private function refHost(mixed $referrer): ?string
    {
        if (!is_string($referrer) || $referrer === '') {
            return null;
        }
        $host = parse_url($referrer, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? substr($host, 0, 255) : null;
    }

    private function str(mixed $value, int $max): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return substr($value, 0, $max);
    }

    private function intRange(mixed $value, int $min, int $max): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }
        $n = (int) $value;

        return max($min, min($max, $n));
    }
}
