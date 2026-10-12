<?php

namespace App\PennyPincher\Services\Planner;

use App\PennyPincher\Domain\Planner\MatchType;
use App\PennyPincher\Domain\Planner\PlanStatus;
use App\PennyPincher\Support\Format;

/**
 * Eksport planu kampanii: CSV (UTF-8 z BOM, separator „;”, zabezpieczony przed CSV/formula injection)
 * i czytelny dokument HTML do druku. To własny format Penny Pincher – NIE jest to format importu Google Ads Editor
 * (zgodność z Edytorem nie została zweryfikowana, więc jej nie deklarujemy).
 */
class PlanExporter
{
	public const COLUMNS = ['Typ wiersza', 'Plan', 'Kampania', 'Budżet miesięczny kampanii', 'Waluta', 'Grupa reklam', 'Słowo kluczowe / treść', 'Typ dopasowania', 'Poziom wykluczenia', 'Intencja', 'Opportunity Score', 'Pewność', 'Szac. CPC', 'Waluta CPC', 'Wolumen / mies.', 'Źródło', 'URL docelowy'];

	public static function csv(array $plan, array $tree): string
	{
		$rows = [self::COLUMNS];
		$currency = (string) $plan['currency'];
		$base = fn (string $type, ?array $campaign = null, ?array $group = null) => [
			$type, $plan['name'], $campaign['name'] ?? '', isset($campaign['budget_micros']) && $campaign['budget_micros'] !== null ? self::amount((int) $campaign['budget_micros']) : '', $currency, $group['name'] ?? '',
		];

		$rows[] = [...$base('Plan'), $plan['service'] ?? '', '', '', '', '', '', '', '', '', PlanStatus::LABELS[$plan['status']] ?? '', ''];

		foreach ($tree['campaigns'] as $campaign) {
			$rows[] = [...$base('Kampania', $campaign), '', '', '', '', '', '', '', '', '', $campaign['kind'] === 'brand' ? 'Kampania brandowa' : 'Kampania w wyszukiwarce', $campaign['final_url'] ?? ''];

			foreach ($campaign['groups'] as $group) {
				$rows[] = [...$base('Grupa reklam', $campaign, $group), '', '', '', '', '', '', '', '', '', '', $group['final_url'] ?? ''];

				foreach ($group['keywords'] as $keyword) {
					$cpc = $keyword['cpc_micros'] !== null ? self::amount((int) $keyword['cpc_micros']) : '';
					$rows[] = [...$base('Słowo kluczowe', $campaign, $group), $keyword['phrase'], $keyword['match_type'], '', $keyword['intent'] ?? '', $keyword['score'] ?? '', $keyword['confidence'] ?? '', $cpc, $keyword['cpc_currency'] ?? '', $keyword['search_volume'] ?? '', $keyword['source'], ''];
				}

				foreach ($group['ads'] as $ad) {
					foreach ($ad['headlines'] as $i => $headline) {
						$rows[] = [...$base('Nagłówek ' . ($i + 1), $campaign, $group), $headline, '', '', '', '', '', '', '', '', '', $ad['final_url'] ?? ''];
					}

					foreach ($ad['descriptions'] as $i => $description) {
						$rows[] = [...$base('Opis ' . ($i + 1), $campaign, $group), $description, '', '', '', '', '', '', '', '', '', ''];
					}

					if ($ad['path1'] || $ad['path2']) {
						$rows[] = [...$base('Ścieżka URL', $campaign, $group), trim(($ad['path1'] ?? '') . '/' . ($ad['path2'] ?? ''), '/'), '', '', '', '', '', '', '', '', '', ''];
					}
				}
			}
		}

		foreach ($tree['negatives'] as $negative) {
			$campaign = $negative['plan_campaign_id'] !== null ? ($tree['campaigns'][(int) $negative['plan_campaign_id']] ?? null) : null;
			$group = $campaign && $negative['plan_ad_group_id'] !== null ? ($campaign['groups'][(int) $negative['plan_ad_group_id']] ?? null) : null;
			$rows[] = [...$base('Wykluczenie', $campaign, $group), $negative['phrase'], $negative['match_type'], PlanStatus::LEVELS[$negative['level']] ?? $negative['level'], '', '', '', '', '', '', $negative['source'], ''];
		}

		$out = "\xEF\xBB\xBF";

		foreach ($rows as $row) {
			$out .= implode(';', array_map([self::class, 'cell'], $row)) . "\r\n";
		}

		return $out;
	}

	/**
	 * Komórka CSV: wartości zaczynające się od =, +, -, @, tabulatora lub CR dostają apostrof (ochrona przed
	 * wykonaniem formuły w arkuszu), a całość jest w cudzysłowie z podwojonymi cudzysłowami.
	 */
	public static function cell(mixed $value): string
	{
		$value = (string) $value;

		if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
			$value = "'" . $value;
		}

		return '"' . str_replace('"', '""', $value) . '"';
	}

	public static function filename(array $plan, string $extension): string
	{
		$slug = sanitize_title((string) $plan['name']) ?: 'plan';

		return 'plan-kampanii-' . $slug . '-' . gmdate('Y-m-d') . '.' . $extension;
	}

	private static function amount(int $micros): string
	{
		return number_format($micros / 1_000_000, 2, ',', '');
	}

	public static function matchLabel(string $type): string
	{
		return MatchType::LABELS[$type] ?? $type;
	}

	public static function money(?int $micros, string $currency): string
	{
		return Format::money($micros, $currency);
	}
}
