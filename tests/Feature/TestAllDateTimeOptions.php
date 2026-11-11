<?php

namespace Tests\Feature;

use App\Services\DateService;
use App\Services\TimezoneCatalog;
use DateTimeZone;
use Tests\TestCase;
use Tests\Traits\WithAuthentication;

class TestAllDateTimeOptions extends TestCase
{
    use WithAuthentication;

    public function test_check_timezone_validity()
    {
        $this->attemptAuthenticate();

        $timezones = config( 'nexopos.timezones' );

        $this->assertArrayNotHasKey( 'Canada/Pacific', $timezones );
        $this->assertArrayHasKey( 'America/Vancouver', $timezones );

        $canonical = timezone_identifiers_list( DateTimeZone::ALL );

        foreach ( $timezones as $zone => $name ) {
            $this->assertContains(
                $zone,
                $canonical,
                "Offered timezone [{$zone}] must be a canonical IANA identifier."
            );
            $this->assertTrue( TimezoneCatalog::isConstructible( $zone ) );
            $this->assertInstanceOf( DateService::class, new DateService( 'now', $zone ) );
        }
    }

    public function test_legacy_canada_pacific_is_canonicalized_to_vancouver()
    {
        $this->assertSame( 'America/Vancouver', TimezoneCatalog::canonicalize( 'Canada/Pacific' ) );
        $this->assertSame( 'America/Vancouver', TimezoneCatalog::canonicalize( 'America/Vancouver' ) );
        $this->assertTrue( TimezoneCatalog::isConstructible( 'Canada/Pacific' ) );
        $this->assertInstanceOf(
            DateService::class,
            new DateService( 'now', TimezoneCatalog::canonicalize( 'Canada/Pacific' ) )
        );

        $vancouver = new DateTimeZone( 'America/Vancouver' );
        $legacy = TimezoneCatalog::canonicalize( 'Canada/Pacific' );
        $this->assertSame( $vancouver->getName(), ( new DateTimeZone( $legacy ) )->getName() );
    }

    public function test_compare_dates()
    {
        $this->assertSame(
            now()->toFormattedDateString( 'Y-m-d H:m' ),
            ns()->date->toFormattedDateString( 'Y-m-d H:m' )
        );
    }
}
