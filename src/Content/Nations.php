<?php

declare(strict_types=1);

namespace App\Content;

/**
 * The 21 Robinson Huron Treaty signatory nations, as member-compiled, unofficial
 * standard profiles. Content layer for the /communities/<slug> pages: the
 * SiteController renders these through one shared template so the unofficial
 * banner, correction link, and official-site courtesy are carried by the
 * template, not duplicated per page.
 *
 * Sourced and cross-checked 2026-06-22 against the RHT Litigation Fund community
 * list (rht1850.ca), the federal CIRNAC/ISC First Nation Profiles registry, each
 * nation's own website, and encyclopedic sources. ALL of this is point-in-time:
 * Chief and Council rosters and population figures change, so re-verify against
 * each nation's live site before relying on a page. Population uses the federal
 * registered figure as the figure of record, shown with its date and source.
 */
final class Nations
{
    /** Sub-region groups, in display order. */
    public static function regions(): array
    {
        return [
            'north-shore' => 'North shore of Lake Huron',
            'nipissing-georgian' => 'Lake Nipissing and Georgian Bay',
            'manitoulin' => 'Manitoulin Island',
        ];
    }

    /** @return array<int, array<string, mixed>> all 21 profiles in canonical order */
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

    /** @return array<string, array<int, array<string, mixed>>> profiles grouped by region key */
    public static function byRegion(): array
    {
        $grouped = ['north-shore' => [], 'nipissing-georgian' => [], 'manitoulin' => []];
        foreach (self::rows() as $row) {
            $grouped[$row['region']][] = $row;
        }

        return $grouped;
    }

