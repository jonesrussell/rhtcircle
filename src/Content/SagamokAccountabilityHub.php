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
        'start-here' => [
            'eyebrow' => 'Requests, responses and member action',
            'title' => 'Start here',
            'intro' => 'The central requests members have made, the answers still outstanding, and practical ways to take part.',
            'hrefs' => [
                '/communities/sagamok/awaiting-council',
                '/communities/sagamok/member-accountability-resolution',
                '/standard/records-request',
                '/communities/sagamok/write-to-council',
                '/communities/sagamok/conflict-register',
            ],
        ],
        'follow-the-record' => [
            'eyebrow' => 'Public records and member explainers',
            'title' => 'Follow the record',
            'intro' => 'Worked examples drawn from public records, organized by the decision or system members are trying to understand.',
            'hrefs' => [
                '/communities/sagamok/gr-truss',
                '/communities/sagamok/one-seat-one-salary',
                '/communities/sagamok/it-accountability',
                '/communities/sagamok/how-its-organized',
                '/communities/sagamok/long-term-care',
                '/communities/sagamok/play-limited-partnership',
                '/communities/sagamok/espanola-mill-bmi',
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
    public static function groups(array $signatures): array
    {
        $cardsByHref = [];
        foreach (CommunityHub::sagamokAccountabilityCards($signatures) as $card) {
            $cardsByHref[(string) $card['href']] = $card;
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
            'feature' => true,
            'tag' => 'Dedicated Sagamok section',
            'title' => 'Sagamok member accountability',
            'desc' => 'Records, unanswered questions, business decisions, member tools and ways to act, now organized in one place.',
            'go' => 'Open the accountability section',
            'href' => '/communities/sagamok/accountability',
        ];
    }
}
