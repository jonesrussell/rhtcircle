<?php

declare(strict_types=1);

namespace App\Anokii;

use Anokii\CoIntelligence\TopicVocabulary;

/**
 * The curated RHT graph data for rhtcircle.ca, all public and sourced.
 *
 * Communities are the 21 signatory nations; each carries a curated region (the
 * authoritative catchment of nearby Place slugs). Per the brief, lat/long is NOT
 * fabricated here: the curated region is the authoritative layer, and sourced
 * town coordinates are a later ranking refinement. Organizations and Services are
 * the front doors from research/resources-directory.md, with the directory's
 * verify-before-publish and scope-correction rules honored exactly:
 *
 *   - the Sault friendship centre is linked at ssmifc.ca only (the .com is a
 *     hijacked spam domain and is never seeded);
 *   - NAN Hope, Nishnawbe-Aski Legal Services, and AETS are NOT seeded (Treaty 9
 *     and Robinson-Superior, not RHT);
 *   - Anishinabek Nation and Chiefs of Ontario are not seeded as RHT bodies
 *     (Ontario-wide);
 *   - no flagged-unverified phone number is stated as fact: those services cite
 *     the official website only;
 *   - Wiikwemkoong's unceded-but-beneficiary nuance lives in its community page,
 *     which the ingest links to the wiikwemkoong vantage.
 *
 * The verified contact details live in the curated chunk text (the retrieval and
 * citation layer), never in the entity rows, so nothing is invented.
 */
final class GraphSeedData
{
    /** Idempotency-key prefix for curated chunks (so app:ingest never prunes them). */
    public const CURATED_KEY_PREFIX = 'curated:';

    /** North Shore service catchment (Algoma to Sudbury). */
    private const REGION_NORTH_SHORE = ['sault-ste-marie', 'blind-river', 'elliot-lake', 'espanola', 'greater-sudbury', 'serpent-river', 'thessalon', 'little-current'];

    /** Manitoulin / Mnidoo Mnising catchment. */
    private const REGION_MANITOULIN = ['little-current', 'aundeck-omni-kaning', 'mchigeeng', 'gore-bay', 'mindemoya', 'espanola', 'greater-sudbury'];

    /** Nipissing / Georgian Bay / Parry Sound catchment. */
    private const REGION_NIPISSING_GB = ['north-bay', 'sturgeon-falls', 'parry-sound', 'greater-sudbury'];

    /**
     * @return list<array<string, mixed>> place rows (name + slug only; no coordinates in v1)
     */
    public static function places(): array
    {
        $names = [
            'sault-ste-marie' => 'Sault Ste. Marie',
            'blind-river' => 'Blind River',
            'elliot-lake' => 'Elliot Lake',
            'espanola' => 'Espanola',
            'greater-sudbury' => 'Greater Sudbury',
            'serpent-river' => 'Serpent River (Cutler)',
            'thessalon' => 'Thessalon',
            'little-current' => 'Little Current',
            'aundeck-omni-kaning' => 'Aundeck Omni Kaning',
            'mchigeeng' => "M'Chigeeng",
            'gore-bay' => 'Gore Bay',
            'mindemoya' => 'Mindemoya',
            'north-bay' => 'North Bay',
            'sturgeon-falls' => 'Sturgeon Falls',
            'parry-sound' => 'Parry Sound',
            'massey' => 'Massey',
            'henvey-inlet' => 'Henvey Inlet',
        ];
        $rows = [];
        foreach ($names as $slug => $name) {
            $rows[] = ['slug' => $slug, 'name' => $name, 'lat' => '', 'lng' => '', 'travel_note' => ''];
        }

        return $rows;
    }

