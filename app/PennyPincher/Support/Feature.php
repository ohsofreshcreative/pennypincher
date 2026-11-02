<?php

namespace App\PennyPincher\Support;

/**
 * Flagi funkcji przebudowy interfejsu (Penny Pincher 2.0). Domyślnie wyłączone.
 *
 * Włączenie: stała w wp-config.php (np. `define('PP2_NAVIGATION', true);`) albo filtr
 * `pennypincher/feature/{nazwa}` – filtr ma ostatnie słowo. Wyłączenie flagi przywraca stary widok;
 * trasy i dane są wspólne dla obu wersji.
 */
final class Feature
{
	public const NAVIGATION = 'pp2_navigation';

	public const DASHBOARD = 'pp2_dashboard';

	public const CAMPAIGNS = 'pp2_campaigns';

	public const OPTIMIZATION = 'pp2_optimization';

	public const ACTION_PLAN = 'pp2_action_plan';

	public const CONVERSIONS = 'pp2_conversions';

	public const MANAGEMENT = 'pp2_management';

	public const ASSISTANT = 'pp2_assistant';

	public static function enabled(string $name): bool
	{
		$constant = strtoupper($name);
		$default = defined($constant) && (bool) constant($constant);

		return (bool) apply_filters('pennypincher/feature/' . $name, $default);
	}
}
