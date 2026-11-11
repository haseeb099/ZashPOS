<?php

namespace App\Services;

use DateTimeZone;

/**
 * Prefer IANA Zone identifiers over obsolescent backward-compatibility links.
 * Legacy names such as Canada/Pacific can fail on environments whose tzdata
 * omits the optional "backward" links (seen on GitHub Actions Ubuntu + PHP).
 */
class TimezoneCatalog
{
    /**
     * Map obsolescent Link names to their current IANA Zone targets.
     * Sourced from the IANA tzdb "backward" file (Canada/Pacific → America/Vancouver, etc.).
     *
     * @var array<string, string>
     */
    public const LEGACY_MAP = [
        'Africa/Asmera' => 'Africa/Asmara',
        'Africa/Timbuktu' => 'Africa/Abidjan',
        'America/Atka' => 'America/Adak',
        'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
        'America/Catamarca' => 'America/Argentina/Catamarca',
        'America/Coral_Harbour' => 'America/Panama',
        'America/Cordoba' => 'America/Argentina/Cordoba',
        'America/Ensenada' => 'America/Tijuana',
        'America/Fort_Wayne' => 'America/Indiana/Indianapolis',
        'America/Godthab' => 'America/Nuuk',
        'America/Indianapolis' => 'America/Indiana/Indianapolis',
        'America/Jujuy' => 'America/Argentina/Jujuy',
        'America/Knox_IN' => 'America/Indiana/Knox',
        'America/Louisville' => 'America/Kentucky/Louisville',
        'America/Mendoza' => 'America/Argentina/Mendoza',
        'America/Montreal' => 'America/Toronto',
        'America/Nipigon' => 'America/Toronto',
        'America/Pangnirtung' => 'America/Iqaluit',
        'America/Porto_Acre' => 'America/Rio_Branco',
        'America/Rainy_River' => 'America/Winnipeg',
        'America/Rosario' => 'America/Argentina/Cordoba',
        'America/Shiprock' => 'America/Denver',
        'America/Thunder_Bay' => 'America/Toronto',
        'America/Virgin' => 'America/Puerto_Rico',
        'America/Yellowknife' => 'America/Edmonton',
        'Antarctica/South_Pole' => 'Pacific/Auckland',
        'Asia/Ashkhabad' => 'Asia/Ashgabat',
        'Asia/Calcutta' => 'Asia/Kolkata',
        'Asia/Choibalsan' => 'Asia/Ulaanbaatar',
        'Asia/Chongqing' => 'Asia/Shanghai',
        'Asia/Chungking' => 'Asia/Shanghai',
        'Asia/Dacca' => 'Asia/Dhaka',
        'Asia/Harbin' => 'Asia/Shanghai',
        'Asia/Istanbul' => 'Europe/Istanbul',
        'Asia/Kashgar' => 'Asia/Urumqi',
        'Asia/Katmandu' => 'Asia/Kathmandu',
        'Asia/Macao' => 'Asia/Macau',
        'Asia/Rangoon' => 'Asia/Yangon',
        'Asia/Saigon' => 'Asia/Ho_Chi_Minh',
        'Asia/Tel_Aviv' => 'Asia/Jerusalem',
        'Asia/Thimbu' => 'Asia/Thimphu',
        'Asia/Ujung_Pandang' => 'Asia/Makassar',
        'Asia/Ulan_Bator' => 'Asia/Ulaanbaatar',
        'Atlantic/Faeroe' => 'Atlantic/Faroe',
        'Atlantic/Jan_Mayen' => 'Europe/Berlin',
        'Australia/ACT' => 'Australia/Sydney',
        'Australia/Canberra' => 'Australia/Sydney',
        'Australia/Currie' => 'Australia/Hobart',
        'Australia/LHI' => 'Australia/Lord_Howe',
        'Australia/NSW' => 'Australia/Sydney',
        'Australia/North' => 'Australia/Darwin',
        'Australia/Queensland' => 'Australia/Brisbane',
        'Australia/South' => 'Australia/Adelaide',
        'Australia/Tasmania' => 'Australia/Hobart',
        'Australia/Victoria' => 'Australia/Melbourne',
        'Australia/West' => 'Australia/Perth',
        'Australia/Yancowinna' => 'Australia/Broken_Hill',
        'Brazil/Acre' => 'America/Rio_Branco',
        'Brazil/DeNoronha' => 'America/Noronha',
        'Brazil/East' => 'America/Sao_Paulo',
        'Brazil/West' => 'America/Manaus',
        'Canada/Atlantic' => 'America/Halifax',
        'Canada/Central' => 'America/Winnipeg',
        'Canada/Eastern' => 'America/Toronto',
        'Canada/Mountain' => 'America/Edmonton',
        'Canada/Newfoundland' => 'America/St_Johns',
        'Canada/Pacific' => 'America/Vancouver',
        'Canada/Saskatchewan' => 'America/Regina',
        'Canada/Yukon' => 'America/Whitehorse',
        'Chile/Continental' => 'America/Santiago',
        'Chile/EasterIsland' => 'Pacific/Easter',
        'Europe/Belfast' => 'Europe/London',
        'Europe/Kiev' => 'Europe/Kyiv',
        'Europe/Nicosia' => 'Asia/Nicosia',
        'Europe/Tiraspol' => 'Europe/Chisinau',
        'Europe/Uzhgorod' => 'Europe/Kyiv',
        'Europe/Zaporozhye' => 'Europe/Kyiv',
        'Mexico/BajaNorte' => 'America/Tijuana',
        'Mexico/BajaSur' => 'America/Mazatlan',
        'Mexico/General' => 'America/Mexico_City',
    ];

    public static function canonicalize( string $timezone ): string
    {
        return self::LEGACY_MAP[ $timezone ] ?? $timezone;
    }

    /**
     * Drop obsolescent Link identifiers from the settings catalog so every
     * offered zone exists in PHP's default timezone list (no backward links).
     *
     * @param  array<string, string> $timezones
     * @return array<string, string>
     */
    public static function offerable( array $timezones ): array
    {
        $allowed = array_fill_keys( timezone_identifiers_list( DateTimeZone::ALL ), true );
        $result = [];

        foreach ( $timezones as $zone => $label ) {
            $resolved = self::canonicalize( (string) $zone );

            if ( ! isset( $allowed[ $resolved ] ) ) {
                continue;
            }

            if ( array_key_exists( $resolved, $result ) ) {
                continue;
            }

            $result[ $resolved ] = $zone === $resolved
                ? $label
                : str_replace( (string) $zone, $resolved, $label );
        }

        return $result;
    }

    public static function isConstructible( string $timezone ): bool
    {
        try {
            new DateTimeZone( self::canonicalize( $timezone ) );

            return true;
        } catch ( \Exception ) {
            return false;
        }
    }
}
