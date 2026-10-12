<?php

namespace App\PennyPincher\Domain\Audit;


/**
 * Lista aktywnych reguł audytu. Kolejność = kolejność wyświetlania w ramach tej samej ważności.
 * Nowa reguła: klasa w Rules/ (rozszerza BaseRule) + wpis tutaj + test w tests/Unit/AuditRulesTest.php.
 */
final class RuleRegistry
{
	/** Zmieniaj przy każdej zmianie reguł lub progów – zapisywane przy każdym audycie. */
	public const VERSION = '1.1';

	public const RULES = [
		Rules\MicroConversionAsPrimary::class,
		Rules\NoValuablePrimaryConversion::class,
		Rules\LeadCountingEvery::class,
		Rules\SearchPartnersEnabled::class,
		Rules\DisplayInSearchCampaign::class,
		Rules\LocationPresenceOrInterest::class,
		Rules\NoLocationTargeting::class,
		Rules\SmartBiddingLowVolume::class,
		Rules\MonthlyPacingForecast::class,
		Rules\DailyBudgetsExceedMonthly::class,
		Rules\NoCampaignData::class,
		Rules\NoActivity::class,
		Rules\NoApiConnection::class,
		Rules\StaleSync::class,
		Rules\ConnectionProblem::class,
		Rules\CoverageGaps::class,
		Rules\SourceConflicts::class,
		Rules\CurrencyMismatch::class,
		Rules\CpcIncrease::class,
		Rules\CtrDrop::class,
		Rules\CpaIncrease::class,
		Rules\SpendWithoutConversions::class,
		Rules\SearchTermsWithoutConversions::class,
	];

	/**
	 * @return array<int, Rule>
	 */
	public static function all(): array
	{
		return array_map(fn (string $class) => new $class(), self::RULES);
	}

	/**
	 * @return array<string, Rule> klucz = ID reguły
	 */
	public static function byId(): array
	{
		$rules = [];

		foreach (self::all() as $rule) {
			$rules[$rule->id()] = $rule;
		}

		return $rules;
	}
}
