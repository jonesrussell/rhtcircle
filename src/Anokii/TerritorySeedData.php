<?php

declare(strict_types=1);

namespace App\Anokii;

/**
 * The curated territory resource seed for rhtcircle.ca (Highway 17 / North Shore
 * corridor starter set), all public and sourced.
 *
 * This is the commerce and everyday-life layer that answers "what is here, and
 * what do I have to leave town for" for Massey, Webbwood, Spanish, Espanola,
 * Elliot Lake, Sault Ste. Marie and Greater Sudbury. It is merged into
 * {@see GraphSeedData} so the existing seeder (app:seed-graph) loads it with the
 * same idempotent, slug-keyed upsert and the same curated-chunk contract.
 *
 * Rules carried from the seed (RHT/anokii-territory-seed-starter.md), enforced
 * here, not left to the loader:
 *   - every chunk carries its source URL (no source, no record);
 *   - one grounding chunk per resource, tagged with its place and topic;
 *   - entries the seed marks verify-address get an "address unconfirmed" note
 *     appended (this app has no editorial state), and the unconfirmed Spanish
 *     bank, hardware store, and medical/dental claims are NOT loaded at all;
 *   - community-reported gaps load as their own chunks, attributed to residents,
 *     never as official record;
 *   - coordinates are a distance-ranking signal only; the only travel figure
 *     shown is Elliot Lake at about 45 minutes from Sagamok;
 *   - no invented phone numbers, addresses, hours, or drive times; no em dashes;
 *   - the standing line (general information, confirm with the office, call 911)
 *     and the affiliation disclaimer are enforced by the chat prompt and the
 *     page, not repeated in every chunk.
 */
final class TerritorySeedData
{
    /** Note appended to a chunk whose street address is from a directory aggregator. */
    private const VERIFY_ADDRESS_NOTE = ' Address unconfirmed; confirm with the office.';

    /**
     * Sourced coordinates for the corridor places (ranking-only, never shown).
     * Massey, Espanola, Elliot Lake, Sault Ste. Marie and Greater Sudbury are the
     * figures carried in the region data; Spanish and Webbwood are geocoded to
     * their municipal centroid (Town of Spanish office, Webbwood / Main Street).
     * Elliot Lake carries the one sourced travel note.
     *
     * @var array<string, array{lat: string, lng: string, travel_note: string}>
     */
    private const PLACE_COORDS = [
        'massey' => ['lat' => '46.2126', 'lng' => '-82.0776', 'travel_note' => ''],
        'espanola' => ['lat' => '46.2584', 'lng' => '-81.7665', 'travel_note' => ''],
        'elliot-lake' => ['lat' => '46.3833', 'lng' => '-82.6500', 'travel_note' => 'about 45 minutes from Sagamok'],
        'sault-ste-marie' => ['lat' => '46.5168', 'lng' => '-84.3333', 'travel_note' => ''],
        'greater-sudbury' => ['lat' => '46.4900', 'lng' => '-80.9900', 'travel_note' => ''],
        'webbwood' => ['lat' => '46.2667', 'lng' => '-81.8833', 'travel_note' => ''],
        'spanish' => ['lat' => '46.1928', 'lng' => '-82.3458', 'travel_note' => ''],
    ];

    /** New place nodes the corridor introduces (the others already exist). */
    private const NEW_PLACES = [
        'webbwood' => 'Webbwood',
        'spanish' => 'Spanish',
    ];

    /** Corridor towns to add to Sagamok's North Shore catchment. */
    private const REGION_ADDITIONS = ['massey', 'webbwood', 'spanish'];

    /** Display names for the places used in chunk headings. */
    private const PLACE_NAMES = [
        'massey' => 'Massey',
        'webbwood' => 'Webbwood',
        'spanish' => 'Spanish',
        'espanola' => 'Espanola',
        'elliot-lake' => 'Elliot Lake',
        'sault-ste-marie' => 'Sault Ste. Marie',
        'greater-sudbury' => 'Greater Sudbury',
    ];

    /** Topic-slug to display label, for chunk headings. */
    private const TOPIC_LABELS = [
        'groceries-and-food' => 'Groceries and food',
        'banking' => 'Banking',
        'dining' => 'Dining',
        'retail-and-hardware' => 'Retail and hardware',
        'everyday-services' => 'Everyday services',
        'government-services' => 'Government services',
        'primary-health' => 'Primary health',
        'food-security' => 'Food security',
        'mental-health-addictions' => 'Mental health and addictions',
        'child-and-family' => 'Child and family',
        'income-support' => 'Income support',
        'employment-training' => 'Jobs and training',
        'education-youth' => 'Education',
        'transportation' => 'Transportation',
    ];

