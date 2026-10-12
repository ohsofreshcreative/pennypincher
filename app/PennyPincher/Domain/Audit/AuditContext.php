<?php

namespace App\PennyPincher\Domain\Audit;

use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Metrics\ResolvedPeriods;

/**
 * Dane wejściowe audytu – zebrane raz przez AuditContextBuilder, reguły tylko je czytają.
 * Dzięki temu reguły są czystymi funkcjami i można je testować bez bazy danych.
 */
final class AuditContext
{
	/**
	 * @param array<int, array<string, mixed>> $campaigns kampanie z `settings`, `current` i `previous` (metryki okresów)
	 * @param array<int, array<string, mixed>> $conversionActions działania konwersji z ustawieniami i `conversions` w okresie
	 * @param array{spend: ?int, covered_days: int, elapsed_days: int, days_in_month: int, complete: bool, month: string}|null $monthToDate
	 * @param array<int, array{term: string, campaign: string, clicks: int, cost_micros: int}> $searchTermsWithoutConversions
	 */
	public function __construct(
		public readonly string $currency,
		public readonly ?int $monthlyBudgetMicros,
		public readonly DateRange $range,
		public readonly DateRange $previousRange,
		public readonly array $campaigns,
		public readonly array $conversionActions,
		public readonly ResolvedPeriods $coverage,
		public readonly ?array $connection,
		public readonly ?string $lastSyncAt,
		public readonly ?string $lastRunStatus,
		public readonly ?string $lastRunError,
		public readonly ?array $monthToDate,
		public readonly array $searchTermsWithoutConversions,
		public readonly ?int $searchTermsTotalCostMicros,
		public readonly int $now,
		public readonly ?string $projectCurrencyMismatch = null,
	) {
	}

	public function hasCampaignData(): bool
	{
		foreach ($this->campaigns as $campaign) {
			if (($campaign['current']['cost_micros'] ?? null) !== null || ($campaign['current']['impressions'] ?? null) !== null) {
				return true;
			}
		}

		return false;
	}

	public function hasConversionSettings(): bool
	{
		foreach ($this->conversionActions as $action) {
			if (! empty($action['category_code'])) {
				return true;
			}
		}

		return false;
	}

	public function totalCostMicros(): ?int
	{
		$total = null;

		foreach ($this->campaigns as $campaign) {
			if (($campaign['current']['cost_micros'] ?? null) !== null) {
				$total = ($total ?? 0) + (int) $campaign['current']['cost_micros'];
			}
		}

		return $total;
	}

	/**
	 * Czy główne konwersje wyglądają na mikrokonwersje (wynik reguły CT001) – używane przez inne reguły,
	 * żeby nie wyciągać wniosków z CPA liczonego na podstawie niewłaściwych konwersji.
	 */
	public function primaryConversionsAreMicro(): bool
	{
		$primary = array_filter($this->conversionActions, fn ($action) => self::isPrimary($action) && ($action['status'] ?? null) !== 'removed');

		if ($primary === [] || ! $this->hasConversionSettings()) {
			return false;
		}

		foreach ($primary as $action) {
			if (! in_array($action['category_code'] ?? '', \App\PennyPincher\Domain\GoogleAds\Enums::MICRO_CATEGORIES, true)) {
				return false;
			}
		}

		return true;
	}

	public static function isPrimary(array $action): bool
	{
		if (isset($action['primary_for_goal']) && $action['primary_for_goal'] !== null) {
			return (bool) $action['primary_for_goal'];
		}

		return (bool) ($action['include_in_conversions'] ?? false);
	}
}