    /** @return array<int, array<string, mixed>> */
    private static function rows(): array
    {
        return [
            [
                'slug' => 'batchewana',
                'name' => 'Batchewana First Nation of Ojibways',
                'anishinaabemowin' => 'Obaajiwan / Obadjiwan Anishinaabek',
                'region' => 'north-shore',
                'region_name' => 'North shore of Lake Huron',
                'sub_region' => 'North shore of Lake Huron (and Lake Superior / St. Marys River)',
                'nearest_town' => 'Near Sault Ste. Marie, Ontario',
                'population_total' => '~3,979 registered (May 2026)',
                'population_on_reserve' => '~771',
                'population_source' => 'CIRNAC, First Nation Profiles (May 2026)',
                'governance' => 'Chief and 8 Councillors; community election system, two-year terms',
                'language' => 'Ojibwe (Anishinaabemowin)',
                'tribal_council' => 'Mamaweswen, The North Shore Tribal Council (and AIAI)',
                'website' => 'https://batchewana.ca/',
                'website_label' => 'batchewana.ca',
                'history' => <<<'HTML'
<p>Obaajiwan Anishinaabek hold several non-contiguous reserves near Sault Ste. Marie, including Rankin, Goulais Bay, Obadjiwan (Corbeil Point), and Whitefish Island, a fishing and trade centre occupied for thousands of years. Chief Nebenaigoching led the nation's part in the 1849 Mica Bay incident, and Batchewana was a signatory to the Robinson Huron Treaty of 1850 (reserve No. 15 for "Nebenaigoching and his Band"); the nation was also a party to the Pennefather Treaty of 1859. Whitefish Island was returned to reserve status in 1997. Council comprises a Chief and eight Councillors on two-year terms.</p>
HTML,
                'note' => null,
                'sources' => [
                    ['label' => 'Batchewana First Nation official site', 'url' => 'https://batchewana.ca/'],
                    ['label' => 'Governance (band)', 'url' => 'https://batchewana.ca/our-story/governance/'],
                    ['label' => 'Treaties (band)', 'url' => 'https://batchewana.ca/our-story/treaties/'],
                    ['label' => 'CIRNAC registered population', 'url' => 'https://fnp-ppn.aadnc-aandc.gc.ca/FNP/Main/Search/FNRegPopulation.aspx?BAND_NUMBER=198&lang=eng'],
                ],
                'related' => [
                    ['label' => 'North Shore Link and Northeast Power Line', 'href' => '/land/north-shore-link-northeast-power-line', 'desc' => 'Two new Hydro One transmission lines, with eight RHT nations as equity partners through Waasmoowin.'],
                ],
            ],
            [
                'slug' => 'garden-river',
                'name' => 'Garden River First Nation',
                'anishinaabemowin' => 'Ketegaunseebee / Gitigaan-ziibi ("Garden River")',
                'region' => 'north-shore',
                'region_name' => 'North shore of Lake Huron',
                'sub_region' => 'North shore of Lake Huron (St. Marys River / Hwy 17)',
                'nearest_town' => 'Garden River, near Sault Ste. Marie, Ontario',
                'population_total' => '~3,851 registered (May 2026)',
                'population_on_reserve' => '~1,247',
                'population_source' => 'CIRNAC, First Nation Profiles (May 2026)',
                'governance' => 'Chief and 8 Councillors; custom Leadership Selection Law, four-year terms (first election Sept 2023)',
                'language' => 'Ojibwe (Anishinaabemowin) and English',
                'tribal_council' => 'Mamaweswen, The North Shore Tribal Council',
                'website' => 'https://www.gardenriver.org/site/',
                'website_label' => 'gardenriver.org',
                'history' => <<<'HTML'
<p>Ketegaunseebee, the community of Chief Shingwaukonse, lies along the St. Marys River bordering Sault Ste. Marie. Shingwaukonse was a leading negotiator of the Robinson Huron Treaty of 1850, whose schedule set aside reserve land for "Shinguacouse and his Band"; the nation was also a party to the Pennefather Treaty of 1859 and recovered lands in Anderson and Chesley townships in 2003. It governs under its own Leadership Selection Law with a Chief and eight Councillors on four-year terms.</p>
HTML,
                'note' => null,
                'sources' => [
                    ['label' => 'Garden River First Nation official site', 'url' => 'https://www.gardenriver.org/site/'],
                    ['label' => 'Treaties (band)', 'url' => 'https://www.gardenriver.org/site/treaties/'],
                    ['label' => 'CIRNAC registered population', 'url' => 'https://fnp-ppn.aadnc-aandc.gc.ca/FNP/Main/Search/FNRegPopulation.aspx?BAND_NUMBER=199&lang=eng'],
                    ['label' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Garden_River_First_Nation'],
                ],
                'related' => [
                    ['label' => 'Dunns Valley Solar', 'href' => '/land/dunns-valley-solar', 'desc' => 'A proposed solar farm on Garden River land, one of Ontario\'s largest, with the nation holding 50 percent equity.'],
                ],
            ],
            [
                'slug' => 'thessalon',
                'name' => 'Thessalon First Nation',
                'anishinaabemowin' => 'No public community-name spelling found; the 1850 treaty schedule records "Keokouse and his Band"',
                'region' => 'north-shore',
                'region_name' => 'North shore of Lake Huron',
                'sub_region' => 'North shore of Lake Huron, Algoma District',
                'nearest_town' => 'Near Thessalon, Ontario',
                'population_total' => '~1,743 registered (May 2026)',
                'population_on_reserve' => '~113',
                'population_source' => 'CIRNAC, First Nation Profiles (May 2026)',
                'governance' => 'Chief and 5 Councillors; custom election code',
                'language' => 'Anishinaabemowin (Ojibwe)',
                'tribal_council' => 'Mamaweswen, The North Shore Tribal Council',
                'website' => 'https://www.thessalonfirstnation.ca/',
                'website_label' => 'thessalonfirstnation.ca',
                'history' => <<<'HTML'
<p>An Ojibwe and Anishinaabe community on the north shore of Lake Huron near the town of Thessalon. Thessalon was a signatory to the Robinson Huron Treaty of 1850, whose schedule recorded "Keokouse and his Band" receiving four miles of frontage east of the Thessalon River; the nation was also a party to the Pennefather Treaty of 1859. It governs under a custom election code with a Chief and five Councillors, and asserts an outstanding boundary land claim north of Thessalon Township.</p>
HTML,
                'note' => null,
                'sources' => [
                    ['label' => 'Thessalon First Nation official site', 'url' => 'https://www.thessalonfirstnation.ca/'],
                    ['label' => 'Our history (band)', 'url' => 'https://www.thessalonfirstnation.ca/our-history.html'],
                    ['label' => 'CIRNAC registered population', 'url' => 'https://fnp-ppn.aadnc-aandc.gc.ca/fnp/Main/Search/FNRegPopulation.aspx?BAND_NUMBER=202&lang=eng'],
                    ['label' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Thessalon_First_Nation'],
                ],
                'related' => [
                    ['label' => 'North Shore Link and Northeast Power Line', 'href' => '/land/north-shore-link-northeast-power-line', 'desc' => 'Two new Hydro One transmission lines, with eight RHT nations as equity partners through Waasmoowin.'],
                ],
            ],
            [
                'slug' => 'mississauga',
                'name' => 'Mississauga First Nation',
                'anishinaabemowin' => 'Misswezahging ("a river with many outlets")',
                'region' => 'north-shore',
                'region_name' => 'North shore of Lake Huron',
                'sub_region' => 'North shore of Lake Huron, Algoma District',
                'nearest_town' => 'Near Blind River, Ontario (Mississagi River mouth)',
                'population_total' => '~1,595 registered (May 2026)',
                'population_on_reserve' => '~386',
                'population_source' => 'Indigenous Services Canada, First Nation Profiles (May 2026)',
                'governance' => 'Chief (Ogimaa) and 9 Councillors; custom election under the Misswezahging Constitution (2015)',
                'language' => 'Anishinaabemowin (Ojibwe)',
                'tribal_council' => 'Mamaweswen, The North Shore Tribal Council',
                'website' => 'https://www.mississaugi.com/',
                'website_label' => 'mississaugi.com',
                'history' => <<<'HTML'
<p>Also known as Mississauga #8 or Mississaugi, this Ojibwe community sits at the mouth of the Mississagi River next to Blind River. (It is distinct from the Mississaugas of the Credit near Toronto.) The name Misswezahging means "a river with many outlets." Mississauga was a signatory to the Robinson Huron Treaty of 1850, whose schedule allotted land to "Ponekeosh and his Band." The nation governs under its own 2015 Misswezahging Constitution and election code, with a Chief and nine Councillors, and has adopted a Land Code (2019) and Community Protection Law.</p>
HTML,
                'note' => null,
                'sources' => [
                    ['label' => 'Mississauga First Nation official site', 'url' => 'https://www.mississaugi.com/'],
                    ['label' => 'Robinson Huron Treaty page (band)', 'url' => 'https://www.mississaugi.com/robinson-huron-treaty-of-1850'],
                    ['label' => 'ISC registered population', 'url' => 'https://fnp-ppn.aadnc-aandc.gc.ca/fnp/Main/Search/FNRegPopulation.aspx?BAND_NUMBER=200&lang=eng'],
                    ['label' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Mississauga_First_Nation'],
                ],
                'related' => [
                    ['label' => 'North Shore Link and Northeast Power Line', 'href' => '/land/north-shore-link-northeast-power-line', 'desc' => 'Two new Hydro One transmission lines, with eight RHT nations as equity partners through Waasmoowin.'],
                ],
            ],
            [
                'slug' => 'serpent-river',
                'name' => 'Serpent River First Nation',
                'anishinaabemowin' => 'Genabaajing Anishinaabek',
                'region' => 'north-shore',
                'region_name' => 'North shore of Lake Huron',
                'sub_region' => 'North Channel of Lake Huron, near Elliot Lake',
                'nearest_town' => 'Cutler, Ontario',
                'population_total' => '~1,621 registered (2024)',
                'population_on_reserve' => '~375',
                'population_source' => 'CIRNAC, via Wikipedia (2024)',
                'governance' => 'Chief and 5 Councillors; First Nations Elections Act, four-year terms (first FNEA election Oct 30, 2021)',
                'language' => 'Anishinaabemowin (Ojibwe)',
                'tribal_council' => 'Mamaweswen, The North Shore Tribal Council',
                'website' => 'https://serpentriverfn.com/',
                'website_label' => 'serpentriverfn.com',
                'history' => <<<'HTML'
<p>Genabaajing Anishinaabek, an Ojibwe community of the Three Fires Confederacy on the North Channel of Lake Huron, with its community at Cutler near Elliot Lake. Serpent River was a signatory to the Robinson Huron Treaty of 1850; community history records Chief Windawtegownini walking the reserve boundary with the treaty commissioner. The twentieth century brought impacts from Elliot Lake uranium mining and the Cutler acid plant. The nation adopted the First Nations Elections Act in 2020 and is governed by a Chief and five Councillors on four-year terms.</p>
HTML,
                'note' => 'The population figure is dated 2024 (CIRNAC via Wikipedia); re-confirm the current registered figure at publish.',
                'sources' => [
                    ['label' => 'Serpent River First Nation official site', 'url' => 'https://serpentriverfn.com/'],
                    ['label' => 'Our history (band)', 'url' => 'https://serpentriverfn.com/meetup/our-history/'],
                    ['label' => 'Wikipedia (population, CIRNAC-sourced)', 'url' => 'https://en.wikipedia.org/wiki/Serpent_River_First_Nation'],
                    ['label' => 'Canada Gazette (FNEA order)', 'url' => 'https://gazette.gc.ca/rp-pr/p2/2021/2021-05-26/html/sor-dors93-eng.html'],
                ],
                'related' => [
                    ['label' => 'North Shore Link and Northeast Power Line', 'href' => '/land/north-shore-link-northeast-power-line', 'desc' => 'Two new Hydro One transmission lines, with eight RHT nations as equity partners through Waasmoowin.'],
                    ['label' => 'Elliot Lake uranium legacy', 'href' => '/land/elliot-lake-uranium', 'desc' => 'The uranium-tailings legacy in the Serpent River watershed, under perpetual care.'],
                ],
            ],
            [
                'slug' => 'sagamok',
                'name' => 'Sagamok Anishnawbek',
                'anishinaabemowin' => 'Sagamok Anishnawbek',
                'region' => 'north-shore',
                'region_name' => 'North shore of Lake Huron',
                'sub_region' => 'North shore of Lake Huron, Algoma District',
                'nearest_town' => 'Near Massey, Ontario (about 120 km west of Sudbury)',
                'population_total' => '~3,295 registered (2024)',
                'population_on_reserve' => '~1,615',
                'population_source' => 'CIRNAC, via Wikipedia (2024)',
                'governance' => 'Chief and 12 Councillors; First Nations Elections Act, four-year terms (first FNEA election Aug 9, 2024)',
                'language' => 'Anishinaabemowin (Ojibwe)',
                'tribal_council' => 'Mamaweswen, The North Shore Tribal Council',
                'website' => 'https://www.sagamokanishnawbek.com/',
                'website_label' => 'sagamokanishnawbek.com',
                'history' => <<<'HTML'
<p>A Three Fires (Ojibwe, Odawa, Potawatomi) community on the north shore of Lake Huron, near Massey. Sagamok was a signatory to the Robinson Huron Treaty of 1850 and is one of the 21 communities of the Robinson Huron Treaty Litigation Fund. In October 2023 the council resolved to adopt the First Nations Elections Act, holding its first election under it in August 2024 (four-year terms), and is governed by a Chief and twelve Councillors.</p>
HTML,
                'note' => 'The population figure is dated 2024 (CIRNAC via Wikipedia); re-confirm the current registered figure at publish.',
                'sources' => [
                    ['label' => 'Sagamok Anishnawbek official site', 'url' => 'https://www.sagamokanishnawbek.com/'],
                    ['label' => 'Robinson Huron Treaty page (band)', 'url' => 'https://www.sagamokanishnawbek.com/rht/robinson-huron-treaty-1850'],
                    ['label' => 'Wikipedia (population, CIRNAC-sourced)', 'url' => 'https://en.wikipedia.org/wiki/Sagamok_Anishnawbek_First_Nation'],
                    ['label' => 'Canada Gazette (FNEA order)', 'url' => 'https://gazette.gc.ca/rp-pr/p2/2024/2024-01-03/html/sor-dors287-eng.html'],
                ],
                'related' => [
                    ['label' => 'North Shore Link and Northeast Power Line', 'href' => '/land/north-shore-link-northeast-power-line', 'desc' => 'Two new Hydro One transmission lines, with eight RHT nations as equity partners through Waasmoowin.'],
                    ['label' => 'Community safety', 'href' => '/safety', 'desc' => 'Crisis lines, MMIWG, harm reduction, and hate and extremism in the territory.'],
                ],
            ],
            [
                'slug' => 'atikameksheng',
                'name' => 'Atikameksheng Anishnawbek',
                'anishinaabemowin' => 'Atikameksheng ("place of the whitefish"); formerly Whitefish Lake First Nation',
                'region' => 'north-shore',
                'region_name' => 'North shore of Lake Huron',
                'sub_region' => 'North shore of Lake Huron (inland, Sudbury area)',
                'nearest_town' => 'Naughton, Greater Sudbury, Ontario',
                'population_total' => '~1,720 members (Oct 2024)',
                'population_on_reserve' => 'roughly 20% of members',
                'population_source' => 'Atikameksheng Anishnawbek community profile (Oct 2024)',
                'governance' => 'Chief and up to 7 Councillors; Indian Act elections, four-year terms',
                'language' => 'Ojibwe (Anishinaabemowin)',
                'tribal_council' => 'Anishinabek Nation',
                'website' => 'https://atikamekshenganishnawbek.ca/',
                'website_label' => 'atikamekshenganishnawbek.ca',
                'history' => <<<'HTML'
<p>Formerly the Whitefish Lake First Nation (the federal name change took effect in 2013), Atikameksheng ("place of the whitefish") sits on Whitefish Lake about 20 km southwest of Sudbury near Naughton. In 1850 Chief Shawenekezhik signed the Robinson Huron Treaty on the nation's behalf. The community is governed by a Chief and up to seven Councillors. It is currently in litigation seeking the return of lands it says were set out under the 1850 treaty, a claim cleared to proceed to trial.</p>
HTML,
                'note' => 'The membership figure is a band community-profile count (Oct 2024), not the federal registered figure; re-confirm at publish.',
                'sources' => [
                    ['label' => 'Atikameksheng Anishnawbek official site', 'url' => 'https://atikamekshenganishnawbek.ca/'],
                    ['label' => 'Community profile (band)', 'url' => 'https://atikamekshenganishnawbek.ca/about/community-profile/'],
                    ['label' => 'History (band)', 'url' => 'https://atikamekshenganishnawbek.ca/culture-language/history/'],
                    ['label' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Atikameksheng_Anishnawbek_First_Nation'],
                ],
                'related' => [
                    ['label' => 'Atikameksheng land claim', 'href' => '/land/atikameksheng-land-claim', 'desc' => 'The claim to the reserve area set out in the 1850 treaty, cleared to trial in 2024.'],
                    ['label' => 'North Shore Link and Northeast Power Line', 'href' => '/land/north-shore-link-northeast-power-line', 'desc' => 'Two new Hydro One transmission lines, with eight RHT nations as equity partners through Waasmoowin.'],
                    ['label' => 'Community safety', 'href' => '/safety', 'desc' => 'Crisis lines, MMIWG, harm reduction, and hate and extremism in the territory, including the Sudbury area.'],
                ],
            ],
            [
                'slug' => 'wahnapitae',
                'name' => 'Wahnapitae First Nation',
                'anishinaabemowin' => 'Signed the 1850 treaty as "Tagawinini and his Band"; "Taighwenini" survives locally',
                'region' => 'north-shore',
                'region_name' => 'North shore of Lake Huron',
                'sub_region' => 'North shore of Lake Huron (inland, Lake Wanapitei / Sudbury area)',
                'nearest_town' => 'Greater Sudbury, Ontario (northwest shore of Lake Wanapitei)',
                'population_total' => '~799 members (2025)',
                'population_on_reserve' => '~104',
                'population_source' => 'Wikipedia (2025)',
                'governance' => 'Council of five including the Chief (election system unconfirmed)',
                'language' => 'Ojibwe (Anishinaabemowin)',
                'tribal_council' => 'Anishinabek Nation',
                'website' => 'https://www.wahnapitaefirstnation.com/',
                'website_label' => 'wahnapitaefirstnation.com',
                'history' => <<<'HTML'
<p>A small Anishinaabe community on the northwestern shore of Lake Wanapitei, bordered by Greater Sudbury. Wahnapitae was a signatory to the Robinson Huron Treaty of 1850 as "Tagawinini and his Band," a name still echoed in the band office's Taighwenini Trail Road. Council is made up of five elected positions including the Chief.</p>
HTML,
                'note' => 'The election system (custom vs. Indian Act) was not confirmed in available sources, and the population is an encyclopedic figure rather than the federal registered count; verify both at publish.',
                'sources' => [
                    ['label' => 'Wahnapitae First Nation official site', 'url' => 'https://www.wahnapitaefirstnation.com/'],
                    ['label' => 'Chief and Council (band)', 'url' => 'https://www.wahnapitaefirstnation.com/our-community/chief-and-council.html'],
                    ['label' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Wahnapitae_First_Nation'],
                ],
                'related' => [
                    ['label' => 'North Shore Link and Northeast Power Line', 'href' => '/land/north-shore-link-northeast-power-line', 'desc' => 'Two new Hydro One transmission lines, with eight RHT nations as equity partners through Waasmoowin.'],
                    ['label' => 'Community safety', 'href' => '/safety', 'desc' => 'Crisis lines, MMIWG, harm reduction, and hate and extremism in the territory, including the Sudbury area.'],
                ],
            ],
            [
                'slug' => 'nipissing',
                'name' => 'Nipissing First Nation',
                'anishinaabemowin' => 'Constitution is the Gichi-Naaknigewin ("Big Law"); members are Debendaagziwaad',
                'region' => 'nipissing-georgian',
                'region_name' => 'Lake Nipissing and Georgian Bay',
                'sub_region' => 'Lake Nipissing',
                'nearest_town' => 'Garden Village, near Sturgeon Falls, Ontario',
                'population_total' => '~3,694 registered (March 2026)',
                'population_on_reserve' => '~1,004',
                'population_source' => 'Wikipedia (March 2026, CIRNAC-sourced)',
                'governance' => 'Chief, Deputy Chief and 6 Councillors; custom electoral system, three-year terms',
                'language' => 'Ojibwe (Anishinaabemowin), Nbisiing dialect',
                'tribal_council' => 'Anishinabek Nation',
                'website' => 'https://nfn.ca/',
                'website_label' => 'nfn.ca',
                'history' => <<<'HTML'
<p>An Anishinaabe community on the north shore of Lake Nipissing, with administration at Garden Village near Sturgeon Falls. Nipissing was one of the 21 signatory First Nations of the Robinson Huron Treaty of 1850. In 2014 the community ratified the Gichi-Naaknigewin ("Big Law") by referendum, described as the first ratified First Nation constitution in Ontario. It is governed by a Chief, Deputy Chief, and six Councillors under a custom electoral system with three-year terms.</p>
HTML,
                'note' => null,
                'sources' => [
                    ['label' => 'Nipissing First Nation official site', 'url' => 'https://nfn.ca/'],
                    ['label' => 'Constitution (band)', 'url' => 'https://nfn.ca/constitution/'],
                    ['label' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Nipissing_First_Nation'],
                ],
                'related' => [],
            ],
            [
                'slug' => 'dokis',
                'name' => 'Dokis First Nation',
                'anishinaabemowin' => 'Okikendawt (the north island reserve land); named for Chief Michel "Eagle" Dokis',
                'region' => 'nipissing-georgian',
                'region_name' => 'Lake Nipissing and Georgian Bay',
                'sub_region' => 'Lake Nipissing / French River',
                'nearest_town' => 'On the French River, about 16 km southwest of Lake Nipissing',
                'population_total' => '1,300+ members (band, current)',
                'population_on_reserve' => '~200',
                'population_source' => 'Dokis First Nation website (current)',
                'governance' => 'Chief and 5 Councillors; Indian Act elections, two-year cycle (most recent Nov 2024)',
                'language' => 'Ojibwe (Anishinaabemowin)',
                'tribal_council' => 'Anishinabek Nation',
                'website' => 'https://dokis.ca/',
                'website_label' => 'dokis.ca',
                'history' => <<<'HTML'
<p>A community on the French River southwest of Lake Nipissing, comprising the north island (Okikendawt) and a large southern peninsula. In 1850 Michel "Eagle" Dokis signed the Robinson Huron Treaty while running a fur-trading enterprise at Dokis Point; the community settled on its negotiated lands in the 1890s, establishing Dokis Village. Council is a Chief and five Councillors. The nation operates the Okikendawt hydro generating project on the French River and settled a specific claim with Canada in 2021.</p>
HTML,
                'note' => 'The membership figure is a band self-reported count rather than the federal registered figure; confirm at publish.',
                'sources' => [
                    ['label' => 'Dokis First Nation official site', 'url' => 'https://dokis.ca/'],
                    ['label' => 'Leadership (band)', 'url' => 'https://dokis.ca/meet-our-leadership/'],
                    ['label' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Dokis_First_Nation'],
                    ['label' => 'Specific claim settlement (Canada)', 'url' => 'https://www.canada.ca/en/crown-indigenous-relations-northern-affairs/news/2021/05/canada-and-the-dokis-first-nation-celebrate-settlement-of-specific-claim.html'],
                ],
                'related' => [
                    ['label' => 'Okikendawt Hydro (French River)', 'href' => '/land/okikendawt-hydro', 'desc' => 'The run-of-river hydro station on the French River, with Dokis holding 40 percent equity.'],
                ],
            ],
            [
                'slug' => 'henvey-inlet',
                'name' => 'Henvey Inlet First Nation',
                'anishinaabemowin' => 'Energy company named Nigig Power Corporation ("Nigig" = otter)',
                'region' => 'nipissing-georgian',
                'region_name' => 'Lake Nipissing and Georgian Bay',
                'sub_region' => 'Georgian Bay',
                'nearest_town' => 'Near Pickerel and Britt, Ontario (northeast shore of Georgian Bay)',
                'population_total' => '~900 enrolled members (band)',
                'population_on_reserve' => '~200',
                'population_source' => 'Henvey Inlet First Nation community profile',
                'governance' => 'Chief and 7 Councillors; Indian Act elections, two-year cycle',
                'language' => 'Ojibwe (Anishinaabemowin)',
                'tribal_council' => 'Anishinabek Nation',
                'website' => 'https://www.hifn.ca/',
                'website_label' => 'hifn.ca',
                'history' => <<<'HTML'
<p>An Anishinaabe community on the northeast shore of Georgian Bay, with its main community near Pickerel. Henvey Inlet was a signatory to the Robinson Huron Treaty of 1850 and is one of the 21 RHT First Nations. Through its subsidiary Nigig Power Corporation it partnered with Pattern Canada to develop the 300 MW Henvey Inlet Wind project, described as the largest First Nation wind-energy partnership in Canada. Council is a Chief and seven Councillors.</p>
HTML,
                'note' => 'Enrolled-membership figures (about 900) and trust enrolment figures (1,200+) differ across sources; verify at publish.',
                'sources' => [
                    ['label' => 'Henvey Inlet First Nation official site', 'url' => 'https://www.hifn.ca/'],
                    ['label' => 'Community profile (band)', 'url' => 'https://hifn.ca/community/community-profile.html'],
                    ['label' => 'Henvey Inlet Wind (Pattern Canada)', 'url' => 'https://patterncanada.ca/projects/henvey-inlet-wind/'],
                ],
                'related' => [
                    ['label' => 'Henvey Inlet Wind', 'href' => '/land/henvey-inlet-wind', 'desc' => 'The 300 MW wind facility on Henvey Inlet land, the largest First Nation wind partnership in Canada.'],
                ],
            ],
            [
                'slug' => 'magnetawan',
                'name' => 'Magnetawan First Nation',
                'anishinaabemowin' => 'Reported as "Magnetawan Atik Anishnaabe"',
                'region' => 'nipissing-georgian',
                'region_name' => 'Lake Nipissing and Georgian Bay',
                'sub_region' => 'Georgian Bay (Hwy 69 corridor)',
                'nearest_town' => 'Near Britt, Ontario (between Sudbury and Parry Sound)',
                'population_total' => '~416 registered (May 2026)',
                'population_on_reserve' => '~74',
                'population_source' => 'CIRNAC, First Nation Profiles (May 2026)',
                'governance' => 'Chief and 2 Councillors; Indian Act elections, two-year terms',
                'language' => 'Ojibwe (Anishinaabemowin)',
                'tribal_council' => 'Anishinabek Nation',
                'website' => 'https://www.magfn.com/',
                'website_label' => 'magfn.com',
                'history' => <<<'HTML'
<p>A small community on the east shore of Georgian Bay near Britt, midway between Sudbury and Parry Sound along the Highway 69 corridor. Magnetawan is one of the Highway 69 corridor signatories of the Robinson Huron Treaty of 1850 and one of the 21 RHT First Nations. It is the smallest of the corridor communities by registered membership, governed by a Chief and two Councillors.</p>
HTML,
                'note' => 'A Chief-plus-two-Councillor structure and two-year term are reported; re-confirm against the band governance page at publish.',
                'sources' => [
                    ['label' => 'Magnetawan First Nation official site', 'url' => 'https://www.magfn.com/'],
                    ['label' => 'CIRNAC registered population', 'url' => 'https://fnp-ppn.aadnc-aandc.gc.ca/fnp/Main/Search/FNRegPopulation.aspx?BAND_NUMBER=174&lang=eng'],
                    ['label' => 'RHT communities (RHTLF)', 'url' => 'https://www.rht1850.ca/aboutus'],
                    ['label' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Magnetawan_First_Nation'],
                ],
                'related' => [],
            ],
            [
                'slug' => 'shawanaga',
                'name' => 'Shawanaga First Nation',
                'anishinaabemowin' => 'Signed by Chief Muckata Mishoquet, 1850',
                'region' => 'nipissing-georgian',
                'region_name' => 'Lake Nipissing and Georgian Bay',
                'sub_region' => 'Georgian Bay (Hwy 69 corridor)',
                'nearest_town' => 'Nobel, near Parry Sound, Ontario',
                'population_total' => '~732 (encyclopedic; verify against ISC)',
                'population_on_reserve' => null,
                'population_source' => 'Wikipedia (verify against ISC First Nation Profiles)',
                'governance' => 'Chief and 5 Councillors; four-year terms',
                'language' => 'Ojibwe (Anishinaabemowin)',
                'tribal_council' => 'Anishinabek Nation',
                'website' => 'https://shawanagafirstnation.ca/',
                'website_label' => 'shawanagafirstnation.ca',
                'history' => <<<'HTML'
<p>An Ojibwe community on the east shore of Georgian Bay northwest of Parry Sound, in the Highway 69 corridor. Per the nation's own history, Chief Muckata Mishoquet signed the Robinson Huron Treaty in 1850 at Penetanguishene, alongside Wasauksing and neighbouring nations. Council comprises a Chief and five Councillors on four-year terms.</p>
HTML,
                'note' => 'The ISC registered-population figure was not retrieved in research; the ~732 figure should be cross-checked against the federal First Nation Profiles entry, and the current Chief confirmed live, before publish.',
                'sources' => [
                    ['label' => 'Shawanaga First Nation official site', 'url' => 'https://shawanagafirstnation.ca/'],
                    ['label' => 'About and history (band)', 'url' => 'https://shawanagafirstnation.ca/about/'],
                    ['label' => 'Leadership and governance (band)', 'url' => 'https://shawanagafirstnation.ca/leadership-and-governance/'],
                    ['label' => 'RHT communities (RHTLF)', 'url' => 'https://www.rht1850.ca/aboutus'],
                ],
                'related' => [],
            ],
            [
                'slug' => 'wasauksing',
                'name' => 'Wasauksing First Nation',
                'anishinaabemowin' => 'Waaseyaakosing ("place that shines brightly in the reflection of the sacred light"); formerly Parry Island',
                'region' => 'nipissing-georgian',
                'region_name' => 'Lake Nipissing and Georgian Bay',
                'sub_region' => 'Georgian Bay (Hwy 69 corridor)',
                'nearest_town' => 'Parry Island, near Parry Sound, Ontario',
                'population_total' => '~1,490 registered (May 2026)',
                'population_on_reserve' => '~383',
                'population_source' => 'CIRNAC, First Nation Profiles (May 2026)',
                'governance' => 'Band council; adopted a community Land Code (effective June 2017)',
                'language' => 'Ojibwe (Anishinaabemowin), with Odawa and Potawatomi heritage',
                'tribal_council' => 'Anishinabek Nation',
                'website' => 'https://wasauksing.ca/',
                'website_label' => 'wasauksing.ca',
                'history' => <<<'HTML'
<p>An Ojibwe, Odawa, and Potawatomi community on Parry Island in Georgian Bay, near Parry Sound (formerly the Parry Island First Nation). The name Waaseyaakosing is glossed "place that shines brightly in the reflection of the sacred light." Wasauksing is a Highway 69 corridor signatory of the Robinson Huron Treaty of 1850; after the treaty the community settled in two villages, Niisaakiing and Nishnaabe-oodenaang. In 2017 it adopted a community Land Code, taking authority over its reserve lands and resources.</p>
HTML,
                'note' => 'The exact councillor count and election custom should be confirmed on the band site at publish.',
                'sources' => [
                    ['label' => 'Wasauksing First Nation official site', 'url' => 'https://wasauksing.ca/'],
                    ['label' => 'CIRNAC registered population', 'url' => 'https://fnp-ppn.aadnc-aandc.gc.ca/fnp/Main/Search/FNRegPopulation.aspx?BAND_NUMBER=136&lang=eng'],
                    ['label' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Wasauksing_First_Nation'],
                ],
                'related' => [],
            ],
            [
                'slug' => 'aundeck-omni-kaning',
                'name' => 'Aundeck Omni Kaning First Nation',
                'anishinaabemowin' => 'Aundeck Omni Kaning (the name is Anishinaabemowin); formerly Ojibways of Sucker Creek',
                'region' => 'manitoulin',
                'region_name' => 'Manitoulin Island',
                'sub_region' => 'Manitoulin Island (North Channel shore)',
                'nearest_town' => 'Near Little Current, Ontario',
                'population_total' => '~1,047 registered (May 2026)',
                'population_on_reserve' => '~371',
                'population_source' => 'CIRNAC, First Nation Profiles (May 2026)',
                'governance' => 'Chief and Council; band custom system, three-year term (adopted 1991)',
                'language' => 'Ojibwe (Anishinaabemowin)',
                'tribal_council' => 'United Chiefs and Councils of Mnidoo Mnising (UCCMM)',
                'website' => 'https://aokfn.com/',
                'website_label' => 'aokfn.com',
                'history' => <<<'HTML'
<p>A community on the North Channel shore of Manitoulin Island near Little Current, formerly known as the Ojibways of Sucker Creek (the reserve is still officially Sucker Creek 23). Aundeck Omni Kaning is a Manitoulin Island signatory of the Robinson Huron Treaty of 1850 and one of the 21 RHT First Nations. In 1991 it adopted its own band-custom governance with a three-year term of office, and is a member of the United Chiefs and Councils of Mnidoo Mnising.</p>
HTML,
                'note' => null,
                'sources' => [
                    ['label' => 'Aundeck Omni Kaning official site', 'url' => 'https://aokfn.com/'],
                    ['label' => 'CIRNAC registered population', 'url' => 'https://fnp-ppn.aadnc-aandc.gc.ca/fnp/Main/Search/FNRegPopulation.aspx?BAND_NUMBER=180&lang=eng'],
                    ['label' => 'RHT communities (RHTLF)', 'url' => 'https://www.rht1850.ca/aboutus'],
                    ['label' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Aundeck_Omni_Kaning_First_Nation'],
                ],
                'related' => [],
            ],
            [
                'slug' => 'whitefish-river',
                'name' => 'Whitefish River First Nation',
                'anishinaabemowin' => 'Wiigwaaskingaa ("place of birches" / Birch Island); Adikamegoshii-ziibiing ("whitefish river")',
                'region' => 'manitoulin',
                'region_name' => 'Manitoulin Island',
                'sub_region' => 'Manitoulin Island (gateway, Birch Island)',
                'nearest_town' => 'Birch Island, Ontario (Hwy 6 between Espanola and Little Current)',
                'population_total' => '~1,754 registered (May 2026)',
                'population_on_reserve' => '~414',
                'population_source' => 'CIRNAC, First Nation Profiles (May 2026)',
                'governance' => 'Chief and 7 Councillors; custom Election Code (2018), four-year terms',
                'language' => 'Ojibwe (Anishinaabemowin)',
                'tribal_council' => 'United Chiefs and Councils of Mnidoo Mnising (UCCMM)',
                'website' => 'https://www.whitefishriver.ca/',
                'website_label' => 'whitefishriver.ca',
                'history' => <<<'HTML'
<p>Wiigwaaskingaa, "place of birches," sits on Birch Island at the gateway to Manitoulin Island, on Highway 6 between Espanola and Little Current. The nation signed two treaties: the Bond Head Treaty of 1836 and the Robinson Huron Treaty of 1850, in which Chief Wabakekik is recorded as the fourth signatory. It governs under its own 2018 Election Code with a Chief and seven Councillors on four-year terms, and is active in the RHT 21-nation Anishinaabemowin language-revitalization strategy.</p>
HTML,
                'note' => null,
                'sources' => [
                    ['label' => 'Whitefish River First Nation official site', 'url' => 'https://www.whitefishriver.ca/'],
                    ['label' => 'Governance (band)', 'url' => 'https://www.whitefishriver.ca/governance'],
                    ['label' => 'Robinson Huron Treaty page (band)', 'url' => 'https://www.whitefishriver.ca/robinson-huron-treaty'],
                    ['label' => 'CIRNAC registered population', 'url' => 'https://fnp-ppn.aadnc-aandc.gc.ca/fnp/Main/Search/FNRegPopulation.aspx?BAND_NUMBER=230&lang=eng'],
                ],
                'related' => [
                    ['label' => 'North Shore Link and Northeast Power Line', 'href' => '/land/north-shore-link-northeast-power-line', 'desc' => 'Two new Hydro One transmission lines, with eight RHT nations as equity partners through Waasmoowin.'],
                ],
            ],
            [
                'slug' => 'mchigeeng',
                'name' => "M'Chigeeng First Nation",
                'anishinaabemowin' => "M'Chigeeng (formerly West Bay); no settled translation found",
                'region' => 'manitoulin',
                'region_name' => 'Manitoulin Island',
                'sub_region' => 'Manitoulin Island (central)',
                'nearest_town' => "M'Chigeeng, Ontario (off the North Channel)",
                'population_total' => '~3,004 registered (May 2026)',
                'population_on_reserve' => '~879',
                'population_source' => 'Indigenous Services Canada, First Nation Profiles (May 2026)',
                'governance' => 'Chief and 10 Councillors (election system not explicitly confirmed; recent volatility)',
                'language' => 'Anishinaabemowin (Ojibwe), Three Fires community',
                'tribal_council' => 'United Chiefs and Councils of Mnidoo Mnising (UCCMM)',
                'website' => 'https://mchigeeng.ca/',
                'website_label' => 'mchigeeng.ca',
                'history' => <<<'HTML'
<p>A Three Fires community in central Manitoulin Island, formerly known as West Bay, and the second-largest First Nation on the island. M'Chigeeng is a confirmed signatory and beneficiary of the Robinson Huron Treaty of 1850 and maintains an RHT section on its own site. Council comprises a Chief and ten Councillors, the largest council among the island's RHT nations.</p>
HTML,
                'note' => 'The exact election designation (custom vs. Indian Act) was not confirmed, and recent leadership has been volatile; confirm the current roster live at publish.',
                'sources' => [
                    ['label' => "M'Chigeeng First Nation official site", 'url' => 'https://mchigeeng.ca/'],
                    ['label' => 'About (band)', 'url' => 'https://mchigeeng.ca/about-us/'],
                    ['label' => 'ISC registered population', 'url' => 'https://fnp-ppn.aadnc-aandc.gc.ca/fnp/Main/Search/FNRegPopulation.aspx?BAND_NUMBER=181&lang=eng'],
                    ['label' => 'RHT communities (RHTLF)', 'url' => 'https://www.rht1850.ca/communities'],
                ],
                'related' => [],
            ],
            [
                'slug' => 'sheguiandah',
                'name' => 'Sheguiandah First Nation',
                'anishinaabemowin' => 'Sheguiandah (several community translations offered; etymology not settled)',
                'region' => 'manitoulin',
                'region_name' => 'Manitoulin Island',
                'sub_region' => 'Manitoulin Island (northeast)',
                'nearest_town' => 'Near Little Current, Ontario (about 12 km south, Hwy 6)',
                'population_total' => '~495 registered (May 2026)',
                'population_on_reserve' => '~143',
                'population_source' => 'Indigenous Services Canada, First Nation Profiles (May 2026)',
                'governance' => 'Chief and 4 Councillors; Indian Act elections, two-year terms',
                'language' => 'Anishinaabemowin and English; Three Fires roots',
                'tribal_council' => 'United Chiefs and Councils of Mnidoo Mnising (UCCMM)',
                'website' => 'https://sheguiandahfn.ca/',
                'website_label' => 'sheguiandahfn.ca',
                'history' => <<<'HTML'
<p>A small Three Fires community at the northeast end of Manitoulin Island, about 12 km south of Little Current along Highway 6. Sheguiandah is a confirmed signatory and beneficiary of the Robinson Huron Treaty of 1850 (its own About page also foregrounds the Manitoulin Island treaty context, which applies to island communities). It is governed by a Chief and four Councillors under the Indian Act electoral system with two-year terms.</p>
HTML,
                'note' => null,
                'sources' => [
                    ['label' => 'Sheguiandah First Nation official site', 'url' => 'https://sheguiandahfn.ca/'],
                    ['label' => 'About (band)', 'url' => 'https://sheguiandahfn.ca/about/'],
                    ['label' => 'ISC First Nation Profile', 'url' => 'https://fnp-ppn.aadnc-aandc.gc.ca/fnp/Main/Search/FNMain.aspx?BAND_NUMBER=176&lang=eng'],
                    ['label' => 'RHT communities (RHTLF)', 'url' => 'https://www.rht1850.ca/communities'],
                ],
                'related' => [],
            ],
            [
                'slug' => 'sheshegwaning',
                'name' => 'Sheshegwaning First Nation',
                'anishinaabemowin' => 'Nishnaabemwin (Odawa dialect); name reported as "place of the rattlesnake" (unconfirmed)',
                'region' => 'manitoulin',
                'region_name' => 'Manitoulin Island',
                'sub_region' => 'Manitoulin Island (western)',
                'nearest_town' => 'Western Manitoulin Island (about 112 km west of Little Current)',
                'population_total' => '~536 registered (May 2026)',
                'population_on_reserve' => '~113',
                'population_source' => 'Indigenous Services Canada, First Nation Profiles (May 2026)',
                'governance' => 'Chief and 4 Councillors, two-year term; self-governing under its Kchi-Naaknigewin',
                'language' => 'Nishnaabemwin (Anishinaabemowin, Odawa dialect) and English',
                'tribal_council' => 'United Chiefs and Councils of Mnidoo Mnising (UCCMM)',
                'website' => 'https://www.sheshegwaning.org/',
                'website_label' => 'sheshegwaning.org',
                'history' => <<<'HTML'
<p>An Odawa community on the northern shore of western Manitoulin Island. Sheshegwaning is a confirmed Manitoulin Island signatory and beneficiary of the Robinson Huron Treaty of 1850. It is self-governing under its own constitution, the Sheshegwaning Kchi-Naaknigewin, with a Chief and four Councillors on two-year terms.</p>
HTML,
                'note' => 'The name etymology comes from secondary or tourism sources and is flagged as unconfirmed; the precise statutory election designation is not stated. Verify at publish.',
                'sources' => [
                    ['label' => 'Sheshegwaning First Nation official site', 'url' => 'https://www.sheshegwaning.org/'],
                    ['label' => 'Chief and council (band)', 'url' => 'https://sheshegwaning.org/government/chief-council'],
                    ['label' => 'ISC registered population', 'url' => 'https://fnp-ppn.aadnc-aandc.gc.ca/fnp/Main/Search/FNRegPopulation.aspx?BAND_NUMBER=178&lang=eng'],
                    ['label' => 'RHT communities (RHTLF)', 'url' => 'https://www.rht1850.ca/communities'],
                ],
                'related' => [],
            ],
            [
                'slug' => 'wiikwemkoong',
                'name' => 'Wiikwemkoong Unceded Territory',
                'anishinaabemowin' => 'Wiikwemkoong (from wiikwemik, "bay with a gently sloping bottom," plus -ong, "at")',
                'region' => 'manitoulin',
                'region_name' => 'Manitoulin Island',
                'sub_region' => 'Manitoulin Island (eastern peninsula)',
                'nearest_town' => 'Near Manitowaning, Ontario',
                'population_total' => '~9,400 registered (April 2026)',
                'population_on_reserve' => '~3,213',
                'population_source' => 'The Canadian Encyclopedia (April 2026); verify against the live ISC band-175 profile',
                'governance' => 'Ogimaa (Chief) and 12 Councillors; Indian Act terms (working toward a custom election law); Elders and Youth councils',
                'language' => 'Anishinaabemowin (Eastern / Manitoulin Ojibwe); Three Fires (Ojibwe, Odawa, Potawatomi)',
                'tribal_council' => 'Independent; works with UCCMM and the Anishinabek Nation',
                'website' => 'https://www.wiikwemkoong.ca/',
                'website_label' => 'wiikwemkoong.ca',
                'history' => <<<'HTML'
<p>A large Three Fires community on the eastern peninsula of Manitoulin Island, and one of the few "unceded" territories in Canada. Wiikwemkoong adopted its self-designation and constitution (the Wiikwemkong G'chi Naaknigewin) in 2014, and is governed by an Ogimaa and twelve Councillors.</p>
<p>On the treaty, two facts need to be stated together and not blurred:</p>
<ul>
  <li>Wiikwemkoong did <strong>not</strong> sign the Robinson Huron Treaty of 1850, which ceded the Lake Huron and Lake Superior north shore. Manitoulin Island was handled separately, under the 1836 Bond Head and 1862 McDougall treaties. Wiikwemkoong <strong>refused</strong> to sign the 1862 treaty, and the Wikwemikong peninsula was deliberately excluded from that surrender. That 1862 refusal, not anything in the 1850 treaty, is why its land remains unceded.</li>
  <li>Wiikwemkoong <strong>is</strong> a participating Robinson Huron Treaty First Nation and annuities beneficiary in the modern context: it is listed among the 21 communities of the Robinson Huron Treaty Litigation Fund, helped lead the annuities claim, and administered the 2023 settlement payments to its members.</li>
</ul>
<p>In short: a participating RHT beneficiary whose reserve land is unceded because of the 1862 refusal. The two coexist because Manitoulin was treated separately from the 1850 cession.</p>
HTML,
                'note' => null,
                'sources' => [
                    ['label' => 'Wiikwemkoong Unceded Territory official site', 'url' => 'https://www.wiikwemkoong.ca/'],
                    ['label' => 'Managing your Robinson Huron Treaty settlement (band)', 'url' => 'https://www.wiikwemkoong.ca/money-management-managing-your-robinson-huron-treaty-settlement/'],
                    ['label' => 'The Canadian Encyclopedia (Wiikwemkoong)', 'url' => 'https://www.thecanadianencyclopedia.ca/en/article/wiikwemkoong-unceded-territory'],
                    ['label' => 'Manitoulin Island treaties report (CIRNAC)', 'url' => 'https://www.rcaanc-cirnac.gc.ca/eng/1100100028959/1564583230395'],
                    ['label' => 'RHT communities (RHTLF)', 'url' => 'https://www.rht1850.ca/communities'],
                ],
                'related' => [
                    ['label' => 'Wiikwemkoong islands boundary claim', 'href' => '/land/wiikwemkoong-islands-claim', 'desc' => 'Negotiations toward the return of islands off the Wiikwemkoong coast.'],
                ],
            ],
            [
                'slug' => 'zhiibaahaasing',
                'name' => 'Zhiibaahaasing First Nation',
                'anishinaabemowin' => 'Zhiibaahaasing ("the narrows" / "where water passes between two lands," needs primary confirmation); formerly Cockburn Island',
                'region' => 'manitoulin',
                'region_name' => 'Manitoulin Island',
                'sub_region' => 'Manitoulin Island (western)',
                'nearest_town' => 'Western Manitoulin Island (parcel 19A); Cockburn Island (no permanent residents)',
                'population_total' => '~213 registered (May 2026)',
                'population_on_reserve' => '~65',
                'population_source' => 'Indigenous Services Canada, First Nation Profiles (May 2026)',
                'governance' => 'Chief and 3 Councillors (election system unconfirmed; roster to re-verify)',
                'language' => 'Odawa and Ojibwe (Anishinaabemowin)',
                'tribal_council' => 'United Chiefs and Councils of Mnidoo Mnising (UCCMM)',
                'website' => null,
                'website_label' => null,
                'website_note' => 'No dedicated official band website was confirmed. Zhiibaahaasing is represented through the United Chiefs and Councils of Mnidoo Mnising and the Anishinabek Nation; see the Anishinabek Nation profile in the sources below.',
                'history' => <<<'HTML'
<p>The smallest of the 21 RHT nations by registered membership, with origins on Cockburn Island and a community now based on a western Manitoulin Island parcel established through a federal negotiation around 1990. Zhiibaahaasing (formerly Cockburn Island First Nation) is a confirmed signatory and beneficiary of the Robinson Huron Treaty of 1850. Council is a Chief and three Councillors.</p>
HTML,
                'note' => 'No dedicated official band website was confirmed; the most recent leadership roster found dates to 2015 and the election system is unconfirmed. Re-verify all governance details at publish.',
                'sources' => [
                    ['label' => 'Anishinabek Nation profile', 'url' => 'https://anishinabek.ca/zhiibaahaasing-first-nation/'],
                    ['label' => 'ISC registered population', 'url' => 'https://fnp-ppn.aadnc-aandc.gc.ca/fnp/Main/Search/FNRegPopulation.aspx?BAND_NUMBER=173&lang=eng'],
                    ['label' => 'RHT communities (RHTLF)', 'url' => 'https://www.rht1850.ca/communities'],
                    ['label' => 'Wikipedia', 'url' => 'https://en.wikipedia.org/wiki/Zhiibaahaasing_First_Nation'],
                ],
                'related' => [],
            ],
        ];
    }
}
