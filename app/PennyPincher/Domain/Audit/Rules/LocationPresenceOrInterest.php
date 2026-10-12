<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Domain\GoogleAds\Enums;
use App\PennyPincher\Support\Format;

/**
 * CS003: kierowanie „obecność lub zainteresowanie” zamiast „obecność”.
 */
final class LocationPresenceOrInterest extends BaseRule
{
	public function id(): string
	{
		return 'CS003';
	}

	public function name(): string
	{
		return 'Lokalizacja: obecność lub zainteresowanie';
	}

	public function description(): string
	{
		return 'Reklamy docierają też do osób, które są poza wybranym obszarem, ale się nim interesują – przy usługach świadczonych lokalnie to często płatny ruch spoza rynku.';
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
			$settings = $campaign['settings'] ?? [];

			if (($campaign['status'] ?? null) !== 'enabled' || ($settings['geo_target_type'] ?? null) !== 'PRESENCE_OR_INTEREST' || empty($settings['locations'])) {
				continue;
			}

			$locations = array_map(fn ($l) => $l['name'] ?? '?', array_filter($settings['locations'], fn ($l) => empty($l['negative'])));

			$findings[] = $this->finding(
				Severity::Warning,
				sprintf('Kampania „%s” kieruje także do osób zainteresowanych lokalizacją', $campaign['name']),
				$this->description(),
				[
					['Kampania', (string) $campaign['name']],
					['Lokalizacje', $locations === [] ? '—' : implode(', ', array_slice($locations, 0, 5))],
					['Opcja kierowania', 'Obecność lub zainteresowanie'],
				],
				'Jeśli obsługujesz klientów tylko w wybranym regionie, zmień opcję na „Obecność: osoby przebywające w docelowych lokalizacjach”.',
				'campaign',
				(int) $campaign['id'],
			);
		}

		return $findings;
	}
}
