<?php

declare(strict_types=1);

namespace Platform\Tenancy\Support;

/**
 * TASK-MVP-016. The authoritative list of the 16 governorates of the
 * State of Palestine (11 West Bank + 5 Gaza Strip), used by
 * `Platform\Tenancy\Services\TenantProvisioner::
 * ensurePalestineGovernoratesSeeded()` to seed `country_states`/
 * `country_state_translations` for every newly provisioned tenant.
 *
 * SOURCE, disclosed honestly: names and ISO 3166-2:PS subdivision codes
 * as commonly published (matches the Palestinian Central Bureau of
 * Statistics' own governorate list and the ISO 3166-2:PS standard),
 * applied here from established public administrative-geography
 * knowledge - not fetched from a live registry during this task. The
 * English/Arabic governorate NAMES below are standard and
 * uncontroversial; the 3-letter CODE column is the one part carrying
 * that disclosed provenance caveat - cross-check against the official
 * ISO 3166 registry first if these codes are ever relied on for an
 * external integration requiring certified ISO compliance. Kept as a
 * single, isolated, easily-reviewed array specifically so that
 * cross-check is easy to do later without touching any other file.
 *
 * Deliberately a small value object, not a seeder class of its own -
 * this data is only ever consumed by the one provisioning step above.
 */
final class PalestineGovernorates
{
    /**
     * Keyed by a bare state code (matching this project's existing
     * `states.json` convention - e.g. `'AL'` for Alabama, not `'US-AL'`).
     *
     * @var array<string, array{ar: string, en: string}>
     */
    const ALL = [
        // West Bank (11)
        'JEN' => ['ar' => 'جنين', 'en' => 'Jenin'],
        'TBS' => ['ar' => 'طوباس', 'en' => 'Tubas'],
        'TKM' => ['ar' => 'طولكرم', 'en' => 'Tulkarm'],
        'NBS' => ['ar' => 'نابلس', 'en' => 'Nablus'],
        'QQA' => ['ar' => 'قلقيلية', 'en' => 'Qalqilya'],
        'SLT' => ['ar' => 'سلفيت', 'en' => 'Salfit'],
        'RBH' => ['ar' => 'رام الله والبيرة', 'en' => 'Ramallah and Al-Bireh'],
        'JRH' => ['ar' => 'أريحا والأغوار', 'en' => 'Jericho and Al Aghwar'],
        'JEM' => ['ar' => 'القدس', 'en' => 'Jerusalem'],
        'BTH' => ['ar' => 'بيت لحم', 'en' => 'Bethlehem'],
        'HBN' => ['ar' => 'الخليل', 'en' => 'Hebron'],

        // Gaza Strip (5)
        'NGZ' => ['ar' => 'شمال غزة', 'en' => 'North Gaza'],
        'GZA' => ['ar' => 'غزة', 'en' => 'Gaza'],
        'DEB' => ['ar' => 'دير البلح', 'en' => 'Deir al-Balah'],
        'KYS' => ['ar' => 'خان يونس', 'en' => 'Khan Yunis'],
        'RFH' => ['ar' => 'رفح', 'en' => 'Rafah'],
    ];
}
