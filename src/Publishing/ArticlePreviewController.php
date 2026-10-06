<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Cms\ArticleRepository;
use App\Rendering\SiteRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Publishing\Preview\PreviewLinkService;

/**
 * Signed draft preview: renders the WORKING COPY of an article through the
 * real news article layout, gated by a short-lived HMAC grant issued through
 * the MCP `article.preview` tool. Invalid, expired, or tampered grants
 * collapse to 404 (no existence oracle); every response is noindex.
 */
final readonly class ArticlePreviewController
{
    public function __construct(
        private EntityTypeManager $entityTypeManager,
        private ArticleRepository $articles,
        private SiteRenderer $renderer,
        private PreviewLinkService $previewLinks,
    ) {}

    public function preview(Request $request, string $nid): Response
    {
        $exp = (int) $request->query->get('exp', 0);
        $sig = (string) $request->query->get('sig', '');
        if ($nid === '' || !ctype_digit($nid) || !$this->previewLinks->verify('node', $nid, $exp, $sig)) {
            return $this->notFound($nid);
        }

        // The working copy is the draft tip (falls back to the served row).
        $node = $this->entityTypeManager->getRepository('node')->loadWorkingCopy($nid);
        if ($node === null || $node->bundle() !== \App\Cms\ArticleFields::BUNDLE) {
            return $this->notFound($nid);
        }

        $response = $this->renderer->html('pages/news/article.html.twig', [
            'article' => $this->articles->view($node),
            'noindex' => true,
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function notFound(string $nid): Response
    {
        $response = $this->renderer->html('404.html.twig', ['path' => '/news/preview/' . $nid], 404);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
