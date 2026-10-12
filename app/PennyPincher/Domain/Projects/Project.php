<?php

namespace App\PennyPincher\Domain\Projects;

use App\PennyPincher\Domain\Access\Role;
use DateTimeZone;

/**
 * Projekt (konto reklamowe) w organizacji wraz z rolą bieżącego użytkownika.
 */
final class Project
{
	public const STATUSES = [
		'active' => 'Aktywny',
		'paused' => 'Wstrzymany',
		'archived' => 'Zarchiwizowany',
	];

	public const PLATFORMS = [
		'google_ads' => 'Google Ads',
	];

	public function __construct(
		public readonly int $id,
		public readonly int $organizationId,
		public readonly string $organizationName,
		public readonly string $name,
		public readonly string $slug,
		public readonly string $platform,
		public readonly string $currency,
		public readonly string $timezone,
		public readonly ?int $monthlyBudgetMicros,
		public readonly ?string $websiteUrl,
		public readonly ?string $landingPageUrl,
		public readonly ?string $businessDescription,
		public readonly ?string $targetCustomer,
		public readonly ?int $minProjectValueMicros,
		public readonly ?int $maxProjectValueMicros,
		public readonly string $status,
		public readonly bool $isDemo,
		public readonly ?Role $role,
		public readonly string $createdAt,
		public readonly string $updatedAt,
		public readonly ?BusinessContext $businessContext = null,
		public readonly ?float $dataforseoMonthlyLimit = null,
	) {
	}

	/**
	 * Kontekst biznesowy (zawsze obiekt – pusty, gdy nic nie uzupełniono).
	 */
	public function context(): BusinessContext
	{
		return $this->businessContext ?? new BusinessContext([], $this->businessDescription, $this->targetCustomer, $this->landingPageUrl, $this->minProjectValueMicros, $this->maxProjectValueMicros);
	}

	public static function fromRow(array $row): self
	{
		$int = fn ($value) => $value === null ? null : (int) $value;

		return new self(
			id: (int) $row['id'],
			organizationId: (int) $row['organization_id'],
			organizationName: (string) ($row['organization_name'] ?? ''),
			name: (string) $row['name'],
			slug: (string) $row['slug'],
			platform: (string) $row['platform'],
			currency: (string) $row['currency'],
			timezone: (string) $row['timezone'],
			monthlyBudgetMicros: $int($row['monthly_budget_micros']),
			websiteUrl: $row['website_url'] ?: null,
			landingPageUrl: $row['landing_page_url'] ?: null,
			businessDescription: $row['business_description'] ?: null,
			targetCustomer: $row['target_customer'] ?: null,
			minProjectValueMicros: $int($row['min_project_value_micros']),
			maxProjectValueMicros: $int($row['max_project_value_micros']),
			status: (string) $row['status'],
			isDemo: (bool) $row['is_demo'],
			role: isset($row['role']) ? Role::tryFrom((string) $row['role']) : null,
			createdAt: (string) $row['created_at'],
			updatedAt: (string) $row['updated_at'],
			businessContext: BusinessContext::fromJson(
				$row['business_context'] ?? null,
				$row['business_description'] ?: null,
				$row['target_customer'] ?: null,
				$row['landing_page_url'] ?: null,
				$int($row['min_project_value_micros']),
				$int($row['max_project_value_micros']),
			),
			dataforseoMonthlyLimit: isset($row['dataforseo_monthly_limit']) && $row['dataforseo_monthly_limit'] !== null ? (float) $row['dataforseo_monthly_limit'] : null,
		);
	}

	public function timezone(): DateTimeZone
	{
		try {
			return new DateTimeZone($this->timezone);
		} catch (\Exception) {
			return wp_timezone();
		}
	}

	public function statusLabel(): string
	{
		return self::STATUSES[$this->status] ?? $this->status;
	}
}
