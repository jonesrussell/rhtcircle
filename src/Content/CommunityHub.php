<?php

declare(strict_types=1);

namespace App\Content;

/**
 * The view context for a community hub page (templates/pages/communities/nation.html.twig),
 * built from one source so the live page (SiteController) and the chat index
 * (IngestCommand) stay in sync.
 *
 * It supplies the hero lede, the two intro lines, and the two card grids:
 *  - transparency: member transparency resources. Only Sagamok has worked
 *    examples so far, led by the growing "Questions awaiting an answer" list;
 *    every other nation gets an empty list, and the template renders the
 *    "bring the shared standard home" invitation card.
 *  - territory: built from the land projects that touch the nation (a project
 *    belongs to a nation when the slug is in the project's "related" list), so
 *    the North Shore Link partners, the energy host nations, and the land-claim
 *    nations each pick up the right cards. Sagamok keeps its richer set. A nation
 *    with no land item gets an empty list and the land-section pointer card.
 *
 * Cards are framed as questions, not accusations, and name no private
 * individuals (see CLAUDE.md guardrails).
 */
final class CommunityHub
{
    /**
     * @param array<string, mixed> $nation
     *
     * @return array{transparency: list<array<string, string|bool>>, territory: list<array<string, string|bool>>, community_life: list<array<string, string|bool>>, lede: string, tsub: string}
     */
    public static function context(string $slug, array $nation): array
    {
        $transparency = self::transparencyCards($slug);

        return [
            'transparency' => $transparency,
            'territory' => self::territoryCards($slug),
            'community_life' => self::communityLife($slug),
            'lede' => self::lede((string) $nation['name'], $transparency !== []),
            'tsub' => $slug === 'sagamok'
                ? 'Sagamok is where the shared standard became a worked example. These pages are members putting that standard into practice. They are the work of members, not of Chief and Council.'
                : 'The shared standard, the same fair questions every member can put to their own Chief and Council, is ready to be brought home here.',
        ];
    }

    /** @return list<array<string, string|bool>> */
    private static function transparencyCards(string $slug): array
    {
        if ($slug !== 'sagamok') {
            return [];
        }

        return [
            [
                'tag' => 'An informal member poll',
                'title' => 'What matters most to you right now?',
                'desc' => 'Anonymous, single select: what should our leadership be focused on? No personal data is collected, and results are shown right after you vote.',
                'go' => 'Vote and see the results',
                'href' => '/communities/sagamok/what-matters',
            ],
            [
                'feature' => true,
                'tag' => "A member's public statement",
                'title' => 'A call for the resignation of the Director of IT and Communications',
                'desc' => 'Two serious IT and data failures, raised properly and privately first, met with silence. A member is now asking publicly.',
                'go' => 'Read the statement',
                'href' => '/communities/sagamok/it-accountability',
            ],
            [
                'feature' => true,
                'tag' => 'New, growing list',
                'title' => 'Questions awaiting an answer',
                'desc' => 'A living record of member questions to Chief and Council and the offices, still awaiting a reply, with the date asked and the status. Silence is its own answer.',
                'go' => 'See the list',
                'href' => '/communities/sagamok/awaiting-council',
            ],
            [
                'tag' => 'The standard, in practice',
                'title' => 'The records request',
                'desc' => 'Sixteen member questions about the settlement, the trust, the enterprises, and the benefit to members, carried with 88 plus signatures.',
                'go' => 'Read the request',
                'href' => '/standard/records-request',
            ],
            [
                'tag' => "A member's guide",
                'title' => 'How Sagamok is organized',
                'desc' => 'Governance, administration, and the enterprises, mapped in plain terms so the questions have somewhere to land.',
                'go' => 'See the guide',
                'href' => '/communities/sagamok/how-its-organized',
            ],
            [
                'tag' => 'Reported and resolved',
                'title' => 'The members website issue',
                'desc' => 'A responsible disclosure of a members-portal exposure, reported and since resolved, kept here as a record.',
                'go' => 'Read what happened',
                'href' => '/communities/sagamok/members-website-issue',
            ],
            [
                'tag' => 'A member explainer',
                'title' => 'Where your data actually lives',
                'desc' => 'In plain terms: the US platforms the Nation\'s site runs on, where member data goes, which laws can reach it, and what data sovereignty really means.',
                'go' => 'Read the explainer',
                'href' => '/communities/sagamok/where-your-data-lives',
            ],
            [
                'tag' => 'Questions for Chief and Council',
                'title' => 'The long-term care MOU',
                'desc' => 'Member questions about the Espanola long-term care MOU: what was authorized, by whom, and on what terms.',
                'go' => 'See the questions',
                'href' => '/communities/sagamok/long-term-care',
            ],
        ];
    }

