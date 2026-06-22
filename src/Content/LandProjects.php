<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Developments and decisions on Robinson Huron Treaty territory, as member-compiled,
 * unofficial, sourced profiles. Content layer for the /land/<slug> pages: the
 * SiteController renders each through one shared template (pages/land/project.html.twig)
 * so the member-compiled banner and "tell us what we got wrong" note are carried by
 * the template, not duplicated per page.
 *
 * Hard rule (see ../../../RHT/land/README.md): neutral territory facts only, no vendor
 * or consultant pitch. The North Shore Link / Northeast Power Line profile covers the
 * lines and the Waasmoowin First Nations equity partnership as public facts only; any
 * commercial venture selling into those lines stays entirely off the hub.
 *
 * Massey Solar is NOT here: it keeps its richer flagship cluster at
 * /land/massey-solar-project. The /land index links to both.
 *
 * Researched 2026-06-22. ALL capacities, costs, ownership splits, and dates are
 * point-in-time; each profile shows them with their source and flags figures to
 * verify before relying on them.
 */
final class LandProjects
{
    /** Display order on the /land index, grouped by type label. */
    public static function typeOrder(): array
    {
        return ['Energy', 'Transmission', 'Environment', 'Land claim'];
    }

    /** @return array<int, array<string, mixed>> all profiles in display order */
    public static function all(): array
    {
        return self::rows();
    }