    /**
     * Every loaded resource: [slug, name, place, topic, source_url, confidence,
     * grounding]. confidence is 'verified' or 'verify-address' (the latter gets
     * the unconfirmed-address note). Each row yields an Organization, a Service,
     * and one curated grounding chunk. Resources already in the RHT graph
     * (Maamwesying, Shkagamik-Kwe, N'Swakamok, Ontario Aboriginal Housing, the
     * Sault Friendship Centre, Mamaweswen) are NOT repeated here. The unconfirmed
     * Spanish bank, hardware store, and medical/dental claims are NOT included.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>
     */
    public static function resources(): array
    {
        return [
            // Massey (inside Sagamok's catchment).
            ['massey-poiriers-clover-farm', "Poirier's Clover Farm", 'massey', 'groceries-and-food', 'https://www.sables-spanish.ca/business-directory/retail-convenience-stores/', 'verify-address', "Poirier's Clover Farm is the town's main full-service grocery store in Massey, an independent Clover Farm banner with meat and produce, at 130 Sauble Street E."],
            ['massey-little-brew-cafe', 'The Little Brew Cafe', 'massey', 'dining', 'https://www.ontariobybike.ca/business-directory/little-brew-cafe/', 'verified', 'The Little Brew Cafe is a coffee shop and bakery in Massey serving breakfast and lunch with daily baked goods made on site, at 365 Imperial Street S.'],
            ['massey-birch-lake-abattoir', 'Birch Lake Abattoir', 'massey', 'groceries-and-food', 'https://www.sables-spanish.ca/business-directory/local-producers/', 'verify-address', 'Birch Lake Abattoir is the local abattoir on Birch Lake Road in Massey, offering custom slaughter and meat processing, at 556 Birch Lake Rd.'],
            ['massey-janeway-pharmachoice', 'Janeway PharmaChoice', 'massey', 'everyday-services', 'https://janewaypharmachoice.ca/', 'verify-address', 'Janeway PharmaChoice is the town pharmacy in Massey, a PharmaChoice banner, at 180 Sable Street E.'],
            ['massey-home-hardware', 'Massey Home Hardware', 'massey', 'retail-and-hardware', 'https://www.homehardware.ca/en/store/14318', 'verified', 'Massey Home Hardware is a Home Hardware dealer in Massey, at 275 Imperial Street S.'],
            ['massey-rona-sonnenburg', 'RONA Sonnenburg Hardware Ltd', 'massey', 'retail-and-hardware', 'https://www.sables-spanish.ca/business-directory/retail-convenience-stores/', 'verify-address', 'RONA Sonnenburg Hardware Ltd is a RONA-affiliated hardware and building supply store in Massey, at 155 Sauble Street E.'],
            ['massey-wing-house', 'Wing House', 'massey', 'dining', 'https://www.sables-spanish.ca/business-directory/dining-restaurants/', 'verify-address', 'Wing House is a local restaurant in Massey, at 340 Sable Street E.'],
            ['massey-medical-clinic', 'Massey Medical Clinic', 'massey', 'primary-health', 'https://www.sables-spanish.ca/living-here/medical-clinic/', 'verified', 'The Massey Medical Clinic is a physician and nurse-practitioner clinic with lab service in Massey, serving Massey, Webbwood, Walford and Sagamok Anishnawbek, at 260 Cameron Street.'],
            ['massey-food-bank', 'Massey Food Bank', 'massey', 'food-security', 'https://211north.ca/record/65271407/', 'verified', 'The Massey Food Bank provides monthly non-perishable food and vouchers in Massey; call to register.'],
            ['massey-post-office', 'Massey Post Office (Canada Post)', 'massey', 'everyday-services', 'https://www.canadapost-postescanada.ca/', 'verify-address', 'The Massey Post Office is a Canada Post retail outlet at 145 Grove Street in Massey.'],
            ['massey-library', 'Sables-Spanish Rivers Public Library, Massey Branch', 'massey', 'everyday-services', 'https://www.ssrpl.ca/', 'verify-address', 'The Massey Branch of the Sables-Spanish Rivers Public Library is the main public library branch for the township, at 185 Grove Street.'],
            ['massey-township-office', 'Township of Sables-Spanish Rivers (municipal office)', 'massey', 'government-services', 'https://www.sables-spanish.ca/contact/', 'verified', 'The Township of Sables-Spanish Rivers municipal office is the township hall serving Massey, Webbwood and Spanish, at 11 Birch Lake Road in Massey.'],
            ['massey-martins-home-baking', "Martin's Home Baking", 'massey', 'groceries-and-food', 'https://www.sables-spanish.ca/business-directory/local-producers/', 'verify-address', "Martin's Home Baking is a home bakery in the Lee Valley Mennonite community near Walford, the Mennonite bakery near Walford, at 530 Lee Valley Road, Massey. No Sunday sales."],
            ['massey-sauders-produce-meats', "Sauder's Produce & Meats", 'massey', 'groceries-and-food', 'https://www.sables-spanish.ca/business-directory/local-producers/', 'verify-address', "Sauder's Produce & Meats is a produce and meat stand on River Road in Walford, near Massey, at 75 River Road. No Sunday sales."],

            // Webbwood.
            ['webbwood-tom-stewart-general-store', 'Tom Stewart & Wife General Store', 'webbwood', 'retail-and-hardware', 'https://www.sables-spanish.ca/business-directory/retail-convenience-stores/', 'verify-address', 'Tom Stewart & Wife General Store is a long-running general store in Webbwood carrying groceries and cured meats, with an LCBO agency, open year-round, at 27-29 Main Street.'],
            ['webbwood-post-office', 'Webbwood Post Office (Canada Post)', 'webbwood', 'everyday-services', 'https://www.canadapost-postescanada.ca/', 'verify-address', 'The Webbwood Post Office is a Canada Post outlet on Main Street in Webbwood, at 27 Main Street.'],
            ['webbwood-library', 'Webbwood Public Library', 'webbwood', 'everyday-services', 'https://www.ssrpl.ca/', 'verify-address', 'The Webbwood Public Library is a branch of the Sables-Spanish Rivers Public Libraries, in Webbwood.'],

            // Spanish (the unconfirmed bank, hardware store, and medical/dental claims are intentionally not loaded).
            ['spanish-small-bites-groceries', 'Small Bites Groceries', 'spanish', 'groceries-and-food', 'https://www.townofspanish.com/', 'verify-address', 'Small Bites Groceries is a small-town grocery in Spanish with fresh produce, subs and hot meals, at 1 John Street.'],
            ['spanish-gambles-highway-variety', "Gamble's Highway Variety", 'spanish', 'retail-and-hardware', 'https://www.algomacountry.com/cities-towns/spanish/', 'verify-address', "Gamble's Highway Variety is a highway variety and convenience store in Spanish, at 6 Algoma Street."],
            ['spanish-north-channel-pizza', 'North Channel Pizza', 'spanish', 'dining', 'https://www.algomacountry.com/cities-towns/spanish/', 'verify-address', 'North Channel Pizza is a pizza place and restaurant in the downtown core of Spanish, at 101 Front Street.'],
            ['spanish-serpent-river-trading-post', 'Serpent River Trading Post, Gas & Convenience', 'spanish', 'everyday-services', 'https://serpentrivertradingpost.ca/', 'verified', 'The Serpent River Trading Post is a gas and convenience stop on Highway 17 toward Serpent River, which also houses a First Nations and Canadian fine-art gallery, at 479 Highway 17, Spanish.'],
            ['spanish-waterfront-marina', 'Waterfront Marina Complex (Town of Spanish)', 'spanish', 'everyday-services', 'https://www.townofspanish.com/', 'verify-address', 'The Waterfront Marina Complex in Spanish is a full-service marina with a public laundromat, showers, a fitness centre and wifi, on the Spanish waterfront.'],
            ['spanish-post-office', 'Spanish Post Office (Canada Post)', 'spanish', 'everyday-services', 'https://www.canadapost-postescanada.ca/', 'verify-address', 'The Spanish Post Office is a Canada Post outlet in Spanish, at 4 Bernard Street.'],
            ['spanish-town-office', 'Town of Spanish (municipal office)', 'spanish', 'government-services', 'https://www.townofspanish.com/contact-us/', 'verified', 'The Town of Spanish municipal office is the town hall for taxation, building and planning, the marina, water and landfill, at 8 Trunk Road.'],

            // Espanola (regional service hub).
            ['espanola-regional-hospital', 'Espanola Regional Hospital and Health Centre', 'espanola', 'primary-health', 'https://211north.ca/record/65272267/', 'verified', 'The Espanola Regional Hospital and Health Centre is a full-service hospital with emergency care, lab, physiotherapy, an on-site pharmacy, and a Rapid Access Addictions Medicine (RAAM) clinic, at 825 McKinnon Dr.'],
            ['espanola-clinic-pharmacy', 'Espanola Clinic Pharmacy (PharmaChoice)', 'espanola', 'everyday-services', 'https://www.pharmachoice.com/locations/espanola-clinic-pharmacy/', 'verified', 'Espanola Clinic Pharmacy is a community pharmacy on the hospital campus in Espanola, at 825 McKinnon Drive.'],
            ['espanola-district-credit-union', 'Espanola & District Credit Union', 'espanola', 'banking', 'https://www.fsrao.ca/', 'verify-address', 'The Espanola & District Credit Union is a credit union branch in Espanola, the nearest banking for Massey, Webbwood and Spanish residents, at 91 Centre Street.'],
            ['espanola-northern-credit-union', 'Northern Credit Union (Espanola)', 'espanola', 'banking', 'https://www.northerncu.com/', 'verify-address', 'Northern Credit Union, the largest credit union in Northern Ontario, serves Espanola.'],
            ['espanola-scotiabank', 'Scotiabank (Espanola)', 'espanola', 'banking', 'https://locator.scotiabank.com/', 'verify-address', 'Scotiabank has a branch serving Espanola and the LaCloche and Manitoulin area.'],
            ['espanola-winkels-independent', "Winkel's Your Independent Grocer", 'espanola', 'groceries-and-food', 'https://www.yourindependentgrocer.ca/', 'verify-address', "Winkel's Your Independent Grocer is a full-size supermarket in Espanola, a Loblaw banner."],
            ['espanola-freshco', 'FreshCo Espanola', 'espanola', 'groceries-and-food', 'https://www.freshco.com/', 'verify-address', 'FreshCo Espanola is a discount grocery store in Espanola.'],
            ['espanola-foodland', 'Foodland Espanola', 'espanola', 'groceries-and-food', 'https://www.foodland.ca/', 'verify-address', 'Foodland Espanola is a Sobeys-banner grocery store in Espanola.'],
            ['espanola-serviceontario', 'ServiceOntario (Fleming and Centre, Espanola)', 'espanola', 'government-services', 'https://www.ontario.ca/locations/serviceontario/fleming-and-centre-espanola', 'verified', 'ServiceOntario in Espanola provides provincial services including health cards, driver and vehicle services, and licences, at 148 Fleming Street, Unit 2.'],
            ['espanola-service-canada', 'Service Canada Centre, Espanola', 'espanola', 'government-services', 'https://offices.service.canada.ca/en/Office/3762', 'verified', 'The Service Canada Centre in Espanola provides federal programs including EI, CPP, SIN and passports, at 721 Centre Street, Suites 2 and 3.'],
            ['espanola-msdsb-ontario-works', 'Manitoulin-Sudbury District Services Board, Ontario Works (Espanola)', 'espanola', 'income-support', 'https://211north.ca/record/71647059/', 'verified', 'The Manitoulin-Sudbury District Services Board in Espanola delivers Ontario Works income and employment assistance, housing, paramedic and children\'s services, at 210 Mead Blvd.'],
            ['espanola-cambrian-employment', 'Cambrian College Employment Options (Espanola)', 'espanola', 'employment-training', 'https://www.espanola.ca/explore-espanola/business-service-directory', 'verify-address', 'Cambrian College Employment Options in Espanola is an employment services office offering job search and training support, at 91 Tudhope Street.'],
            ['espanola-town-office', 'Town of Espanola (municipal office)', 'espanola', 'government-services', 'https://www.espanola.ca/', 'verified', 'The Town of Espanola municipal office serves the area; Espanola is the service hub for the region.'],
            ['espanola-library', 'Espanola Public Library', 'espanola', 'everyday-services', 'https://www.espanola.ca/services/library', 'verified', 'The Espanola Public Library serves Espanola and contracting municipalities.'],

            // Elliot Lake (regional service hub).
            ['elliot-lake-st-josephs-hospital', "St. Joseph's General Hospital Elliot Lake", 'elliot-lake', 'primary-health', 'https://sjghel.ca/', 'verified', "St. Joseph's General Hospital in Elliot Lake is an accredited hospital with medical, surgical, obstetrical, pediatric and chronic care and a 24-hour emergency department, at 70 Spine Rd."],
            ['elliot-lake-hsn-east-algoma', 'HSN East Algoma Site (Elliot Lake)', 'elliot-lake', 'mental-health-addictions', 'https://hsnsudbury.ca/en/Services-and-Specialties/Mental-Health-and-Addictions/HSN-East-Algoma-Site-Elliot-Lake', 'verified', 'The HSN East Algoma Site in Elliot Lake is a Health Sciences North satellite providing mental health and addictions services.'],
            ['elliot-lake-counselling-centre', 'Counselling Centre of East Algoma', 'elliot-lake', 'mental-health-addictions', 'https://www.counsellingcentre.org/', 'verified', 'The Counselling Centre of East Algoma offers confidential counselling for individuals, couples, families and groups in Elliot Lake, at 9 Oakland Blvd, Suite 2.'],
            ['elliot-lake-rbc', 'RBC Royal Bank (Elliot Lake)', 'elliot-lake', 'banking', 'https://maps.rbcroyalbank.com/ON-ELLIOT%20LAKE-branch-1342', 'verify-address', 'RBC Royal Bank has a chartered bank branch with an ATM in Elliot Lake.'],
            ['elliot-lake-scotiabank', 'Scotiabank (Elliot Lake)', 'elliot-lake', 'banking', 'https://www.elliotlaketoday.com/directory/banks-and-credit-unions/', 'verify-address', 'Scotiabank has a chartered bank branch in Elliot Lake, at 1 Manitoba Rd.'],
            ['elliot-lake-td', 'TD Canada Trust (Elliot Lake)', 'elliot-lake', 'banking', 'https://www.elliotlaketoday.com/directory/banks-and-credit-unions', 'verify-address', 'TD Canada Trust has a chartered bank branch in Elliot Lake.'],
            ['elliot-lake-cibc', 'CIBC (Elliot Lake)', 'elliot-lake', 'banking', 'https://www.elliotlaketoday.com/directory/banks-and-credit-unions', 'verify-address', 'CIBC has a chartered bank branch in Elliot Lake.'],
            ['elliot-lake-northern-credit-union', 'Northern Credit Union (Elliot Lake)', 'elliot-lake', 'banking', 'https://www.northerncu.com/', 'verify-address', 'Northern Credit Union has a credit union branch in Elliot Lake.'],
            ['elliot-lake-foodland', 'Foodland Elliot Lake', 'elliot-lake', 'groceries-and-food', 'https://www.foodland.ca/', 'verify-address', 'Foodland Elliot Lake is a Sobeys-banner supermarket at Pearson Plaza in Elliot Lake.'],
            ['elliot-lake-no-frills', 'No Frills (Elliot Lake)', 'elliot-lake', 'groceries-and-food', 'https://www.nofrills.ca/', 'verify-address', 'No Frills in Elliot Lake is a discount supermarket with an in-store pharmacy.'],
            ['elliot-lake-canadian-tire', 'Canadian Tire (Elliot Lake)', 'elliot-lake', 'retail-and-hardware', 'https://www.canadiantire.ca/', 'verify-address', 'Canadian Tire has a store in Elliot Lake.'],
            ['elliot-lake-city-hall', 'City of Elliot Lake, City Hall', 'elliot-lake', 'government-services', 'https://www.elliotlake.ca/en/city-hall/city-hall.aspx', 'verified', 'Elliot Lake City Hall is the municipal government office, at 45 Hillside Dr N.'],
            ['elliot-lake-library', 'Elliot Lake Public Library', 'elliot-lake', 'everyday-services', 'https://www.elliotlake.ca/en/library/index.aspx', 'verified', 'The Elliot Lake Public Library is the municipal public library, at 40 Hillside Dr S.'],
            ['elliot-lake-serviceontario', 'ServiceOntario Elliot Lake', 'elliot-lake', 'government-services', 'https://www.ontario.ca/locations/serviceontario/hillsdale-and-spruce-elliot-lake/', 'verified', 'ServiceOntario in Elliot Lake provides provincial services including health card, driver and vehicle, and licences, at 50 Hillside Drive North.'],
            ['elliot-lake-service-canada', 'Service Canada Centre, Elliot Lake', 'elliot-lake', 'government-services', 'https://offices.service.canada.ca/en/Office/3751', 'verified', 'The Service Canada Centre in Elliot Lake provides federal programs including EI, CPP, SIN and passports.'],
            ['elliot-lake-adsab-ontario-works', 'Algoma District Services Administration Board, Ontario Works (Elliot Lake)', 'elliot-lake', 'income-support', 'https://www.adsab.on.ca/', 'verify-address', 'The Algoma District Services Administration Board delivers Ontario Works for the Elliot Lake area.'],
            ['elliot-lake-food-bank', 'Elliot Lake Emergency Food Bank', 'elliot-lake', 'food-security', 'https://211north.ca/record/65299340/', 'verified', 'The Elliot Lake Emergency Food Bank provides emergency food supplies up to 12 times a year and Christmas hampers, at 23 Timber Rd.'],
            ['elliot-lake-apo-way-a-in', "Apo-way-a-in Wigamin (Mississauga First Nation Women's Shelter)", 'elliot-lake', 'child-and-family', 'https://www.algomapublichealth.com/', 'verify-address', "Apo-way-a-in Wigamin is a women's shelter associated with Mississauga First Nation serving the Elliot Lake area, with a 24-hour crisis line."],

            // Sault Ste. Marie (urban anchor; resources already in the graph are not repeated).
            ['ssm-sault-area-hospital', 'Sault Area Hospital', 'sault-ste-marie', 'primary-health', 'https://sah.on.ca/contact/', 'verified', 'Sault Area Hospital is the full-service acute-care hospital for Sault Ste. Marie with a 24/7 emergency department, and the regional referral hospital for the Algoma district, at 750 Great Northern Road.'],
            ['ssm-nmninoeyaa-ahac', "N'Mninoeyaa Aboriginal Health Access Centre", 'sault-ste-marie', 'primary-health', 'https://maamwesying.ca/nmninoeyaa-aboriginal-health-access-centre/', 'verified', "N'Mninoeyaa Aboriginal Health Access Centre is the access-centre arm of Maamwesying, offering team-based primary care, mental wellness and traditional health, with a site at the Indigenous Friendship Centre in Sault Ste. Marie, administered from Cutler."],
            ['ssm-baawaating-fht', 'Baawaating Family Health Team', 'sault-ste-marie', 'primary-health', 'https://baawaatingfht.ca/', 'verify-address', 'Baawaating Family Health Team is an interdisciplinary family health team in Sault Ste. Marie focused on the Indigenous population, serving Indigenous and non-Indigenous patients.'],
            ['ssm-mno-metis-council', 'Metis Nation of Ontario, Historic Sault Ste. Marie Metis Council', 'sault-ste-marie', 'child-and-family', 'https://www.metisnation.org/community-councils/council-contacts/', 'verify-address', 'The Historic Sault Ste. Marie Metis Council is the local Metis Nation of Ontario council and Metis centre, a cultural space and social-program hub for Metis citizens in Sault Ste. Marie.'],
            ['ssm-service-canada', 'Service Canada Centre, Sault Ste. Marie', 'sault-ste-marie', 'government-services', 'https://offices.service.canada.ca/en/Office/3546', 'verified', 'The Service Canada Centre in Sault Ste. Marie is the federal in-person centre for EI, CPP, SIN and passports, at 22 Bay Street, 1st Floor.'],
            ['ssm-serviceontario', 'ServiceOntario, Sault Ste. Marie (Queen and Elgin)', 'sault-ste-marie', 'government-services', 'https://www.ontario.ca/locations/serviceontario/queen-and-elgin-sault-ste-marie/', 'verified', 'ServiceOntario in Sault Ste. Marie is the provincial counter for health cards and driver and vehicle licensing, at 420 Queen Street East, Unit 101.'],
            ['ssm-sault-college', 'Sault College', 'sault-ste-marie', 'education-youth', 'https://www.saultcollege.ca/', 'verified', 'Sault College is a public college offering diplomas, degrees and certificates, with trades, health and applied programs, at 443 Northern Avenue East.'],
            ['ssm-algoma-university', 'Algoma University', 'sault-ste-marie', 'education-youth', 'https://algomau.ca/', 'verified', 'Algoma University is a public university with a special mission related to Anishinaabe education and the former Shingwauk residential school site, at 1520 Queen Street East.'],
            ['ssm-library', 'Sault Ste. Marie Public Library, Centennial branch', 'sault-ste-marie', 'everyday-services', 'https://ssmpl.ca/', 'verified', 'The Centennial (main) branch of the Sault Ste. Marie Public Library is the main branch of the city library system, at 50 East Street.'],
            ['ssm-soup-kitchen', 'Soup Kitchen Community Centre', 'sault-ste-marie', 'food-security', 'https://www.soupkitchencommunitycentre.org/', 'verified', 'The Soup Kitchen Community Centre in Sault Ste. Marie is a community soup kitchen and food-support centre with weekday meals and donation-based food assistance, at 172 James Street.'],
            ['ssm-ontario-northland', 'Ontario Northland Bus, Sault Ste. Marie station', 'sault-ste-marie', 'transportation', 'https://www.ontarionorthland.ca/en/station/sault-ste-marie', 'verified', 'Ontario Northland provides intercity motorcoach service from the Sault Ste. Marie station linking the Sault to Sudbury and Thunder Bay, the main public ground link along the corridor.'],
            ['ssm-banking', 'Banking, Sault Ste. Marie', 'sault-ste-marie', 'banking', 'https://www.ssmcoc.com/list/category/financial-institutions-68', 'verified', 'All five major Canadian banks (RBC, TD, BMO, CIBC, Scotiabank) have branches in Sault Ste. Marie.'],

            // Greater Sudbury (urban anchor; resources already in the graph are not repeated).
            ['sudbury-health-sciences-north', 'Health Sciences North', 'greater-sudbury', 'primary-health', 'https://hsnsudbury.ca/en/About-Us', 'verified', 'Health Sciences North is the regional hospital and academic tertiary-care centre for Northeastern Ontario, with regional cardiac, oncology, nephrology, trauma and rehabilitation programs, at 41 Ramsey Lake Road in Sudbury.'],
            ['sudbury-cancer-centre', 'Shirley and Jim Fielding Northeast Cancer Centre', 'greater-sudbury', 'primary-health', 'https://hsnsudbury.ca/en/Services-and-Specialties/Cancer-Care', 'verified', 'The Shirley and Jim Fielding Northeast Cancer Centre at Health Sciences North is the regional cancer program providing radiation, chemotherapy and supportive care for Northeastern Ontario.'],
            ['sudbury-mno-metis-council', 'Metis Nation of Ontario, Sudbury Metis Council', 'greater-sudbury', 'child-and-family', 'http://sudburymetiscouncil.ca/', 'verify-address', 'The Sudbury Metis Council is a Metis Nation of Ontario regional council offering education, employment and training, community support, and lands and resources services for Metis citizens, at 875 Notre Dame Ave, Unit 102.'],
            ['sudbury-service-canada', 'Service Canada, Sudbury Centre', 'greater-sudbury', 'government-services', 'https://offices.service.canada.ca/en/Office/3612', 'verified', 'The Service Canada Sudbury Centre is the federal centre for EI, CPP, SIN, passports and federal benefits, at 19 Lisgar Street.'],
            ['sudbury-serviceontario', 'ServiceOntario, Sudbury (Rainbow Centre)', 'greater-sudbury', 'government-services', 'https://www.ontario.ca/locations/serviceontario/elm-and-beech-sudbury/', 'verified', 'ServiceOntario in Sudbury provides provincial counters for health cards, driver and vehicle and other services, at 40 Elm Street, Rainbow Centre.'],
            ['sudbury-ontario-works', 'City of Greater Sudbury, Ontario Works', 'greater-sudbury', 'income-support', 'https://www.greatersudbury.ca/live/ontario-works1/', 'verified', 'The City of Greater Sudbury Ontario Works office delivers financial and employment assistance, at 199 Larch Street, 9th Floor.'],
            ['sudbury-cambrian-college', 'Cambrian College', 'greater-sudbury', 'education-youth', 'https://cambriancollege.ca/', 'verified', 'Cambrian College is an English-language college in Sudbury; its Wabnode Centre provides cultural space and supports for Indigenous learners, at 1400 Barrydowne Road.'],
            ['sudbury-laurentian-university', 'Laurentian University', 'greater-sudbury', 'education-youth', 'https://laurentian.ca/', 'verified', 'Laurentian University is Sudbury\'s university, with Indigenous STEM and mining initiatives and NOSM pathways, at 935 Ramsey Lake Road.'],
            ['sudbury-library', 'Greater Sudbury Public Library, Mackenzie branch', 'greater-sudbury', 'everyday-services', 'https://www.sudburylibraries.ca/en/about-us/main-public-library.aspx', 'verified', 'The Mackenzie (main) branch of the Greater Sudbury Public Library is the main branch of the municipal library system, at 74 Mackenzie Street.'],
            ['sudbury-food-bank', 'Sudbury Food Bank', 'greater-sudbury', 'food-security', 'https://www.sudburyfoodbank.ca/', 'verified', 'The Sudbury Food Bank is Greater Sudbury\'s food bank, supplying member agencies across the district, at 1105 Webbwood Drive.'],
            ['sudbury-airport', 'Greater Sudbury Airport', 'greater-sudbury', 'transportation', 'https://flysudbury.ca/', 'verified', 'Greater Sudbury Airport is the region\'s airport, northeast of downtown on Municipal Road 86.'],
            ['sudbury-banking', 'Banking, Greater Sudbury', 'greater-sudbury', 'banking', 'https://www.greatersudbury.ca/', 'verified', 'All major Canadian banks (RBC, TD, Scotiabank, BMO, CIBC) and credit unions operate branches across Greater Sudbury, the regional commercial centre.'],
        ];
    }

