<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Cms\ArticleRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dynamic /sitemap.xml: the hand-authored page set (a curated constant, the
 * honest representation of the plain-Twig editorial layer) plus every
 * PUBLISHED article straight from the CMS listing. Replaces the hand-edited
 * public/sitemap.xml, which had already drifted from the published set.
 * Publishing or unpublishing an article changes the sitemap with no file
 * edit, commit, or deploy.
 */
final readonly class SitemapController
{
    private const string BASE_URL = 'https://rhtcircle.ca';

    /** Hand-authored (non-CMS) pages. */
    private const array STATIC_PATHS = [
        '/',
        '/treaty',
        '/treaty/distribution-models',
        '/treaty/language',
        '/treaty/settlement-where-it-goes',
        '/myth-versus-record',
        '/news',
        '/communities',
        '/communities/batchewana',
        '/communities/garden-river',
        '/communities/thessalon',
        '/communities/mississauga',
        '/communities/serpent-river',
        '/communities/sagamok',
        '/communities/atikameksheng',
        '/communities/wahnapitae',
        '/communities/nipissing',
        '/communities/dokis',
        '/communities/henvey-inlet',
        '/communities/magnetawan',
        '/communities/shawanaga',
        '/communities/wasauksing',
        '/communities/aundeck-omni-kaning',
        '/communities/whitefish-river',
        '/communities/mchigeeng',
        '/communities/sheguiandah',
        '/communities/sheshegwaning',
        '/communities/wiikwemkoong',
        '/communities/zhiibaahaasing',
        '/communities/sagamok/awaiting-council',
        '/communities/sagamok/how-its-organized',
        '/communities/sagamok/members-website-issue',
        '/communities/sagamok/where-your-data-lives',
        '/communities/sagamok/long-term-care',
        '/community-life/indigenous-baseball-league',
        '/land',
        '/land/massey-solar-project',
        '/land/massey-solar-project/what-youve-heard',
        '/land/massey-solar-project/voices',
        '/land/massey-solar-project/climate',
        '/land/dunns-valley-solar',
        '/land/henvey-inlet-wind',
        '/land/okikendawt-hydro',
        '/land/north-shore-link-northeast-power-line',
        '/land/elliot-lake-uranium',
        '/land/nwmo-nuclear-waste',
        '/land/atikameksheng-land-claim',
        '/land/wiikwemkoong-islands-claim',
        '/safety',
        '/safety/get-help-now',
        '/safety/emergency-preparedness',
        '/safety/missing-persons-and-mmiwg',
        '/safety/harm-reduction',
        '/safety/protecting-elders',
        '/safety/information-safety',
        '/safety/hate-and-extremism',
        '/resources',
        '/treaty-wide',
        '/standard',
        '/standard/records-request',
        '/circle',
        '/about',
        '/get-involved',
    ];

    public function __construct(private ArticleRepository $articles) {}

    public function sitemap(): Response
    {
        $urls = [];
        foreach (self::STATIC_PATHS as $path) {
            $urls[] = ['loc' => self::BASE_URL . ($path === '/' ? '/' : $path)];
        }
        foreach ($this->articles->published() as $article) {
            $urls[] = [
                'loc' => self::BASE_URL . (string) ($article['href'] ?? ''),
                'lastmod' => (string) ($article['date_iso'] ?? ''),
            ];
        }

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($urls as $url) {
            if (($url['loc'] ?? '') === self::BASE_URL) {
                continue; // defensive: skip malformed article rows
            }
            $xml->startElement('url');
            $xml->writeElement('loc', $url['loc']);
            if (($url['lastmod'] ?? '') !== '') {
                $xml->writeElement('lastmod', $url['lastmod']);
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return new Response($xml->outputMemory(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
