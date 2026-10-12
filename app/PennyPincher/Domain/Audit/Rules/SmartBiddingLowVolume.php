<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Domain\GoogleAds\Enums;
use App\PennyPincher\Support\Format;

/**
 * CS005: inteligentne ustalanie stawek przy małej liczbie konwersji.
 */
final class SmartBiddingLowVolume extends BaseRule
{
	public const MIN_CONVERSIONS = 15;

	public function id(): string
	{
		return 'CS005';
	}

	public function name(): string
	{
		return 'Strategia oparta na konwersjach przy małej liczbie danych';
	}

	public function description(): string
	{
		return 'Strategie typu Maksymalizacja konwersji / docelowy CPA potrzebują regularnych konwersji, żeby działać stabilnie.';
	}

	public function category(): string
	{
		return Category::CAMPAIGN_SETTINGS;
	}

	protected function dimension(): Dimension
	{
		return Dimension::Configuration;
	}

	public function evaluate(AuditContext $context): array
	{
		$findings = [];
		// Próg 15 konwersji na 30 dni, przeskalowany do długości analizowanego okresu.
		$threshold = round(self::MIN_CONVERSIONS * max(1, $context->range->days()) / 30, 1);

		foreach ($context->campaigns as $campaign) {
			$conversions = $campaign['current']['conversions'] ?? null;

			if (
				($campaign['status'] ?? null) !== 'enabled'
				|| ! in_array($campaign['bidding_strategy'] ?? '', Enums::SMART_BIDDING, true)
				|| $conversions === null
				|| (int) ($campaign['current']['cost_micros'] ?? 0) === 0
				|| (float) $conversions >= $threshold
			) {
				continue;
			}

			$micro = $context->primaryConversionsAreMicro();

			$findings[] = $this->finding(
				$micro ? Severity::Critical : Severity::Warning,
				sprintf('„%s” używa strategii %s przy %s konwersjach w %d dni', $campaign['name'], Enums::BIDDING[$campaign['bidding_strategy']] ?? $campaign['bidding_strategy'], Format::conversions($conversions), $context->range->days()),
				$this->description() . ($micro ? ' Dodatkowo konwersje główne konta to mikrokonwersje, więc algorytm optymalizuje pod niewłaściwy cel.' : ''),
				[
					['Strategia', Enums::BIDDING[$campaign['bidding_strategy']] ?? (string) $campaign['bidding_strategy']],
					['Konwersje (w okresie)', Format::conversions($conversions)],
					['Próg', self::MIN_CONVERSIONS . ' konwersji / 30 dni (dla tego okresu: ' . Format::conversions($threshold) . ')'],
					['Wydatki (w okresie)', self::money($campaign['current']['cost_micros'] ?? null, $context->currency)],
				],
				'Przy małej liczbie konwersji rozważ Maksymalizację liczby kliknięć z limitem CPC albo połączenie kampanii, aż konwersji będzie więcej. Najpierw upewnij się, że konwersje główne mierzą kontakt z klientem.',
				'campaign',
				(int) $campaign['id'],
			);
		}

		return $findings;
	}
}
