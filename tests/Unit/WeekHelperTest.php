<?php
/**
 * @package LEAStudios\HelpScoutAIDashboard
 */

declare(strict_types=1);

namespace LEAStudios\HelpScoutAIDashboard\Tests\Unit;

use LEAStudios\HelpScoutAIDashboard\Shared\Week_Helper;
use LEAStudios\Tests\TestCase;

final class WeekHelperTest extends TestCase {

	public function test_wednesday_central_returns_next_thursday(): void {
		// 2025-05-21 is a Wednesday in Central time.
		$this->assertSame( '2025-05-22', Week_Helper::week_ending( '2025-05-21T15:00:00-05:00' ) );
	}

	public function test_thursday_late_central_returns_same_thursday(): void {
		// 2025-05-22 23:59 CT is still that Thursday.
		$this->assertSame( '2025-05-22', Week_Helper::week_ending( '2025-05-22T23:59:00-05:00' ) );
	}

	public function test_friday_early_central_returns_next_thursday(): void {
		// 2025-05-23 00:01 CT rolls into the next week.
		$this->assertSame( '2025-05-29', Week_Helper::week_ending( '2025-05-23T00:01:00-05:00' ) );
	}

	public function test_utc_timestamp_converted_to_central_first(): void {
		// 2025-05-23 04:00 UTC = 2025-05-22 23:00 CT (still Thursday in CT).
		$this->assertSame( '2025-05-22', Week_Helper::week_ending( '2025-05-23T04:00:00Z' ) );
	}

	public function test_dst_spring_forward_does_not_skip_week(): void {
		// 2025-03-09 02:00 CT is DST spring-forward; week ending is 2025-03-13 Thursday.
		$this->assertSame( '2025-03-13', Week_Helper::week_ending( '2025-03-09T08:00:00Z' ) );
	}
}
