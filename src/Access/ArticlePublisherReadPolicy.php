<?php

declare(strict_types=1);

namespace App\Access;

use App\Publishing\ArticleContentType;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

/** Article editors may read article drafts/revisions, without node administration. */
final class ArticlePublisherReadPolicy implements ProtectedEntityReadPolicyInterface
{
    public function access(AuthorizationPrincipalInterface $principal, EntityStructure $structure, PolicySubjectViewInterface $subject, string $operation): AccessResult
    {
        return $structure->entityTypeId === 'node'
            && $structure->bundleId === 'article'
            && in_array($operation, ['view', 'view_revision'], true)
            && $principal->hasPermission(ArticleContentType::CAPABILITY)
            ? AccessResult::allowed('Article publisher may read article drafts and revisions.')
            : AccessResult::neutral('No article publishing read grant.');
    }
}
