<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Domain\GoogleAds\Enums;
use App\PennyPincher\Support\Format;

/**
 * CS001: partnerzy wyszukiwania. Samo włączenie nie jest błędem – informujemy i prosimy o ocenę wyników.
 */
final class SearchPartnersEnabled extends BaseRule
{
	public function id(): string
	{
		return 'CS001';
	}

	public function name(): string
	{
		return 'Włączeni partnerzy wyszukiwania';
	}

	public function description(): string
	{
		return 'Kampania w sieci wyszukiwania wyświetla reklamy także na stronach partnerów Google, gdzie jakość ruchu bywa niższa.';
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

		foreach ($context->campaigns as $campaign) {
			if (($campaign['status'] ?? null) !== 'enabled' || ($campaign['channel_type'] ?? null) !== 'SEARCH' || empty($campaign['settings']['networks']['search_partners'])) {
				continue;
			}

			$findings[] = $this->finding(
				Severity::Info,
				sprintf('Kampania „%s” korzysta z partnerów wyszukiwania', $campaign['name']),
				$this->description() . ' To ustawienie nie jest samo w sobie błędem.',
				[
					['Kampania', (string) $campaign['name']],
					['Wydatki (w okresie)', self::money($campaign['current']['cost_micros'] ?? null, $context->currency)],
					['Konwersje (w okresie)', Format::conversions($campaign['current']['conversions'] ?? null)],
				],
				'Porównaj w Google Ads wyniki segmentu „Sieć (z partnerami wyszukiwania)”. Wyłącz partnerów, jeśli ich koszt konwersji jest wyraźnie wyższy.',
				'campaign',
				(int) $campaign['id'],
			);
		}

		return $findings;
	}
}
