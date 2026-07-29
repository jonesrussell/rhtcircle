<?php

declare(strict_types=1);

namespace App\Publishing;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Waaseyaa\Publishing\ContentHtmlSanitizerInterface;

/**
 * The explicit editorial allowlist for article HTML fields, behind the
 * framework's sanitizer contract. Derived from the markup the news layout
 * actually renders; anything else is stripped BEFORE persistence. `class`
 * survives on structural elements because the article/sidebar/sources
 * partials style through classes; no event handlers, no scripts, no styles,
 * no iframes — ever.
 */
final readonly class ArticleHtmlSanitizer implements ContentHtmlSanitizerInterface
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = new HtmlSanitizerConfig()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->forceHttpsUrls();

        foreach (['p', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'figure', 'figcaption', 'aside', 'div', 'section', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'strong', 'em', 'span', 'small', 'cite'] as $element) {
            $config = $config->allowElement($element, ['class']);
        }
        $config = $config
            ->allowElement('a', ['href', 'class', 'rel', 'title'])
            ->allowElement('img', ['src', 'alt', 'width', 'height', 'loading', 'class'])
            ->allowElement('br');

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