    /**
     * The corridor banking gap, modelled as a banking-topic service node so it
     * survives the retriever's topic-precision filter alongside the real banks
     * and ranks as the headline answer to "where is the nearest bank". No
     * organization (it is a gap, not a front door); place is Massey.
     *
     * @return array{slug: string, name: string, place: string, topic: string, source_url: string, text: string}
     */
    public static function bankingGap(): array
    {
        return [
            'slug' => 'corridor-banking-gap',
            'name' => 'Nearest banking for the Massey, Webbwood and Spanish corridor',
            'place' => 'massey',
            'topic' => 'banking',
            'source_url' => 'https://www.sables-spanish.ca/business-directory/',
            'text' => 'Residents along the Highway 17 corridor report there is no bank, credit union or confirmed standalone ATM in Massey, Webbwood or Spanish. The nearest financial institutions are in Espanola, including the Espanola and District Credit Union, and in Elliot Lake, which has RBC, TD, CIBC, Scotiabank and Northern Credit Union. Elliot Lake is about 45 minutes from Sagamok. This is a resident report, not an official statement.',
        ];
    }

    /**
     * Community-reported gaps that are not banking, attached to their place as
     * general content (reachable from any vantage), each clearly attributed to
     * residents and carrying a source. The unconfirmed Spanish claims are noted
     * here as not listed, never as resources.
     *
     * @return list<array{slug: string, place: string, name: string, heading: string, source_url: string, text: string}>
     */
    public static function placeGaps(): array
    {
        return [
            [
                'slug' => 'massey-services-gap',
                'place' => 'massey',
                'name' => 'What Massey does not have (resident-reported)',
                'heading' => 'Gaps in Massey',
                'source_url' => 'https://www.sables-spanish.ca/business-directory/',
                'text' => 'Residents report Massey has no dollar store, laundromat or thrift store in citable sources, and no standalone storefront bakery beyond the Little Brew Cafe and the rural Mennonite home bakeries near Walford. For these, residents travel to Espanola or Elliot Lake. This is a resident report, not an official statement.',
            ],
            [
                'slug' => 'webbwood-services-gap',
                'place' => 'webbwood',
                'name' => 'What Webbwood does not have (resident-reported)',
                'heading' => 'Gaps in Webbwood',
                'source_url' => 'https://www.sables-spanish.ca/business-directory/',
                'text' => 'Residents report Webbwood is very small: the Tom Stewart general store is effectively the only commerce, with no bank or ATM, no pharmacy, no health clinic, and no grocery beyond the general store. Residents use the Massey Medical Clinic and rely on Massey and Espanola for most needs. This is a resident report, not an official statement.',
            ],
            [
                'slug' => 'spanish-services-gap',
                'place' => 'spanish',
                'name' => 'What is and is not confirmed in Spanish',
                'heading' => 'Gaps and unconfirmed claims in Spanish',
                'source_url' => 'https://www.townofspanish.com/',
                'text' => 'Spanish has a grocery, variety and convenience stores, a pizza place, a marina with a public laundromat, a post office and the town office. A regional tourism page also claims the downtown has a bank, a hardware store, and medical and dental services, but these could not be confirmed against a specific listing, so they are not listed here. The nearest hospital is the North Shore Health Network in Blind River, and regional Indigenous primary care is through Maamwesying North Shore Community Health Services. This is a resident and editorial note, not an official statement.',
            ],
        ];
    }

