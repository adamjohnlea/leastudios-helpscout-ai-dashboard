<?php
/**
 * Week-ending Thursday helpers, computed in America/Chicago (Central) time.
 *
 * A row's week-ending Thursday is the smallest Thursday >= its Central-time
 * date. Mirrors `week_ending_for` in the legacy build.py and
 * `weekEndingThursday` in the legacy dashboard JS.
 *
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers for bucketing CSV rows into week-ending Thursdays in Central time.
 */
final class Week_Helper {

	/**
	 * Memoized Central time zone instance.
	 *
	 * @return \DateTimeZone
	 */
	public static function central_tz(): \DateTimeZone {
		static $tz = null;
		if ( null === $tz ) {
			$tz = new \DateTimeZone( 'America/Chicago' );
		}
		return $tz;
	}

	/**
	 * Compute the week-ending Thursday (Central time) for an ISO timestamp.
	 *
	 * @param string $iso_timestamp ISO-8601 timestamp from the CSV's Date column.
	 *
	 * @return string|null Y-m-d (e.g. "2025-05-22") in Central time, or null on parse failure.
	 */
	public static function week_ending( string $iso_timestamp ): ?string {
		try {
			$dt = new \DateTimeImmutable( $iso_timestamp );
		} catch ( \Exception $e ) {
			return null;
		}

		$local = $dt->setTimezone( self::central_tz() );

		// PHP weekday via 'N': Mon=1..Thu=4..Sun=7. We need Thu=4.
		$weekday = (int) $local->format( 'N' );
		$offset  = ( 4 - $weekday + 7 ) % 7;
		$thu     = $local->modify( "+{$offset} days" );

		return $thu->format( 'Y-m-d' );
	}

	/**
	 * Y-m-d of the Central-time day for an ISO timestamp (used for date_min/date_max).
	 *
	 * @param string $iso_timestamp ISO-8601 timestamp.
	 *
	 * @return string|null Y-m-d in Central time, or null on parse failure.
	 */
	public static function central_day( string $iso_timestamp ): ?string {
		try {
			$dt = new \DateTimeImmutable( $iso_timestamp );
		} catch ( \Exception $e ) {
			return null;
		}
		return $dt->setTimezone( self::central_tz() )->format( 'Y-m-d' );
	}
}
