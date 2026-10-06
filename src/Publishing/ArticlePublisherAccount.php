<?php

declare(strict_types=1);

namespace App\Publishing;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * The machine principal behind the MCP publisher bearer token.
 *
 * Holds the article capability and its required bundle-scoped framework grants
 * (no node/media administration or other content bundles), with a fixed
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
        return in_array($permission, [
            ArticleContentType::CAPABILITY,
            \Waaseyaa\Node\NodePermissions::ACCESS_CONTENT,
            \Waaseyaa\Node\NodePermissions::create('article'),
            \Waaseyaa\Node\NodePermissions::editAny('article'),
            \Waaseyaa\Node\NodeAccessPolicy::PUBLISH_PERMISSION,
            \Waaseyaa\Media\MediaPermissions::ACCESS,
            \Waaseyaa\Media\MediaPermissions::VIEW_OWN_UNPUBLISHED,
            \Waaseyaa\Media\MediaPermissions::create('image'),
        ], true);
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
        return 'rhtcircle-article-publisher-v2';
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