    // ---- Merge helpers consumed by GraphSeedData -------------------------------

    /**
     * Coordinate overrides to apply to existing place rows, by slug.
     *
     * @return array<string, array{lat: string, lng: string, travel_note: string}>
     */
    public static function placeCoordinates(): array
    {
        return self::PLACE_COORDS;
    }

    /**
     * New place rows (slug, name, coordinates) the corridor introduces.
     *
     * @return list<array<string, string>>
     */
    public static function newPlaces(): array
    {
        $rows = [];
        foreach (self::NEW_PLACES as $slug => $name) {
            $coords = self::PLACE_COORDS[$slug] ?? ['lat' => '', 'lng' => '', 'travel_note' => ''];
            $rows[] = ['slug' => $slug, 'name' => $name] + $coords;
        }

        return $rows;
    }

    /**
     * Place slugs to add to the North Shore catchment (Sagamok and the other
     * North Shore nations), so corridor resources answer from those vantages.
     *
     * @return list<string>
     */
    public static function regionAdditions(): array
    {
        return self::REGION_ADDITIONS;
    }

    /**
     * Organization rows (one front door per resource).
     *
     * @return list<array<string, string>>
     */
    public static function organizations(): array
    {
        $rows = [];
        foreach (self::resources() as [$slug, $name, , , $url]) {
            $rows[] = ['slug' => $slug, 'name' => $name, 'source_url' => $url];
        }

        return $rows;
    }

