<?php

namespace App\PennyPincher\Domain\Leaks;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Projects\BusinessContext;

/**
 * Dane wejściowe analizy Budget Leaks – zbierane raz przez LeakContextBuilder, detektor ich tylko używa.
 */
final class LeakContext
{
	/**
	 * @param array<int, array<string, mixed>> $campaigns klucz = id kampanii; current/previous = metryki okresów
	 * @param array<int, array<string, mixed>> $adGroups klucz = id grupy
	 * @param array<int, array<string, mixed>> $terms wyszukiwane hasła z sumami (MetricsRepository::searchTermStats)
	 * @param array<int, array<string, mixed>> $keywords słowa kluczowe z sumami
	 * @param array<string, mixed> $breakdown ConversionBreakdown::forPeriod()
	 * @param array<int, array<string, mixed>> $negatives słowa wykluczające z Google Ads
	 */
	public function __construct(
		public readonly string $currency,
		public readonly AnalysisPeriod $period,
		public readonly array $campaigns,
		public readonly array $adGroups,
		public readonly array $terms,
		public readonly array $keywords,
		public readonly array $breakdown,
		public readonly array $negatives,
		public readonly BusinessContext $context,
		public readonly ?int $monthlyBudgetMicros = null,
		public readonly string $dataState = 'complete',
	) {
	}

	public function days(): int
	{
		return $this->period->days();
	}

	public function totalCost(): int
	{
		return (int) array_sum(array_map(fn ($c) => (int) ($c['current']['cost_micros'] ?? 0), $this->campaigns));
	}

	public function totalClicks(): int
	{
		return (int) array_sum(array_map(fn ($c) => (int) ($c['current']['clicks'] ?? 0), $this->campaigns));
	}

	public function totalConversions(): float
	{
		return (float) array_sum(array_map(fn ($c) => (float) ($c['current']['conversions'] ?? 0), $this->campaigns));
	}

	/**
	 * Konwersje celów biznesowych kampanii (null = brak danych per kampania).
	 */
	public function campaignGoals(int $campaignId): ?float
	{
		if (! ($this->breakdown['campaign_level_available'] ?? false)) {
			return null;
		}

		return (float) ($this->breakdown['campaigns'][$campaignId]['goals'] ?? 0);
	}

	public function accountGoals(): ?float
	{
		return $this->breakdown['account']['goals'] ?? null;
	}

	/**
	 * Współczynnik konwersji kampanii (Google Ads) – punkt odniesienia dla haseł i słów (których konwersji nie da się
	 * rozbić na działania). Zastępczo współczynnik konta.
	 */
	public function conversionRate(?int $campaignId = null): ?float
	{
		if ($campaignId !== null && isset($this->campaigns[$campaignId])) {
			$clicks = (int) ($this->campaigns[$campaignId]['current']['clicks'] ?? 0);
			$conversions = (float) ($this->campaigns[$campaignId]['current']['conversions'] ?? 0);

			if ($clicks >= 50 && $conversions > 0) {
				return $conversions / $clicks;
			}
		}

		$clicks = $this->totalClicks();

		return $clicks > 0 && $this->totalConversions() > 0 ? $this->totalConversions() / $clicks : null;
	}

	/**
	 * Współczynnik konwersji celów biznesowych na poziomie konta (do kampanii).
	 */
	public function goalRate(): ?float
	{
		$goals = $this->accountGoals();
		$clicks = $this->totalClicks();

		return $goals !== null && $goals > 0 && $clicks > 0 ? $goals / $clicks : null;
	}

	/**
	 * Czy główne konwersje Google Ads to głównie mikrokonwersje (wtedy „konwersje” hasła niewiele znaczą).
	 */
	public function primaryMostlyMicro(): bool
	{
		$account = $this->breakdown['account'] ?? [];
		$google = (float) ($account['google'] ?? 0);

		return $google > 0 && (float) ($account['google_micro'] ?? 0) / $google >= 0.5;
	}

	public function campaignName(?int $campaignId): string
	{
		return $campaignId !== null && isset($this->campaigns[$campaignId]) ? (string) $this->campaigns[$campaignId]['name'] : '—';
	}
}