    /**
     * The 21 signatory nations, each with its sub-region catchment.
     *
     * @return list<array<string, mixed>>
     */
    public static function communities(): array
    {
        $ns = self::REGION_NORTH_SHORE;
        $mn = self::REGION_MANITOULIN;
        $ng = self::REGION_NIPISSING_GB;
        $defs = [
            ['batchewana', 'Batchewana First Nation of Ojibways', $ns],
            ['garden-river', 'Garden River First Nation', $ns],
            ['thessalon', 'Thessalon First Nation', $ns],
            ['mississauga', 'Mississauga First Nation', $ns],
            ['serpent-river', 'Serpent River First Nation', $ns],
            ['sagamok', 'Sagamok Anishnawbek', $ns],
            ['atikameksheng', 'Atikameksheng Anishnawbek', $ns],
            ['wahnapitae', 'Wahnapitae First Nation', $ns],
            ['nipissing', 'Nipissing First Nation', $ng],
            ['dokis', 'Dokis First Nation', $ng],
            ['henvey-inlet', 'Henvey Inlet First Nation', $ng],
            ['magnetawan', 'Magnetawan First Nation', $ng],
            ['shawanaga', 'Shawanaga First Nation', $ng],
            ['wasauksing', 'Wasauksing First Nation', $ng],
            ['aundeck-omni-kaning', 'Aundeck Omni Kaning First Nation', $mn],
            ['whitefish-river', 'Whitefish River First Nation', $mn],
            ['mchigeeng', "M'Chigeeng First Nation", $mn],
            ['sheguiandah', 'Sheguiandah First Nation', $mn],
            ['sheshegwaning', 'Sheshegwaning First Nation', $mn],
            ['wiikwemkoong', 'Wiikwemkoong Unceded Territory', $mn],
            ['zhiibaahaasing', 'Zhiibaahaasing First Nation', $mn],
        ];
        $rows = [];
        foreach ($defs as [$slug, $name, $region]) {
            $rows[] = ['slug' => $slug, 'name' => $name, 'located_at' => '', 'region' => json_encode($region, JSON_THROW_ON_ERROR)];
        }

        return $rows;
    }