    /**
     * Service rows (one per resource) plus the synthetic banking-gap service.
     *
     * @return list<array<string, string>>
     */
    public static function services(): array
    {
        $rows = [];
        foreach (self::resources() as [$slug, $name, $place, $topic, $url]) {
            $rows[] = ['slug' => $slug, 'name' => $name, 'provided_by' => $slug, 'located_at' => $place, 'has_topic' => $topic, 'source_url' => $url];
        }
        $gap = self::bankingGap();
        $rows[] = ['slug' => $gap['slug'], 'name' => $gap['name'], 'provided_by' => '', 'located_at' => $gap['place'], 'has_topic' => $gap['topic'], 'source_url' => $gap['source_url']];

        return $rows;
    }

    /**
     * Curated grounding chunks: one per resource (with the unconfirmed-address
     * note where the seed marks verify-address), the banking-gap chunk, and the
     * place gap chunks. Keyed by the curated prefix so app:ingest never prunes
     * them.
     *
     * @return list<array<string, string>>
     */
    public static function curatedChunks(): array
    {
        $rows = [];
        foreach (self::resources() as [$slug, $name, $place, $topic, $url, $confidence, $grounding]) {
            $text = $grounding . ($confidence === 'verify-address' ? self::VERIFY_ADDRESS_NOTE : '');
            $rows[] = [
                'chunk_key' => GraphSeedData::CURATED_KEY_PREFIX . $slug,
                'source_url' => $url,
                'title' => $name,
                'heading' => self::heading($topic, $place),
                'text' => $text,
                'entity_type' => 'service',
                'entity_id' => $slug,
            ];
        }

        // The banking gap (service-backed so it survives topic precision).
        $gap = self::bankingGap();
        $rows[] = [
            'chunk_key' => GraphSeedData::CURATED_KEY_PREFIX . $gap['slug'],
            'source_url' => $gap['source_url'],
            'title' => $gap['name'],
            'heading' => 'Banking near the Highway 17 corridor',
            'text' => $gap['text'],
            'entity_type' => 'service',
            'entity_id' => $gap['slug'],
        ];

        // The non-banking place gaps (general content, attached to the place).
        foreach (self::placeGaps() as $g) {
            $rows[] = [
                'chunk_key' => GraphSeedData::CURATED_KEY_PREFIX . $g['slug'],
                'source_url' => $g['source_url'],
                'title' => $g['name'],
                'heading' => $g['heading'],
                'text' => $g['text'],
                'entity_type' => 'place',
                'entity_id' => $g['place'],
            ];
        }

        return $rows;
    }

    private static function heading(string $topic, string $place): string
    {
        $topicLabel = self::TOPIC_LABELS[$topic] ?? $topic;
        $placeName = self::PLACE_NAMES[$place] ?? $place;

        return $topicLabel . ' in ' . $placeName;
    }
}
