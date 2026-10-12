<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Domain\GoogleAds\Enums;
use App\PennyPincher\Support\Format;

/**
 * CS004: brak kierowania na lokalizacje (cały świat).
 */
final class NoLocationTargeting extends BaseRule
{
	public function id(): string
	{
		return 'CS004';
	}

	public function name(): string
	{
		return 'Brak kierowania na lokalizacje';
	}

	public function description(): string
	{
		return 'Kampania bez kryteriów lokalizacji wyświetla się we wszystkich krajach i regionach.';
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

			// Tylko gdy lokalizacje zostały zsynchronizowane – brak danych to nie to samo co brak kierowania.
			if (($campaign['status'] ?? null) !== 'enabled' || empty($settings['locations_synced']) || ! empty(array_filter($settings['locations'] ?? [], fn ($l) => empty($l['negative'])))) {
				continue;
			}

			$findings[] = $this->finding(
				Severity::Warning,
				sprintf('Kampania „%s” nie ma kierowania na lokalizacje', $campaign['name']),
				$this->description(),
				[
					['Kampania', (string) $campaign['name']],
					['Typ kampanii', Enums::CHANNELS[$campaign['channel_type'] ?? ''] ?? (string) ($campaign['channel_type'] ?? '—')],
					['Wydatki (30 dni)', self::money($campaign['current']['cost_micros'] ?? null, $context->currency)],
				],
				'Dodaj lokalizacje obsługiwane przez firmę (np. województwo mazowieckie) i sprawdź raport „Gdzie były wyświetlane reklamy”.',
				'campaign',
				(int) $campaign['id'],
			);
		}

		return $findings;
	}
}
