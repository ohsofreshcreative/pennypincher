<?php

namespace App\PennyPincher\Services\GoogleAds;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\GoogleAds\Changes\WriteMode;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\PennyPincher\Infrastructure\GoogleAds\Mutate\WriteBlockedException;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\OrganizationRepository;

/**
 * Bramka zapisu do Google Ads. Wdrożenie jest możliwe tylko, gdy jednocześnie:
 * 1. serwer ma PP_GOOGLE_ADS_WRITE_MODE = write_enabled (konfiguracja poza aplikacją),
 *    a konto jest na allowliście PP_GOOGLE_ADS_WRITE_CUSTOMERS i nie ma go na liście blokad (PP_GOOGLE_ADS_BLOCKED_CUSTOMERS),
 * 2. właściciel organizacji włączył zapis dla tego połączenia (konto Google Ads),
 * 3. użytkownik jest administratorem z flagą „wykonawca zmian” (nadaje właściciel),
 * 4. połączenie jest aktywne i jest tym samym połączeniem/kontem, dla którego przygotowano i zatwierdzono zmiany.
 */
class WriteGuard
{
	public function __construct(private GoogleAdsConfig $config, private GoogleAdsConnectionRepository $connections, private OrganizationRepository $organizations)
	{
	}

	public function serverMode(): WriteMode
	{
		return $this->config->writeMode();
	}

	/**
	 * Tryb efektywny dla połączenia projektu.
	 */
	public function mode(?array $connection): WriteMode
	{
		$server = $this->serverMode();

		if (! $server->canPrepare()) {
			return WriteMode::ReadOnly;
		}

		$connectionAllows = $connection !== null && (int) ($connection['write_enabled'] ?? 0) === 1 && ($connection['status'] ?? '') === GoogleAdsConnectionRepository::STATUS_ACTIVE
			&& $this->config->writeCustomerBlocker($connection['customer_id'] ?? null) === null;

		return WriteMode::min($server, $connectionAllows ? WriteMode::WriteEnabled : WriteMode::PrepareOnly);
	}

	public function projectMode(Project $project): WriteMode
	{
		return $this->mode($this->connections->currentForProject($project->id));
	}

	public function apiValidationAvailable(?array $connection): bool
	{
		return $connection !== null && ($connection['status'] ?? '') === GoogleAdsConnectionRepository::STATUS_ACTIVE
			&& ! $this->config->isWriteBlockedCustomer((string) ($connection['customer_id'] ?? ''))
			&& ($this->mode($connection)->canWrite() || ($this->mode($connection)->canPrepare() && $this->config->apiValidationEnabled()));
	}

	public function isExecutor(Project $project, int $userId): bool
	{
		return Policy::allows($project->role, Ability::ExecuteChanges) && $this->organizations->isExecutor($project->organizationId, $userId);
	}

	/**
	 * Powód, dla którego wdrożenie jest niemożliwe (null = można wdrożyć).
	 */
	public function executeBlocker(Project $project, ?array $connection, int $userId, ?array $changeSet = null): ?string
	{
		$server = $this->serverMode();

		if (! $server->canWrite()) {
			return 'Zapis do Google Ads jest wyłączony na serwerze (tryb: ' . $server->label() . '). Zmiany można przygotować i zatwierdzić, ale nie wdrożyć.';
		}

		if ($connection === null || ($connection['status'] ?? '') !== GoogleAdsConnectionRepository::STATUS_ACTIVE) {
			return 'Projekt nie ma aktywnego połączenia z Google Ads.';
		}

		// Allowlista i lista blokad kont – po stronie serwera, niezależnie od zgody w aplikacji.
		if (($accountBlocker = $this->config->writeCustomerBlocker($connection['customer_id'] ?? null)) !== null) {
			return $accountBlocker;
		}

		if ((int) ($connection['write_enabled'] ?? 0) !== 1) {
			return 'Właściciel organizacji nie włączył zapisu dla tego konta Google Ads.';
		}

		if (! $this->isExecutor($project, $userId)) {
			return 'Wdrażać zmiany może tylko administrator z uprawnieniem „wykonawca zmian” (nadaje właściciel organizacji).';
		}

		if ($changeSet !== null && ((int) $changeSet['connection_id'] !== (int) $connection['id'] || (string) $changeSet['customer_id'] !== (string) $connection['customer_id'])) {
			return 'Zestaw zmian przygotowano dla innego konta Google Ads niż obecnie połączone – przygotuj zmiany ponownie.';
		}

		return null;
	}

	public function servingAllowed(): bool
	{
		return $this->config->servingAllowed();
	}

	/**
	 * Stan konta wobec zapisu (do sekcji „Uprawnienia do zapisu”): allowlista, blokada, powód.
	 *
	 * @return array{customer_id: ?string, allowlisted: bool, blocked: bool, blocker: ?string, allowlist_empty: bool, serving_allowed: bool}
	 */
	public function accountStatus(?array $connection): array
	{
		$customer = $connection['customer_id'] ?? null;

		return [
			'customer_id' => $customer,
			'allowlisted' => $customer !== null && $this->config->isWriteAllowedCustomer((string) $customer),
			'blocked' => $customer !== null && $this->config->isWriteBlockedCustomer((string) $customer),
			'blocker' => $this->config->writeCustomerBlocker($customer),
			'allowlist_empty' => $this->config->writeCustomerAllowlist() === [],
			'serving_allowed' => $this->config->servingAllowed(),
		];
	}

	public function assertCanExecute(Project $project, ?array $connection, int $userId, ?array $changeSet = null): void
	{
		$reason = $this->executeBlocker($project, $connection, $userId, $changeSet);

		if ($reason !== null) {
			throw new WriteBlockedException($reason);
		}
	}
}