    /** @return list<array<string, string|bool>> */
    private static function territoryCards(string $slug): array
    {
        if ($slug === 'sagamok') {
            return [
                [
                    'tag' => 'On Sagamok territory',
                    'title' => 'The Massey solar project',
                    'desc' => 'A proposed solar development on the territory: what is known, what members are hearing, and the questions it raises.',
                    'go' => 'Open the profile',
                    'href' => '/land/massey-solar-project',
                ],
                [
                    'tag' => 'Sagamok is a Waasmoowin partner',
                    'title' => 'North Shore Link and Northeast Power Line',
                    'desc' => 'Two new Hydro One transmission lines, with eight RHT nations participating together as equity partners through Waasmoowin.',
                    'go' => 'Open the profile',
                    'href' => '/land/north-shore-link-northeast-power-line',
                ],
                [
                    'tag' => 'Help, MMIWG, and safety',
                    'title' => 'Community safety',
                    'desc' => 'Help, MMIWG, and safety in the territory: where to turn and what members are entitled to ask for.',
                    'go' => 'Go to safety',
                    'href' => '/safety',
                ],
            ];
        }

        $cards = [];
        foreach (LandProjects::all() as $project) {
            foreach (($project['related'] ?? []) as $related) {
                if (($related['slug'] ?? null) === $slug) {
                    $cards[] = [
                        'tag' => (string) $project['type_label'],
                        'title' => (string) $project['name'],
                        'desc' => (string) $project['lead'],
                        'go' => 'Open the profile',
                        'href' => '/land/' . (string) $project['slug'],
                    ];
                    break;
                }
            }
        }

        return $cards;
    }

    /**
     * Community-life features shared across nations. The Maamwesying and Jays Care
     * youth baseball league spans Sagamok, Serpent River, and Atikameksheng, so it
     * is featured on each of their pages and nowhere else.
     *
     * @return list<array<string, string|bool>>
     */
    private static function communityLife(string $slug): array
    {
        if (!in_array($slug, ['sagamok', 'serpent-river', 'atikameksheng'], true)) {
            return [];
        }

        return [
            [
                'feature' => true,
                'tag' => 'Community life, summer 2026',
                'title' => 'Indigenous Baseball League 2026',
                'desc' => 'A summer youth baseball season run by Maamwesying and the Jays Care Foundation, played across Sagamok, Serpent River, and Atikameksheng. The home schedule, how kids earn points, and who to ask.',
                'go' => 'See the league page',
                'href' => '/community-life/indigenous-baseball-league',
            ],
        ];
    }

    private static function lede(string $name, bool $hasTransparency): string
    {
        if ($hasTransparency) {
            return 'A member-compiled profile of ' . $name . ', and the hub for the parts of this resource that touch the community: the shared transparency standard applied here, the records request, how the Nation is organized, and the land decisions on the territory.';
        }

        return 'A member-compiled profile of ' . $name . ', and a doorway into the parts of this resource that touch the community. The shared transparency standard is ready for members here whenever they choose to bring it home.';
    }
}