    /** @return array<string, mixed>|null one profile by slug, or null if unknown */
    public static function find(string $slug): ?array
    {
        foreach (self::rows() as $row) {
            if ($row['slug'] === $slug) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function rows(): array
    {
        return [
            [
                'slug' => 'dunns-valley-solar',
                'name' => 'Dunns Valley Solar',
                'type_label' => 'Energy',
                'status' => 'Proposed; 20-year contract awarded April 2026, construction from 2028, online around 2030',
                'location' => "Dunns Valley, within Garden River First Nation's treaty reserve area, about 45 minutes east of Sault Ste. Marie",
                'nations' => 'Garden River First Nation (50 percent equity)',
                'lead' => 'A proposed solar farm on Garden River First Nation land, set to be one of the largest in Ontario, with the nation holding half the equity.',
                'body' => <<<'HTML'
<p>Dunns Valley Solar is a proposed solar facility of about 200 MWac (253 MWp) on Garden River First Nation's treaty reserve area, set to be one of the largest solar facilities in Ontario. It is developed with Neoen, with Garden River First Nation holding a 50 percent equity stake.</p>
<p>The project was awarded a 20-year contract through the Independent Electricity System Operator's Long-Term 2 procurement in April 2026, with construction expected from 2028 and operation around 2030. Reporting cites a roughly $1 billion project and around $50 million a year in projected revenue for Garden River once operating.</p>
HTML,
                'caveat' => 'Capacity, cost, the ownership split, and the revenue figure are point-in-time and drawn from public reporting. Verify the current figures before relying on them.',
                'related' => [
                    ['slug' => 'garden-river', 'name' => 'Garden River First Nation'],
                ],
                'sources' => [
                    ['label' => 'CBC, Garden River solar farm at Dunns Valley', 'url' => 'https://www.cbc.ca/news/canada/sudbury/garden-river-solar-farm-dunns-valley-9.7171830'],
                    ['label' => 'Neoen, Long-Term 2 contract awards', 'url' => 'https://neoen.com/en/news/2026/neoen-awarded-long-term-contracts-for-2-new-solar-farms-totalling-318-mwp-in-ontario-canada/'],
                ],
            ],
            [
                'slug' => 'henvey-inlet-wind',
                'name' => 'Henvey Inlet Wind',
                'type_label' => 'Energy',
                'status' => 'Operating since September 2019',
                'location' => 'Henvey Inlet First Nation, northeast Georgian Bay',
                'nations' => 'Henvey Inlet First Nation, through its wholly owned Nigig Power Corporation',
                'lead' => 'A 300 MW wind facility on Henvey Inlet First Nation land, described as the largest First Nation wind-energy partnership in Canada.',
                'body' => <<<'HTML'
<p>Henvey Inlet Wind is a 300 MW wind facility of 87 turbines on Henvey Inlet First Nation land, described as the largest First Nation wind-energy partnership in Canada. It was developed through the nation's wholly owned Nigig Power Corporation in partnership with Pattern, with about $1 billion in financing, construction from late 2017, and commercial operation since September 2019. Power is sold to the Independent Electricity System Operator under a 20-year agreement.</p>
<p>The project was structured as a 50-50 partnership at completion. Pattern later announced an agreement to acquire the other half, so the current ownership split is worth confirming before relying on it.</p>
HTML,
                'caveat' => 'The current ownership split should be verified: the project began as a 50-50 partnership, and a later acquisition was announced.',
                'related' => [
                    ['slug' => 'henvey-inlet', 'name' => 'Henvey Inlet First Nation'],
                ],
                'sources' => [
                    ['label' => 'Pattern Energy, Henvey Inlet Wind', 'url' => 'https://patternenergy.com/projects/henvey-inlet-wind/'],
                    ['label' => 'Pattern, largest First Nation wind project completed', 'url' => 'https://patternenergy.com/pattern-development-and-henvey-inlet-first-nation-complete-largest-first-nation-wind-project-in-canada/'],
                ],
            ],
            [
                'slug' => 'okikendawt-hydro',
                'name' => 'Okikendawt Hydro (French River)',
                'type_label' => 'Energy',
                'status' => 'Operating since 2015',
                'location' => 'Portage Dam, where the French River leaves Lake Nipissing',
                'nations' => 'Dokis First Nation (40 percent equity)',
                'lead' => 'A run-of-river hydroelectric station on the French River, with Dokis First Nation holding a 40 percent stake.',
                'body' => <<<'HTML'
<p>Okikendawt Hydro is a 10 MW run-of-river hydroelectric station of two turbines at the Portage Dam on the French River, where it flows out of Lake Nipissing. Dokis First Nation holds a 40 percent equity stake, with the majority held by its developer partner (originally Hydromega, now operating as FirstLight).</p>
<p>The station has been operating since 2015; the partners marked its tenth anniversary in 2024 to 2025.</p>
HTML,
                'caveat' => 'Confirm the current ownership split and the partner name before relying on them.',
                'related' => [
                    ['slug' => 'dokis', 'name' => 'Dokis First Nation'],
                ],
                'sources' => [
                    ['label' => 'Environment Journal, a decade of partnership on Okikendawt', 'url' => 'https://environmentjournal.ca/celebrating-a-decade-of-partnership-on-okikendawt-hydro-generating-station/'],
                    ['label' => 'Dokis First Nation case study (Business and Human Rights)', 'url' => 'https://media.business-humanrights.org/media/documents/Dokis_First_Nation_case_study.pdf'],
                ],
            ],
            [
                'slug' => 'north-shore-link-northeast-power-line',
                'name' => 'North Shore Link and Northeast Power Line',
                'type_label' => 'Transmission',
                'status' => 'Planning; construction targeted to begin early 2027, completion around 2029',
                'location' => 'North shore, Sault Ste. Marie to Wharncliffe to Greater Sudbury',
                'nations' => 'Eight RHT nations through Waasmoowin: Atikameksheng, Batchewana, Mississauga, Sagamok, Serpent River, Thessalon, Wahnapitae, and Whitefish River',
                'lead' => 'Two new Hydro One transmission lines across the north shore, with eight RHT nations participating together as equity partners through Waasmoowin.',
                'body' => <<<'HTML'
<p>Hydro One is planning two new transmission lines across the north shore. The North Shore Link (NSL) is a 230 kV double-circuit line of about 105 km from the Mississagi Transformer Station near Wharncliffe to Sault Ste. Marie. The Northeast Power Line (NEP) is a 500 kV single-circuit line of about 200 km from the Hanmer Transformer Station in Greater Sudbury to the Mississagi station, costing roughly $1.8 billion and reinforcing transfer capability by about 900 MW. Construction is targeted to begin in early 2027 with completion around 2029. Hydro One filed leave-to-construct applications with the Ontario Energy Board for the Northeast Power Line on May 19, 2026.</p>
<h3>The nations' role</h3>
<p>Under Hydro One's 50-50 First Nation equity partnership model (introduced in 2022 and applied to new transmission lines over $100 million), proximate First Nations can hold a 50 percent equity stake in each line. Eight RHT nations are participating together through Waasmoowin, a collective partnership organized into the Waasmoowin Opportunities and Consultation Council (consultation, lands and waters, jobs and training) and a Waasmoowin General Partner (the equity side), with a board of the partner nations' Chiefs or Councillors and a Council of Elders.</p>
<p>The eight are Atikameksheng Anishnawbek, Batchewana, Mississauga, Sagamok Anishnawbek (a founding signatory), Serpent River, Thessalon, Wahnapitae, and Whitefish River. Garden River First Nation withdrew from Waasmoowin on June 10, 2025.</p>
<p>Equity of this kind is typically financed through loan-guarantee programs: Ontario's program (renamed in 2025 to the Indigenous Opportunities Financing Program, tripled to $3 billion) and Canada's Indigenous Loan Guarantee Program (doubled to $10 billion in March 2025). Which instrument finances the Waasmoowin stakes, and the dollar amounts, are not yet public.</p>
HTML,
                'caveat' => 'Capacities, costs, the construction timeline, and the financing instrument for the Waasmoowin stakes are point-in-time public facts. The dollar amounts of the stakes are not yet public. Verify before relying on any figure.',
                'related' => [
                    ['slug' => 'atikameksheng', 'name' => 'Atikameksheng Anishnawbek'],
                    ['slug' => 'batchewana', 'name' => 'Batchewana First Nation'],
                    ['slug' => 'mississauga', 'name' => 'Mississauga First Nation'],
                    ['slug' => 'sagamok', 'name' => 'Sagamok Anishnawbek'],
                    ['slug' => 'serpent-river', 'name' => 'Serpent River First Nation'],
                    ['slug' => 'thessalon', 'name' => 'Thessalon First Nation'],
                    ['slug' => 'wahnapitae', 'name' => 'Wahnapitae First Nation'],
                    ['slug' => 'whitefish-river', 'name' => 'Whitefish River First Nation'],
                ],
                'sources' => [
                    ['label' => 'Hydro One, Northeast Power Line', 'url' => 'https://www.hydroone.com/about/corporate-information/major-projects/northeast-power-line'],
                    ['label' => 'Hydro One OEB filing (May 19, 2026)', 'url' => 'https://www.newswire.ca/news-releases/hydro-one-seeks-approval-from-the-ontario-energy-board-to-build-the-northeast-power-line-and-the-longwood-to-lakeshore-transmission-line-838198305.html'],
                    ['label' => 'First Nations sign agreement with Hydro One (SooToday)', 'url' => 'https://www.sootoday.com/local-news/true-partnership-first-nations-sign-agreement-with-hydro-one-on-transmission-lines-11925255'],
                    ['label' => 'Hydro One signs agreement with eight First Nations (ElliotLakeToday)', 'url' => 'https://www.elliotlaketoday.com/local-news/hydro-one-signs-agreement-with-eight-first-nations-on-two-northeastern-ontario-transmission-projects-11923404'],
                ],
            ],
            [
                'slug' => 'elliot-lake-uranium',
                'name' => 'Elliot Lake uranium legacy',
                'type_label' => 'Environment',
                'status' => 'Legacy; tailings under perpetual care',
                'location' => 'Elliot Lake area and the Serpent River watershed, north shore',
                'nations' => 'Serpent River First Nation',
                'lead' => 'Decades of uranium mining left radioactive tailings across the Serpent River watershed that remain under perpetual care.',
                'body' => <<<'HTML'
<p>Decades of uranium mining and milling around Elliot Lake (roughly the 1950s to the 1990s) left about 102 million tonnes of radioactive, acid-generating tailings across eight decommissioned mine sites covering around 920 hectares. The Serpent River watershed was contaminated, and a sulphuric acid plant was built directly on Serpent River First Nation's reserve at Cutler, bringing pollution rather than the prosperity that had been promised.</p>
<p>The mines closed by 1996, but the tailings remain perpetual-care sites, and community members have reported ongoing impacts on the land, water, fish, and traditional harvesting. A member of the community, historian Lianne Leddy, documents this history in the book Serpent River Resurgence.</p>
HTML,
                'caveat' => null,
                'related' => [
                    ['slug' => 'serpent-river', 'name' => 'Serpent River First Nation'],
                ],
                'sources' => [
                    ['label' => 'Uranium mining in the Elliot Lake area (overview)', 'url' => 'https://en.wikipedia.org/wiki/Uranium_mining_in_the_Elliot_Lake_area'],
                    ['label' => 'Wilfrid Laurier University, on Serpent River Resurgence', 'url' => 'https://www.wlu.ca/news/spotlights/2023/june/award-winning-book-by-laurier-researcher-documents-destructive-legacy-of-uranium-mining-in-northern-ontario-indigenous-community.html'],
                ],
            ],
            [
                'slug' => 'nwmo-nuclear-waste',
                'name' => 'Nuclear waste repository and transport concern',
                'type_label' => 'Environment',
                'status' => 'Site selected November 2024; regulatory process expected to take 7 to 10 years',
                'location' => 'Repository at the Ignace and Revell site (northwestern Ontario); transport corridors a north-shore concern',
                'nations' => 'North-shore RHT nations through opposition resolutions and transport concern (specific bodies to confirm)',
                'lead' => "Canada's planned deep geological repository for nuclear waste is in northwestern Ontario, but the transport of used fuel across the province is a north-shore concern.",
                'body' => <<<'HTML'
<p>On November 28, 2024, the Nuclear Waste Management Organization (NWMO) selected the Wabigoon Lake Ojibway Nation and Ignace area (the Revell site), about 250 km northwest of Thunder Bay, to host Canada's deep geological repository for used nuclear fuel. That site is in northwestern Ontario, not in Robinson Huron Treaty territory.</p>
<p>The relevance to the north shore is twofold. First Nations, regional Indigenous organizations, and municipalities across the region, including north-shore communities, have passed resolutions opposing the transport, storage, and disposal of nuclear waste through or in the region. And used fuel would be moved across Ontario, with northeastern corridors a basis of concern. The regulatory process is expected to take seven to ten years.</p>
HTML,
                'caveat' => 'The specific north-shore bodies and their resolutions should be confirmed before naming them.',
                'related' => [],
                'sources' => [
                    ['label' => "NWMO, site selected for the deep geological repository", 'url' => 'https://www.nwmo.ca/en/news/the-nuclear-waste-management-organization-selects-site-for-canadas-deep-geological-repository'],
                    ['label' => 'CBC, nuclear waste storage site chosen', 'url' => 'https://www.cbc.ca/news/canada/thunder-bay/nuclear-waste-storage-site-chosen-1.7395660'],
                ],
            ],
            [
                'slug' => 'atikameksheng-land-claim',
                'name' => 'Atikameksheng land claim',
                'type_label' => 'Land claim',
                'status' => 'Cleared to trial (August 2024 ruling); no trial outcome yet',
                'location' => 'West of Sudbury, between the Vermilion and Wahnapitae rivers',
                'nations' => 'Atikameksheng Anishnawbek',
                'lead' => 'Atikameksheng Anishnawbek claims the reserve area set out for it in the 1850 treaty, many times larger than the land it occupies today.',
                'body' => <<<'HTML'
<p>Atikameksheng Anishnawbek (west of Sudbury, formerly Whitefish Lake) claims the reserve area set out for it in the 1850 Robinson Huron Treaty, roughly 2,670 square kilometres, against the roughly 174 square kilometres it occupies today, about fifteen times smaller. The original treaty bounds ran between the Vermilion River and the Wahnapitae River, seven miles inland from the north shore up to Lake Wahnapitae.</p>
<p>On August 29, 2024, an Ontario court set aside an 1889 decision that had shrunk those boundaries, clearing the claim to proceed to trial. The claim had been dismissed on administrative grounds in 2013 and restarted in December 2021. There is no trial outcome yet.</p>
HTML,
                'caveat' => null,
                'related' => [
                    ['slug' => 'atikameksheng', 'name' => 'Atikameksheng Anishnawbek'],
                ],
                'sources' => [
                    ['label' => 'Sudbury.com, Atikameksheng land claim proceeds to trial', 'url' => 'https://www.sudbury.com/local-news/atikameksheng-land-claim-proceeds-to-trial-after-new-decision-9471240'],
                    ['label' => 'Sudbury.com, heads to court to claim 1850 treaty lands', 'url' => 'https://www.sudbury.com/local-news/atikameksheng-anishnawbek-heads-to-court-to-claim-lands-set-out-in-treaty-of-1850-4866165'],
                ],
            ],
            [
                'slug' => 'wiikwemkoong-islands-claim',
                'name' => 'Wiikwemkoong islands boundary claim',
                'type_label' => 'Land claim',
                'status' => 'Negotiations ongoing toward a final settlement',
                'location' => 'Islands off the Wiikwemkoong coast, Georgian Bay and Manitoulin',
                'nations' => 'Wiikwemkoong Unceded Territory',
                'lead' => 'Wiikwemkoong is negotiating the return of islands off its coast that it says were promised in the 1836 agreement.',
                'body' => <<<'HTML'
<p>Wiikwemkoong Unceded Territory claims islands off its coast that it says were promised in the 1836 agreement. Tripartite negotiations among Ontario, Canada, and Wiikwemkoong have continued since 2008, and by 2022 a settlement framework contemplated transferring roughly 10,500 hectares of Crown land, including alternative mainland and Philip Edward Island lands, to be set apart as reserve. Negotiations toward a final settlement and the related environmental and public consultations are ongoing.</p>
<p>Wiikwemkoong is the territory that did not sign the 1850 treaty and remains unceded, while still being a Robinson Huron Treaty annuities beneficiary.</p>
HTML,
                'caveat' => null,
                'related' => [
                    ['slug' => 'wiikwemkoong', 'name' => 'Wiikwemkoong Unceded Territory'],
                ],
                'sources' => [
                    ['label' => 'Ontario, current land claims', 'url' => 'https://www.ontario.ca/page/current-land-claims'],
                    ['label' => 'Georgian Bay Association, Wiikwemkoong islands boundary claim', 'url' => 'https://georgianbay.ca/government-affairs/wiikwemkoong-islands-boundary-claim/'],
                ],
            ],
        ];
    }
}
