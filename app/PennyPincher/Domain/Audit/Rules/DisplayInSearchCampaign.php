<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Domain\GoogleAds\Enums;
use App\PennyPincher\Support\Format;

/**
 * CS002: sieć reklamowa włączona w kampanii w sieci wyszukiwania.
 */
final class DisplayInSearchCampaign extends BaseRule
{
	public function id(): string
	{
		return 'CS002';
	}

	public function name(): string
	{
		return 'Sieć reklamowa w kampanii w sieci wyszukiwania';
	}

	public function description(): string
	{
		return 'Opcja „Rozszerzenie na sieć reklamową” wyświetla reklamy tekstowe na stronach i w aplikacjach, gdzie intencja zakupu jest zwykle znacznie niższa niż w wyszukiwarce.';
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
			if (($campaign['status'] ?? null) === 'enabled' && ($campaign['channel_type'] ?? null) === 'SEARCH' && ! empty($campaign['settings']['networks']['display'])) {
				$findings[] = $this->finding(
					Severity::Warning,
					sprintf('Kampania „%s” wyświetla się także w sieci reklamowej', $campaign['name']),
					$this->description(),
					[
						['Kampania', (string) $campaign['name']],
						['Typ', 'Sieć wyszukiwania + sieć reklamowa'],
						['Wydatki (w okresie)', self::money($campaign['current']['cost_micros'] ?? null, $context->currency)],
					],
					'Wyłącz „Sieć reklamowa Google” w ustawieniach kampanii. Jeśli potrzebujesz zasięgu, użyj osobnej kampanii z własnym budżetem i celami.',
					'campaign',
					(int) $campaign['id'],
				);
			}
		}

		return $findings;
	}
}