    /**
     * Topics from the package vocabulary (the set the retriever infers and ranks
     * by). Themes in the content that are not yet first-class inferred topics
     * (language, MMIWG, jurisdiction) live in the ingested pages and can be
     * promoted to the vocabulary in a later increment.
     *
     * @return list<array<string, mixed>>
     */
    public static function topics(): array
    {
        $rows = [];
        foreach (new TopicVocabulary()->all() as $slug => $topic) {
            $rows[] = ['slug' => $slug, 'name' => $topic['name'], 'keywords' => implode(' ', $topic['keywords'])];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function projects(): array
    {
        return [
            [
                'slug' => 'massey-solar',
                'name' => 'Massey Solar Project',
                'relates_to' => json_encode(['sagamok', 'wahnapitae', 'atikameksheng'], JSON_THROW_ON_ERROR),
                'located_at' => 'massey',
                'has_topic' => 'energy-solar',
                'source_url' => '/land/massey-solar-project',
            ],
            [
                'slug' => 'henvey-inlet-wind',
                'name' => 'Henvey Inlet Wind',
                'relates_to' => json_encode(['henvey-inlet'], JSON_THROW_ON_ERROR),
                'located_at' => 'henvey-inlet',
                'has_topic' => 'energy-solar',
                'source_url' => '/communities/henvey-inlet',
            ],
        ];
    }

    /**
     * Front-door organizations. Each is independent; listing them implies no
     * affiliation between them or with the hub.
     *
     * @return list<array<string, mixed>>
     */
    public static function organizations(): array
    {
        $defs = [
            ['maamwesying', 'Maamwesying North Shore Community Health Services', 'https://maamwesying.ca/'],
            ['noojmowin-teg', 'Noojmowin Teg Health Centre', 'https://www.noojmowin-teg.ca/'],
            ['shkagamik-kwe', 'Shkagamik-Kwe Health Centre', 'https://www.shkagamik-kwe.org/'],
            ['north-bay-indigenous-hub', 'North Bay Indigenous Hub', 'https://www.northbayindigenoushub.ca/'],
            ['mamaweswen', 'Mamaweswen, The North Shore Tribal Council', 'https://mamaweswen.com/'],
            ['uccmm', 'United Chiefs and Councils of Mnidoo Mnising', 'http://www.uccmm.ca/'],
            ['waawiindamaagewin', 'Robinson Huron Waawiindamaagewin', 'https://www.waawiindamaagewin.com/'],
            ['anishinabek-police', 'Anishinabek Police Service', 'https://www.anishinabekpolice.ca/'],
            ['uccm-police', 'UCCM Anishnaabe Police Service', 'https://www.uccmpolice.com/'],
            ['wikwemikong-police', 'Wikwemikong Tribal Police Service', ''],
            ['hope-for-wellness', 'Hope for Wellness Help Line', 'https://www.hopeforwellness.ca/'],
            ['talk4healing', 'Talk4Healing', 'https://www.talk4healing.com/'],
            ['crisis-988', '988 Suicide Crisis Helpline', 'https://988.ca/'],
            ['kids-help-phone', 'Kids Help Phone', 'https://kidshelpphone.ca/'],
            ['nirs-crisis', 'National Indian Residential School Crisis Line', ''],
            ['mmiwg-crisis', 'MMIWG and 2SLGBTQQIA+ National Crisis Line', ''],
            ['legal-aid-ontario', 'Legal Aid Ontario', 'https://www.legalaid.on.ca/'],
            ['aboriginal-legal-services', 'Aboriginal Legal Services', 'https://www.aboriginallegal.ca/'],
            ['nogdawindamin', 'Nogdawindamin Family and Community Services', 'https://www.nog.ca/'],
            ['kina-gbezhgomi', 'Kina Gbezhgomi Child and Family Services', 'https://www.kgcfs.org/'],
            ['ontario-aboriginal-housing', 'Ontario Aboriginal Housing Services', 'https://www.ontarioaboriginalhousing.ca/'],
            ['kenjgewin-teg', 'Kenjgewin Teg', 'https://kenjgewinteg.ca/'],
            ['ssmifc', 'Indian Friendship Centre (Sault Ste. Marie)', 'https://www.ssmifc.ca/'],
            ['nswakamok', "N'Swakamok Native Friendship Centre", 'https://www.nfcsudbury.org/'],
        ];
        $rows = [];
        foreach ($defs as [$slug, $name, $url]) {
            $rows[] = ['slug' => $slug, 'name' => $name, 'source_url' => $url];
        }

        return $rows;
    }

    /**
     * Front-door services: provided_by an Org, located_at a Place (empty for a
     * province-wide helpline, which the retriever surfaces from any vantage),
     * tagged with a Topic, with its official source URL.
     *
     * @return list<array<string, mixed>>
     */
    public static function services(): array
    {
        // [slug, name, provided_by, located_at, has_topic, source_url]
        $defs = [
            // Province-wide crisis and helplines (empty place; reachable anywhere)
            ['hope-for-wellness-line', 'Hope for Wellness Help Line', 'hope-for-wellness', '', 'mental-health-addictions', 'https://www.hopeforwellness.ca/'],
            ['talk4healing-line', 'Talk4Healing helpline', 'talk4healing', '', 'mental-health-addictions', 'https://www.talk4healing.com/'],
            ['crisis-988-line', '988 Suicide Crisis Helpline', 'crisis-988', '', 'mental-health-addictions', 'https://988.ca/'],
            ['nirs-crisis-line', 'Residential School Crisis Line', 'nirs-crisis', '', 'mental-health-addictions', 'https://www.hopeforwellness.ca/'],
            ['mmiwg-crisis-line', 'MMIWG crisis line', 'mmiwg-crisis', '', 'community-safety', 'https://www.canada.ca/en/crown-indigenous-relations-northern-affairs.html'],
            ['kids-help-line', 'Kids Help Phone', 'kids-help-phone', '', 'child-and-family', 'https://kidshelpphone.ca/'],
            // Regional Indigenous health authorities
            ['maamwesying-primary', 'Maamwesying primary health care', 'maamwesying', 'serpent-river', 'primary-health', 'https://maamwesying.ca/'],
            ['maamwesying-mental', 'Maamwesying Minobimaadizing mental wellness and addictions', 'maamwesying', 'serpent-river', 'mental-health-addictions', 'https://maamwesying.ca/'],
            ['noojmowin-teg-primary', 'Noojmowin Teg primary care and mental wellness', 'noojmowin-teg', 'aundeck-omni-kaning', 'primary-health', 'https://www.noojmowin-teg.ca/'],
            ['shkagamik-kwe-health', 'Shkagamik-Kwe Health Centre', 'shkagamik-kwe', 'greater-sudbury', 'primary-health', 'https://www.shkagamik-kwe.org/'],
            ['north-bay-hub-health', 'North Bay Indigenous Hub primary care', 'north-bay-indigenous-hub', 'north-bay', 'primary-health', 'https://www.northbayindigenoushub.ca/'],
            // Tribal councils and treaty body
            ['waawiindamaagewin-treaty', 'Robinson Huron Waawiindamaagewin (treaty and annuity)', 'waawiindamaagewin', '', 'treaty', 'https://www.waawiindamaagewin.com/'],
            ['mamaweswen-isetp', 'Mamaweswen employment and training (ISETP)', 'mamaweswen', 'serpent-river', 'employment-training', 'https://mamaweswen.com/'],
            ['uccmm-justice', 'UCCMM community justice', 'uccmm', 'mchigeeng', 'legal-aid', 'http://www.uccmm.ca/'],
            ['uccmm-employment', 'Mnidoo Mnising Employment and Training (UCCMM)', 'uccmm', 'mchigeeng', 'employment-training', 'http://www.uccmm.ca/'],
            // Policing
            ['anishinabek-police-svc', 'Anishinabek Police Service', 'anishinabek-police', '', 'community-safety', 'https://www.anishinabekpolice.ca/'],
            ['uccm-police-svc', 'UCCM Anishnaabe Police Service', 'uccm-police', 'mchigeeng', 'community-safety', 'https://www.uccmpolice.com/'],
            ['wikwemikong-police-svc', 'Wikwemikong Tribal Police Service', 'wikwemikong-police', 'wiikwemkoong', 'community-safety', ''],
            // Legal and family
            ['legal-aid-indigenous', 'Legal Aid Ontario Indigenous services', 'legal-aid-ontario', '', 'legal-aid', 'https://www.legalaid.on.ca/'],
            ['aboriginal-legal', 'Aboriginal Legal Services (Gladue)', 'aboriginal-legal-services', '', 'legal-aid', 'https://www.aboriginallegal.ca/'],
            ['nogdawindamin-svc', 'Nogdawindamin Family and Community Services', 'nogdawindamin', 'serpent-river', 'child-and-family', 'https://www.nog.ca/'],
            ['kina-gbezhgomi-svc', 'Kina Gbezhgomi Child and Family Services', 'kina-gbezhgomi', 'mchigeeng', 'child-and-family', 'https://www.kgcfs.org/'],
            // Education, housing, urban front doors
            ['kenjgewin-teg-edu', 'Kenjgewin Teg education and training', 'kenjgewin-teg', 'mchigeeng', 'education-youth', 'https://kenjgewinteg.ca/'],
            ['oahs-housing', 'Ontario Aboriginal Housing Services (off-reserve)', 'ontario-aboriginal-housing', '', 'housing', 'https://www.ontarioaboriginalhousing.ca/'],
            ['ssmifc-frontdoor', 'Indian Friendship Centre, Sault Ste. Marie', 'ssmifc', 'sault-ste-marie', '', 'https://www.ssmifc.ca/'],
            ['nswakamok-frontdoor', "N'Swakamok Native Friendship Centre, Sudbury", 'nswakamok', 'greater-sudbury', '', 'https://www.nfcsudbury.org/'],
            // Off-reserve income support (number flagged unverified: website only)
            ['niigaaniin-income', 'Niigaaniin social assistance (Mamaweswen)', 'mamaweswen', 'serpent-river', 'income-support', 'https://niigaaniin.com/'],
        ];
        $rows = [];
        foreach ($defs as [$slug, $name, $providedBy, $locatedAt, $topic, $sourceUrl]) {
            $rows[] = ['slug' => $slug, 'name' => $name, 'provided_by' => $providedBy, 'located_at' => $locatedAt, 'has_topic' => $topic, 'source_url' => $sourceUrl];
        }

        return $rows;
    }

    /**
     * One short, sourced chunk per front-door service, so the chat can answer and
     * cite. Verified contacts only (the directory's verified set); services whose
     * number is flagged unverified carry the official website and no phone. No
     * private individuals. Keyed by CURATED_KEY_PREFIX so app:ingest never prunes
     * them. For emergencies the answer always says call 911.
     *
     * @return list<array<string, mixed>>
     */
    public static function curatedChunks(): array
    {
        // [service slug, title (org), heading, text]
        $defs = [
            ['hope-for-wellness-line', 'Hope for Wellness Help Line', 'Mental health and crisis support, 24/7', 'The Hope for Wellness Help Line offers immediate mental health support and crisis help for all Indigenous people across Canada, 24 hours a day, 7 days a week, by phone at 1-855-242-3310 and through online chat. Phone and chat are in English and French, with Cree, Ojibway, and Inuktitut available by phone on request. It is a nationwide service, not tied to one community. For an emergency, call 911.'],
            ['talk4healing-line', 'Talk4Healing', 'Help for Indigenous women and families, 24/7', 'Talk4Healing is a confidential, culturally grounded helpline for Indigenous women and their families, operated by Beendigen. It is available 24 hours a day, 7 days a week across Ontario by phone, text, and chat at 1-855-554-4325. It is a province-wide service. For an emergency, call 911.'],
            ['crisis-988-line', '988 Suicide Crisis Helpline', 'Suicide crisis help, 24/7', 'If you or someone you know is thinking about suicide, the 988 Suicide Crisis Helpline is available 24 hours a day, 7 days a week to anyone in Canada. Call or text 988. For an emergency, call 911.'],
            ['nirs-crisis-line', 'National Indian Residential School Crisis Line', 'Residential school crisis support, 24/7', 'The National Indian Residential School Crisis Line provides 24-hour support to former residential school students and their families at 1-866-925-4419. For an emergency, call 911.'],
            ['mmiwg-crisis-line', 'MMIWG and 2SLGBTQQIA+ National Crisis Line', 'Support for families and survivors, 24/7', 'A national crisis line offers 24-hour support to family members and survivors affected by the issue of missing and murdered Indigenous women, girls, and 2SLGBTQQIA+ people at 1-844-413-6649, with support available in several languages. For an emergency, call 911.'],
            ['kids-help-line', 'Kids Help Phone', 'Support for young people, 24/7', 'Kids Help Phone offers free, confidential support to young people 24 hours a day, 7 days a week. Call 1-800-668-6868 or text CONNECT to 686868. For an Indigenous-focused responder, text FIRSTNATIONS, INUIT, or METIS to 686868. For an emergency, call 911.'],
            ['maamwesying-primary', 'Maamwesying North Shore Community Health Services', 'Primary health care, North Shore', 'Maamwesying North Shore Community Health Services provides primary care, home and community care, and traditional health to several North Shore First Nations, and leads the Maamwesying Ontario Health Team. Reach the main office at 705-844-2021 or maamwesying.ca. Confirm clinic days for your community with your band health centre.'],
            ['maamwesying-mental', 'Maamwesying North Shore Community Health Services', 'Mental wellness and addictions (Minobimaadizing)', 'Maamwesying delivers mental wellness and addictions support across its North Shore communities, including counselling, crisis support, and the Minobimaadizing addictions program, alongside traditional health and Elders. See maamwesying.ca, or call 705-844-2021. For an emergency, call 911.'],
            ['noojmowin-teg-primary', 'Noojmowin Teg Health Centre', 'Primary care and mental wellness, Manitoulin', 'Noojmowin Teg Health Centre serves Indigenous individuals and families on Manitoulin Island and surrounding areas, combining traditional healing with Western medicine: primary care, diabetes care, mental health and addictions, and traditional health, with an Espanola satellite. Call 705-368-0083 or see nthc.ca (noojmowin-teg.ca).'],
            ['shkagamik-kwe-health', 'Shkagamik-Kwe Health Centre', 'Urban Indigenous health, Sudbury', 'Shkagamik-Kwe Health Centre is an Aboriginal Health Access Centre serving the Indigenous community in Sudbury, with primary care, mental health, and traditional healing. Call 705-675-1596 or see skhc.ca.'],
            ['north-bay-hub-health', 'North Bay Indigenous Hub', 'Indigenous health, North Bay and Nipissing', 'The North Bay Indigenous Hub (Giiwedno Mshkikiiwgamig) provides primary care and wellness to the Indigenous community in the North Bay and Nipissing area. Call 705-995-0060.'],
            ['waawiindamaagewin-treaty', 'Robinson Huron Waawiindamaagewin', 'Treaty governance and the annuity case', 'Robinson Huron Waawiindamaagewin is the deliberative body of the 21 Robinson Huron Treaty First Nations, and is connected to the Robinson Huron Treaty Litigation Fund that pursued the 1850 annuities case. For treaty and annuity matters across the 21 nations, see waawiindamaagewin.com or call 1-877-633-7558. This hub is independent of the Litigation Fund.'],
            ['mamaweswen-isetp', 'Mamaweswen, The North Shore Tribal Council', 'Employment and training (ISETP)', 'Mamaweswen, The North Shore Tribal Council delivers Indigenous skills, employment, and training programs (ISETP) for its seven member North Shore First Nations. Call 705-844-2340 or 1-877-633-7558, or see mamaweswen.com.'],
            ['uccmm-justice', 'United Chiefs and Councils of Mnidoo Mnising', 'Community justice, Manitoulin', 'The United Chiefs and Councils of Mnidoo Mnising (UCCMM) runs Indigenous community justice programs for its member Manitoulin communities, including Gladue support, diversion, and reintegration. See uccmm.ca.'],
            ['uccmm-employment', 'United Chiefs and Councils of Mnidoo Mnising', 'Employment and training, Manitoulin', 'UCCMM delivers Mnidoo Mnising Employment and Training for its member Manitoulin communities. See uccmm.ca for the employment and training office.'],
            ['anishinabek-police-svc', 'Anishinabek Police Service', 'First Nations policing (non-emergency)', 'The Anishinabek Police Service polices several Robinson Huron Treaty communities, including Garden River, Sagamok, Nipissing, Wahnapitae, Dokis, Wasauksing, Shawanaga, and Magnetawan. The non-emergency line is 1-888-310-1122, and the service is at anishinabekpolice.ca. For an emergency, always call 911.'],
            ['uccm-police-svc', 'UCCM Anishnaabe Police Service', 'First Nations policing, Manitoulin (non-emergency)', 'The UCCM Anishnaabe Police Service polices the Manitoulin UCCMM First Nations. The non-emergency line is 705-377-7135 or 1-888-377-7135, and the service is at uccmpolice.com. For an emergency, always call 911.'],
            ['wikwemikong-police-svc', 'Wikwemikong Tribal Police Service', 'First Nations policing, Wiikwemkoong', 'The Wikwemikong Tribal Police Service serves Wiikwemkoong Unceded Territory. For an emergency, always call 911.'],
            ['legal-aid-indigenous', 'Legal Aid Ontario', 'Legal aid, Indigenous services', 'Legal Aid Ontario provides legal help to people with low incomes and runs Indigenous services. Call 1-800-668-8258 or see legalaid.on.ca.'],
            ['aboriginal-legal', 'Aboriginal Legal Services', 'Gladue and legal support', 'Aboriginal Legal Services provides Gladue services and legal support to Indigenous people across Ontario. Call 1-844-633-2886 or see aboriginallegal.ca.'],
            ['nogdawindamin-svc', 'Nogdawindamin Family and Community Services', 'Indigenous child and family services, North Shore', 'Nogdawindamin Family and Community Services is the Indigenous child and family services agency for the North Shore First Nations, focused on prevention and keeping children connected to family and community. Call 1-800-465-0999 or see nog.ca.'],
            ['kina-gbezhgomi-svc', 'Kina Gbezhgomi Child and Family Services', 'Indigenous child and family services, Manitoulin', 'Kina Gbezhgomi Child and Family Services is the Indigenous child and family services agency for the Manitoulin and UCCMM communities and Wiikwemkoong. Call 1-800-268-1899 or see kgcfs.org.'],
            ['kenjgewin-teg-edu', 'Kenjgewin Teg', 'Education and training, Manitoulin', "Kenjgewin Teg is an Anishinaabe education and training institute at M'Chigeeng First Nation, offering post-secondary, training, and lifelong learning. Call 705-370-4342 or see kenjgewinteg.ca."],
            ['oahs-housing', 'Ontario Aboriginal Housing Services', 'Off-reserve Indigenous housing', 'Ontario Aboriginal Housing Services provides off-reserve housing for Indigenous people across the province, with its head office in Sault Ste. Marie. See ontarioaboriginalhousing.ca.'],
            ['ssmifc-frontdoor', 'Indian Friendship Centre (Sault Ste. Marie)', 'Urban Indigenous front door, Sault Ste. Marie', 'The Indian Friendship Centre in Sault Ste. Marie is an urban front door for Indigenous people, with programs and referrals. Call 705-256-5634 or see ssmifc.ca.'],
            ['nswakamok-frontdoor', "N'Swakamok Native Friendship Centre", 'Urban Indigenous front door, Sudbury', "The N'Swakamok Native Friendship Centre in Sudbury is an urban front door for Indigenous people, with programs and referrals. Call 705-674-2128 or see nfcsudbury.org."],
            ['niigaaniin-income', 'Mamaweswen, The North Shore Tribal Council', 'Social assistance (Niigaaniin)', 'Niigaaniin is the social assistance and Ontario Works delivery for North Shore First Nation members through Mamaweswen. See niigaaniin.com to find the office for your community.'],
        ];

        $byServiceSlug = [];
        foreach (self::services() as $svc) {
            $byServiceSlug[(string) $svc['slug']] = $svc;
        }

        $rows = [];
        foreach ($defs as [$serviceSlug, $title, $heading, $text]) {
            $svc = $byServiceSlug[$serviceSlug] ?? null;
            if ($svc === null) {
                continue;
            }
            $rows[] = [
                'chunk_key' => self::CURATED_KEY_PREFIX . $serviceSlug,
                'source_url' => (string) ($svc['source_url'] !== '' ? $svc['source_url'] : '/communities'),
                'title' => $title,
                'heading' => $heading,
                'text' => $text,
                'entity_type' => 'service',
                'entity_id' => $serviceSlug,
            ];
        }

        return $rows;
    }
}
