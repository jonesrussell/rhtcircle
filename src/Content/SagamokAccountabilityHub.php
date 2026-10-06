<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Publication structure for the dedicated Sagamok accountability desk.
 *
 * The card copy stays canonical in CommunityHub while this class groups the
 * worked records by what a member is trying to do.
 */
final class SagamokAccountabilityHub
{
    /** @var array<string, array{eyebrow: string, title: string, intro: string, hrefs: list<string>}> */
    private const array GROUPS = [
        'open-questions' => [
            'eyebrow' => 'Requests and responses',
            'title' => 'Open questions and records requests',
            'intro' => 'What members have asked, what responses are available and what remains unresolved. An unanswered request is not a finding of wrongdoing.',
            'hrefs' => [
                '/communities/sagamok/awaiting-council',
                '/standard/records-request',
            ],
        ],
        'follow-the-record' => [
            'eyebrow' => 'Public records and member explainers',
            'title' => 'Reporting and public-record explainers',
            'intro' => 'Published reporting and explainers about decisions, services and enterprises. Read each page’s sources, dates and limits.',
            'hrefs' => [
                '/news/sagamok-trespass-bylaw-session-was-backwards',
                '/news/sagamok-south-market-land-deal',
                '/news/sagamok-membership-before-trespass',
                '/news/waasmoowin-deal-public-record',
                '/news/inside-sagamoks-gr-truss-deal',
                '/communities/sagamok/gr-truss',
                '/communities/sagamok/one-seat-one-salary',
                '/communities/sagamok/it-accountability',
                '/communities/sagamok/how-its-organized',
                '/communities/sagamok/long-term-care',
                '/communities/sagamok/play-limited-partnership',
                '/communities/sagamok/espanola-mill-bmi',
                '/communities/sagamok/conflict-register',
            ],
        ],
        'member-proposals' => [
            'eyebrow' => 'Member-authored advocacy',
            'title' => 'Member proposals and statements',
            'intro' => 'Working proposals and advocacy submitted by members. These are not adopted Council policy, election endorsements or the conclusions of a news investigation.',
            'hrefs' => [
                '/communities/sagamok/members-first-plan',
                '/communities/sagamok/member-accountability-resolution',
                '/communities/sagamok/member-election-law',
                '/communities/sagamok/booklets',
            ],
        ],
        'member-tools' => [
            'eyebrow' => 'Polls, privacy and shareable tools',
            'title' => 'Member tools',
            'intro' => 'Low-barrier ways to record priorities, understand digital systems and help other members find the work.',
            'hrefs' => [
                '/communities/sagamok/what-matters',
                '/communities/sagamok/poll',
                '/communities/sagamok/support-images',
                '/communities/sagamok/members-website-issue',
                '/communities/sagamok/where-your-data-lives',
                '/communities/sagamok/write-to-council',
            ],
        ],
    ];

    /**
     * @param array{total: int, online: int, paper: int} $signatures
     *
     * @return list<array{
     *   id: string,
     *   eyebrow: string,
     *   title: string,
     *   intro: string,
     *   cards: list<array<string, string|bool>>
     * }>
     */
    public static function groups(array $signatures, array $articles = []): array
    {
        $cardsByHref = [];
        foreach (CommunityHub::sagamokAccountabilityCards($signatures) as $card) {
            $cardsByHref[(string) $card['href']] = $card;
        }
        foreach ($articles as $article) {
            $href = (string) ($article['href'] ?? '');
            if ($href === '') {
                continue;
            }
            $cardsByHref[$href] = [
                'feature' => false,
                'tag' => (string) ($article['kicker'] ?? 'RHT Circle reporting'),
                'title' => (string) ($article['title'] ?? ''),
                'desc' => (string) ($article['summary'] ?? $article['deck'] ?? ''),
                'go' => (string) ($article['action'] ?? 'Read the article'),
                'href' => $href,
            ];
        }

        $groups = [];
        foreach (self::GROUPS as $id => $group) {
            $cards = [];
            foreach ($group['hrefs'] as $href) {
                if (isset($cardsByHref[$href])) {
                    $cards[] = $cardsByHref[$href];
                }
            }

            $groups[] = [
                'id' => $id,
                'eyebrow' => $group['eyebrow'],
                'title' => $group['title'],
                'intro' => $group['intro'],
                'cards' => $cards,
            ];
        }

        return $groups;
    }

    /** @return array<string, string|bool> */
    public static function doorway(): array
    {
        return [
            'feature' => false,
            'tag' => 'Dedicated Sagamok section',
            'title' => 'Sagamok member accountability',
            'desc' => 'Browse reporting, open records requests, clearly labelled member proposals and practical tools.',
            'go' => 'Open the accountability section',
            'href' => '/communities/sagamok/accountability',
        ];
    }
}
