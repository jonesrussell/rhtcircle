<?php

declare(strict_types=1);

namespace App\Access;

use App\Publishing\ArticleContentType;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityInterface;

#[PolicyAttribute(entityType: 'node')]
final class ArticlePublisherAccessPolicy implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return $entity->bundle() === 'article'
            && in_array($operation, ['view', 'view_revision'], true)
            && $account->hasPermission(ArticleContentType::CAPABILITY)
            ? AccessResult::allowed('Article publisher may read article drafts and revisions.')
            : AccessResult::neutral('No article publishing read grant.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral('Creation uses the framework bundle permission.');
    }

    public function protectedEntityReadPolicy(): ?ProtectedEntityReadPolicyInterface
    {
        return new ArticlePublisherReadPolicy();
    }

    public function protectedFieldReadPolicy(): ?ProtectedFieldReadPolicyInterface
    {
        return null;
    }
}
