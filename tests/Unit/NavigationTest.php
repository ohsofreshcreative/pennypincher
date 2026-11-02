<?php

namespace Tests\Unit;

use App\PennyPincher\Support\Feature;
use App\PennyPincher\Support\Navigation;
use Tests\TestCase;

class NavigationTest extends TestCase
{
	public function testEveryLegacyModuleHasExactlyOnePlaceInNavigation(): void
	{
		$placed = [];

		foreach (Navigation::GROUPS as $group => $definition) {
			foreach ($definition['items'] as $key => $item) {
				$this->assertFalse(isset($placed[$key]), "Moduł {$key} jest w dwóch sekcjach.");
				$placed[$key] = $group;
			}
		}

		// 16 modułów projektu + Przegląd + Projekty = 18 pozycji starego menu.
		$legacy = [...Navigation::SECTIONS, 'overview', 'projects'];
		sort($legacy);
		$keys = array_keys($placed);
		sort($keys);
		$this->assertSame($legacy, $keys);
		$this->assertCount(6, Navigation::GROUPS);
	}

	public function testItemPathsMatchLegacyAddresses(): void
	{
		foreach (Navigation::GROUPS as $definition) {
			foreach ($definition['items'] as $key => $item) {
				$expected = $key === 'overview' ? '' : $key;
				$this->assertSame($expected, $item['path'], "Podwidok {$key} musi zostać pod starym adresem.");
				$this->assertSame($key === 'projects', ! empty($item['global']));
			}
		}
	}

	public function testAliasesLeadToFirstSubviewAndDoNotShadowModules(): void
	{
		foreach (Navigation::ALIASES as $alias => $target) {
			$this->assertTrue(isset(Navigation::GROUPS[$alias]), "Alias {$alias} nie jest sekcją główną.");
			$this->assertFalse(in_array($alias, Navigation::SECTIONS, true), "Alias {$alias} zasłoniłby moduł.");
			$items = Navigation::GROUPS[$alias]['items'];
			$first = reset($items);
			$this->assertSame($first['path'], $target);
			$this->assertSame($target, Navigation::resolve($alias));
		}

		// Sekcje główne bez aliasu mają już moduł o tej samej nazwie, który jest ich pierwszym podwidokiem.
		foreach (array_diff(array_keys(Navigation::GROUPS), array_keys(Navigation::ALIASES)) as $group) {
			$this->assertSame($group, array_key_first(Navigation::GROUPS[$group]['items']));
		}

		$this->assertSame('campaigns', Navigation::resolve('campaigns'));
	}

	public function testGroupOfSection(): void
	{
		$this->assertSame('command-center', Navigation::groupOf('overview'));
		$this->assertSame('campaigns', Navigation::groupOf('planner'));
		$this->assertSame('optimization', Navigation::groupOf('budget-leaks'));
		$this->assertSame('action-plan', Navigation::groupOf('changes'));
		$this->assertSame('conversions', Navigation::groupOf('conversion-management'));
		$this->assertSame('administration', Navigation::groupOf('projects'));
		$this->assertNull(Navigation::groupOf(null));
		$this->assertNull(Navigation::groupOf('optimization'));
	}

	public function testFeatureFlagsAreOffByDefaultAndFollowConstant(): void
	{
		$this->assertFalse(Feature::enabled('pp2_unit_probe_off'));

		define('PP2_UNIT_PROBE_ON', true);
		$this->assertTrue(Feature::enabled('pp2_unit_probe_on'));
	}
}
