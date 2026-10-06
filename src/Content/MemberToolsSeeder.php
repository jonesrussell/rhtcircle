<?php

declare(strict_types=1);

namespace App\Content;

use App\Petition\PetitionRepository;
use App\Poll\PollRepository;

/** Explicit legacy member-tool seed operation; never run during HTTP boot. */
final class MemberToolsSeeder
{
    public function __construct(
        private readonly PetitionRepository $petitions,
        private readonly PollRepository $polls,
    ) {}

    public function seed(): void
    {
        $this->polls->ensurePoll(
            'sagamok-what-matters',
            'Sagamok members: what matters most to you right now? What should our leadership be focused on?',
            [
                'Housing, on-reserve and for off-reserve members',
                'Knowing where our settlement money goes, and what reaches members',
                'Care for our Elders and health close to home',
                'More member say in decisions, real consultation and community meetings',
                'Jobs and support for member-owned businesses',
                'Publishing council minutes, financials, and decisions openly',
                'Language, culture, and our youth',
                "Protecting our members' personal information",
                'Ending conflicts of interest, the same few people on all the boards',
            ],
        );
        // Second Sagamok poll: two yes/no/not-sure questions about Chief
        // and Council meetings, grouped onto one page (PollController::
        // pageMulti) as two independent poll rows sharing the vote
        // endpoint and cookie mechanism above.
        $this->polls->ensurePoll(
            'sagamok-poll-meetings-posted',
            'Should Sagamok keep the Chief and Council meeting schedule and minutes current and posted on the Nation\'s website, so any member can see when Council meets and what was decided?',
            ['Yes', 'No', 'Not sure'],
        );
        $this->polls->ensurePoll(
            'sagamok-poll-evening-meetings',
            'Should Council hold some meetings in the evening, alternating with daytime meetings, so members who work during the day can attend and be heard?',
            ['Yes', 'No', 'Not sure'],
        );
        // Anishinaabemowin lookup cache (Minoo language API). Ensured here on
        // the persistent file for the same reason as the petition below.
        $repo = $this->petitions;
        $repo->ensureCampaign(
            'records-request-support',
            'Support the member records request',
            'We, the undersigned members of Sagamok Anishnawbek, support the records request submitted to Chief and Council. We want clear answers, on the record, to one question: when the Nation invests in businesses and ventures, what are the benefits to the membership, and who is being served? We ask Council to provide the records and respond to the membership.',
            'Sagamok Chief and Council',
        );
        // online_base stays 0: the real online sign-ons were migrated from
        // oiatc as rows, so they carry the live count themselves. The paper
        // count + its dated provenance note are carried over from oiatc
        // (aggregate only, no PII). Bump the paper count as more are handed
        // in.
        $repo->setOnlineBase('records-request-support', 0);
        $repo->setPaperCount(
            'records-request-support',
            39,
            'Paper signatures handed to the Sagamok band office: 16 on June 15, 2026, 10 on June 22, 2026, and 3 on June 25, 2026. Plus 10 members who signed on paper and asked to be counted only, not named, accounted on June 25, 2026.',
        );

        // "Account, or Resign": a separate campaign/slug from records-request-support,
        // sharing the same petition infrastructure (see CLAUDE.md's 2026-07-11
        // exception for this one page). New campaign, no carried-over paper/online
        // base; it starts at zero and counts online sign-ons from here.
        $repo->ensureCampaign(
            'account-or-resign',
            'Account, or Resign: a member statement of no confidence',
            'We, the undersigned members of Sagamok Anishnawbek, declare that we have lost confidence in the current Chief and Council, and we call on them to account fully to the members within thirty days, or resign.',
            'Sagamok Chief and Council',
        );
        // The July 23 member resolution is a new consent instrument. Keep
        // the earlier Account-or-Resign signatures attached to their exact
        // original statement; never carry them into this campaign.
        $repo->ensureCampaign(
            'sagamok-accountability-resolution-2026',
            'Sagamok Members\' Accountability Resolution',
            'I support the seven requested actions in the Sagamok Members\' Accountability Resolution displayed at rhtcircle.ca/communities/sagamok/member-accountability-resolution.',
            'Sagamok Chief and Council',
        );
        $repo->setCampaignDetails(
            'sagamok-accountability-resolution-2026',
            'Sagamok Members\' Accountability Resolution',
            'I support the seven requested actions in the Sagamok Members\' Accountability Resolution displayed at rhtcircle.ca/communities/sagamok/member-accountability-resolution.',
            'Sagamok Chief and Council',
        );
    }
}
