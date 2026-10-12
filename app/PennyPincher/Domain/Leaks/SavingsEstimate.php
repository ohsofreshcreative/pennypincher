<?php

namespace App\PennyPincher\Domain\Leaks;

/**
 * Łączny szacunek potencjalnej oszczędności bez podwójnego liczenia nakładających się problemów.
 *
 * W każdej kampanii bierzemy największą z trzech wartości (a nie ich sumę):
 * - unia haseł objętych problemami (każde hasło liczone raz, nawet jeśli należy do kilku problemów),
 * - suma problemów słów kluczowych,
 * - suma problemów całej kampanii,
 * i ograniczamy ją do średnich miesięcznych wydatków kampanii. To szacunek, nie gwarancja przyszłych oszczędności.
 */
final class SavingsEstimate
{
	private const KEYWORD_RULES = ['BL201', 'BL202', 'BL203'];

	private const CAMPAIGN_RULES = ['BL301', 'BL302', 'BL303'];

	/**
	 * @param array<int, array<string, mixed>> $leaks wiersze budget_leaks (z evidence['terms'] i savings_micros)
	 * @param array<int, int> $campaignMonthlySpend id kampanii => średnie miesięczne wydatki w okresie
	 * @return array{total: int, by_campaign: array<int, int>}
	 */
	public static function total(array $leaks, array $campaignMonthlySpend): array
	{
		$terms = [];
		$keywords = [];
		$campaigns = [];

		foreach ($leaks as $leak) {
			if (($leak['status'] ?? 'new') === 'dismissed' || $leak['savings_micros'] === null) {
				continue;
			}

			$campaignId = (int) ($leak['campaign_id'] ?? 0);

			foreach ((array) ($leak['evidence']['terms'] ?? []) as $termId => [$termCampaign, $rate]) {
				$terms[(int) $termCampaign][(int) $termId] = (int) $rate;
			}

			if (in_array($leak['rule_id'], self::KEYWORD_RULES, true) && empty($leak['evidence']['terms'])) {
				$keywords[$campaignId] = ($keywords[$campaignId] ?? 0) + (int) $leak['savings_micros'];
			} elseif (in_array($leak['rule_id'], self::CAMPAIGN_RULES, true)) {
				$campaigns[$campaignId] = ($campaigns[$campaignId] ?? 0) + (int) $leak['savings_micros'];
			}
		}

		$byCampaign = [];

		foreach (array_unique([...array_keys($terms), ...array_keys($keywords), ...array_keys($campaigns)]) as $campaignId) {
			$value = max(array_sum($terms[$campaignId] ?? []), $keywords[$campaignId] ?? 0, $campaigns[$campaignId] ?? 0);

			if (isset($campaignMonthlySpend[$campaignId])) {
				$value = min($value, $campaignMonthlySpend[$campaignId]);
			}

			if ($value > 0) {
				$byCampaign[$campaignId] = $value;
			}
		}

		return ['total' => array_sum($byCampaign), 'by_campaign' => $byCampaign];
	}
}
