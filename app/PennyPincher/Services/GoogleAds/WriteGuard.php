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

		$connectionAllows = $connection !== null && (int) ($connection['write_enabled'] ?? 0) === 1 && ($connection['status'] ?? '') === GoogleAdsConnectionRepository::STATUS_ACTIVE;

		return WriteMode::min($server, $connectionAllows ? WriteMode::WriteEnabled : WriteMode::PrepareOnly);
	}

	public function projectMode(Project $project): WriteMode
	{
		return $this->mode($this->connections->currentForProject($project->id));
	}

	public function apiValidationAvailable(?array $connection): bool
	{
		return $connection !== null && ($connection['status'] ?? '') === GoogleAdsConnectionRepository::STATUS_ACTIVE
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

	public function assertCanExecute(Project $project, ?array $connection, int $userId, ?array $changeSet = null): void
	{
		$reason = $this->executeBlocker($project, $connection, $userId, $changeSet);

		if ($reason !== null) {
			throw new WriteBlockedException($reason);
		}
	}
}
