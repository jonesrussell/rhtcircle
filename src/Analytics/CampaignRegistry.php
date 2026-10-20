<?php

declare(strict_types=1);

namespace App\Analytics;

/** Only editorially registered campaign labels may enter analytics. */
final class CampaignRegistry
{
    public const ELECTION_PATH = '/elections/sables-spanish-rivers-2026';
    public const FACEBOOK = 'ssr26-facebook-final-week';
    public const VERIFICATION = 'ssr26-release-check';

    /** @return array<string, array{label:string, path:string, source:string, medium:string, campaign:string, content:string, test:bool}> */
    public static function all(): array
    {
        return [
            self::FACEBOOK => [
                'label' => 'Election guide: final-week Facebook post',
                'path' => self::ELECTION_PATH,
                'source' => 'facebook',
                'medium' => 'social',
                'campaign' => 'ssr-election-2026',
                'content' => 'final-week',
                'test' => false,
            ],
            self::VERIFICATION => [
                'label' => 'Release verification (excluded from campaign totals)',
                'path' => self::ELECTION_PATH,
                'source' => 'internal',
                'medium' => 'verification',
                'campaign' => 'ssr-election-2026',
                'content' => 'release-check',
                'test' => true,
            ],
        ];
    }

    public static function known(mixed $key): ?string
    {
        return is_string($key) && isset(self::all()[$key]) ? $key : null;
    }
}
