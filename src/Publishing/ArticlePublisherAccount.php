<?php

declare(strict_types=1);

namespace App\Publishing;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * The machine principal behind the MCP publisher bearer token.
 *
 * Holds ONLY the article publish capability (least privilege) with a fixed
 * high sentinel uid (never colliding with real auto-increment uids or the
 * framework sentinels 0 / PHP_INT_MAX). Revision authorship and audit actor
 * columns record this uid for every agent-driven content mutation.
 */
final readonly class ArticlePublisherAccount implements AuthorizationPrincipalInterface
{
    public const int UID = 910000001;

    public function id(): int
    {
        return self::UID;
    }

    public function hasPermission(string $permission): bool
    {
        return $permission === ArticleContentType::CAPABILITY;
    }

    public function getRoles(): array
    {
        return ['article_publisher'];
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function claimsGeneration(): string
    {
        return 'rhtcircle-article-publisher-v1';
    }

    public function tenantId(): ?string
    {
        return null;
    }

    public function communityId(): ?string
    {
        return null;
    }
}
