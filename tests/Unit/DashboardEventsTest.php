<?php

namespace Tests\Unit;

use App\PennyPincher\Services\DashboardService;
use Tests\TestCase;

class DashboardEventsTest extends TestCase
{
	public function testLatestEventsAreNewestFirstStableAndLimited(): void
	{
		$events = DashboardService::latestEvents([
			['at' => '2026-10-01 10:00:00', 'title' => 'import'],
			null,
			['at' => '2026-10-03 08:00:00', 'title' => 'sync'],
			['at' => '2026-10-02 12:00:00', 'title' => 'audit-a'],
			['at' => '2026-10-02 12:00:00', 'title' => 'audit-b'],
		], 3);

		$this->assertSame(['sync', 'audit-a', 'audit-b'], array_column($events, 'title'));
		$this->assertSame([], DashboardService::latestEvents([null], 5));
	}
}
