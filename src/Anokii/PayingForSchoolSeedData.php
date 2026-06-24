<?php

declare(strict_types=1);

namespace App\Anokii;

/**
 * The "Paying for school" seed: treaty-wide post-secondary and training funding
 * resources for members of all 21 Robinson Huron Treaty nations.
 *
 * Merged into {@see GraphSeedData} so app:seed-graph loads it with the same
 * idempotent, slug-keyed upsert and the same curated-chunk contract as the
 * territory seed.
 *
 * CRITICAL scope: these are treaty-wide, so every service has an EMPTY
 * located_at. In the retriever an empty-place service is "broader" content,
 * reachable from any vantage including the default (treaty-wide) Ask box; a
 * place-scoped service would only show from a community whose region includes
 * that place. The regional ISET holders carry their grouping in the text, not in
 * located_at, so they too stay reachable treaty-wide. Each entry carries its
 * official source URL; year-to-year figures and deadlines are deliberately not
 * frozen (the chunks say to confirm the current year on the official page).
 */
final class PayingForSchoolSeedData
{
    /** Heading on every chunk, so "school" funding questions retrieve them. */
    private const string HEADING = 'Paying for school';

    /**
     * Each resource: [slug, name, topic, source_url, grounding]. topic is an
     * existing vocabulary slug: 'education-youth' (the "education" topic) or
     * 'employment-training' (the "jobs and training" topic).
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function resources(): array
    {
        return [
            ['pay-school-band-pssp', 'Band post-secondary funding (PSSSP / UCEP)', 'education-youth', 'https://www.sac-isc.gc.ca/eng/1100100033682/1531933580211', "Your nation's education office administers federal post-secondary funding that can cover tuition, books, travel, and living costs. You apply through your nation, not the government, and funding is capped, so not every student is funded each year. It does not block OSAP or federal student aid. Amounts and deadlines change year to year; confirm the current year with your nation's education office and the official page."],
            ['pay-school-ucep', 'University and College Entrance Preparation (UCEP)', 'education-youth', 'https://www.sac-isc.gc.ca/eng/1100100033688/1531936422341', 'Federal funding, administered through your nation, for upgrading or entrance-preparation programs that bring you to the level needed for college or university. Confirm the current year on the official page.'],
            ['pay-school-osap', 'OSAP (Ontario Student Assistance Program)', 'education-youth', 'https://www.ontario.ca/page/osap-ontario-student-assistance-program', "Ontario's mix of grants you do not repay and repayable loans for college and university, open to eligible Ontario residents and usable alongside band funding. Apply online and check the current application year."],
            ['pay-school-ontario-indigenous-awards', 'Ontario Indigenous student awards', 'education-youth', 'https://www.ontario.ca/page/osap-for-under-represented-learners', 'Ontario lists an Indigenous Student Bursary for Indigenous students with financial need, and a First Nations Resource Development Scholarship for students in mining-related programs. Confirm the current year on the official page.'],
            ['pay-school-indspire', 'Indspire bursaries and scholarships', 'education-youth', 'https://indspire.ca/apply-now/', 'A national Indigenous charity, separate from your band and from government, with one application covering hundreds of bursaries and scholarships for First Nations, Inuit, and Metis students. It stacks on top of band funding and costs nothing to apply. Deadlines change year to year; confirm this year on their page.'],
            ['pay-school-iset', 'Indigenous Skills and Employment Training (ISET)', 'employment-training', 'https://www.canada.ca/en/employment-social-development/programs/indigenous-skills-employment-training.html', 'Federal funding for skills, trades, and shorter training, delivered by local Indigenous organizations, serving members on and off reserve. It funds things band post-secondary money often will not. Contact your regional holder, or use the official page to find one.'],
            ['pay-school-mamaweswen-isetp', 'Mamaweswen ISETP (North Shore)', 'employment-training', 'https://mamaweswen.com/7946/', 'Mamaweswen, The North Shore Tribal Council, delivers ISET employment and training funding for the North Shore nations, including Sagamok Anishnawbek, on and off reserve.'],
            ['pay-school-mnidoo-mnising-iset', 'Mnidoo Mnising Employment and Training (Manitoulin)', 'employment-training', 'http://www.uccmm.ca/mnidoo-mnising-employment--training.html', 'Mnidoo Mnising Employment and Training delivers Indigenous employment and training services for the UCCMM nations on Manitoulin Island.'],
            ['pay-school-mno-education-training', 'Metis Nation of Ontario, Education and Training', 'employment-training', 'https://www.metisnation.org/programs-and-services/education-training/metis-employment-programs/', 'Employment and training programs for registered Metis citizens in Ontario, including the Robinson Huron Treaty region.'],
            ['pay-school-apprenticeship', 'Ontario apprenticeship and trades', 'employment-training', 'https://www.ontario.ca/page/start-apprenticeship', 'You learn a trade as a paid apprentice: you work, attend in-class training, and earn a certificate. Ontario offers a tools grant and the Apprentice Development Benefit for living and travel costs during in-class training, and the federal Canada Apprentice Loan offers interest-free loans. The older federal Apprenticeship Incentive and Completion Grants closed in March 2025, so ignore older guides that mention them.'],
            ['pay-school-bursary-search', 'Indigenous Bursaries Search Tool', 'education-youth', 'https://www.sac-isc.gc.ca/eng/1351185180120/1351685455328', 'A free federal search tool listing bursaries, scholarships, and incentives for Indigenous students from across Canada. Worth a scan before you assume there is nothing for your situation.'],
        ];
    }

    /**
     * Service rows: treaty-wide (empty located_at) so they surface from the
     * default vantage, tagged with the existing education / jobs-and-training
     * topics.
     *
     * @return list<array<string, string>>
     */
    public static function services(): array
    {
        $rows = [];
        foreach (self::resources() as [$slug, $name, $topic, $url]) {
            $rows[] = ['slug' => $slug, 'name' => $name, 'provided_by' => '', 'located_at' => '', 'has_topic' => $topic, 'source_url' => $url];
        }

        return $rows;
    }

    /**
     * Curated grounding chunks, keyed by the curated prefix so app:ingest never
     * prunes them, linked to their service.
     *
     * @return list<array<string, string>>
     */
    public static function curatedChunks(): array
    {
        $rows = [];
        foreach (self::resources() as [$slug, $name, , $url, $grounding]) {
            $rows[] = [
                'chunk_key' => GraphSeedData::CURATED_KEY_PREFIX . $slug,
                'source_url' => $url,
                'title' => $name,
                'heading' => self::HEADING,
                'text' => $grounding,
                'entity_type' => 'service',
                'entity_id' => $slug,
            ];
        }

        return $rows;
    }
}
