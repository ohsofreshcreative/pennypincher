<?php

namespace App\PennyPincher\Services\GoogleAds;

use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;

/**
 * Sekcja „Uprawnienia do zapisu” (Google Ads Management): stan konta wobec zapisu w jednym miejscu –
 * Customer ID, nazwa, konto produkcyjne/testowe, allowlista, tryb serwera, zgoda właściciela, blokada emisji,
 * możliwość wdrożenia przez bieżącego użytkownika i ostatnia operacja zapisu. Tylko odczyt.
 */
class WriteAccessService
{
	public const SNAPSHOT_ACCOUNT = 'customer_info';

	public function __construct(
		private Db $db,
		private WriteGuard $guard,
		private GoogleAdsConfig $config,
		private ChangeSetRepository $sets,
		private StateReader $reader,
		private GoogleAdsConnectionRepository $connections,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function overview(Project $project, ?array $connection, int $userId): array
	{
		$customer = $connection['customer_id'] ?? null;
		$account = $customer !== null ? $this->sets->snapshot($project->id, (string) $customer, self::SNAPSHOT_ACCOUNT, 'account') : null;
		$lastWrite = $this->db->first(
			'SELECT a.change_set_id, a.outcome, a.request_id, a.customer_id, a.created_at, a.message FROM ' . $this->db->table('change_attempts') . " a
			WHERE a.project_id = ? AND a.kind = 'execute' ORDER BY a.id DESC LIMIT 1",
			[$project->id]
		);

		return $this->guard->accountStatus($connection) + [
			'customer_formatted' => $customer !== null ? GoogleAdsConfig::formatCustomer((string) $customer) : null,
			'customer_name' => $connection['customer_name'] ?? null,
			'account' => $account['data'] ?? null,
			'account_read_at' => $account['read_at'] ?? null,
			'server_mode' => $this->guard->serverMode(),
			'consent' => (int) ($connection['write_enabled'] ?? 0) === 1,
			'consent_at' => $connection['write_enabled_at'] ?? null,
			'effective_mode' => $this->guard->mode($connection),
			'execute_blocker' => $this->guard->executeBlocker($project, $connection, $userId),
			'allowlist' => array_map(fn ($id) => GoogleAdsConfig::formatCustomer($id), $this->config->writeCustomerAllowlist()),
			'blocked_list' => array_map(fn ($id) => GoogleAdsConfig::formatCustomer($id), $this->config->blockedWriteCustomers()),
			'last_write' => $lastWrite,
		];
	}

	/**
	 * Odczyt danych konta z Google Ads (GAQL, bez zapisu): nazwa, konto testowe/produkcyjne, menedżer, waluta, strefa.
	 *
	 * @return array<string, mixed>
	 */
	public function readAccount(Project $project): array
	{
		$connection = $this->connections->currentForProject($project->id);

		if ($connection === null || $connection['status'] !== GoogleAdsConnectionRepository::STATUS_ACTIVE || ! $connection['customer_id']) {
			throw new ValidationFailed(['connection' => 'Brak aktywnego połączenia z kontem Google Ads.']);
		}

		try {
			$row = $this->reader->search($connection, 'SELECT customer.id, customer.descriptive_name, customer.test_account, customer.manager, customer.status, customer.currency_code, customer.time_zone FROM customer')[0]['customer'] ?? null;
		} catch (GoogleAdsApiException $e) {
			throw new ValidationFailed(['google_ads' => 'Nie udało się odczytać konta: ' . $e->userMessage()]);
		}

		if ($row === null) {
			throw new ValidationFailed(['google_ads' => 'Google Ads nie zwrócił danych konta.']);
		}

		$data = [
			'id' => (string) ($row['id'] ?? $connection['customer_id']),
			'name' => (string) ($row['descriptiveName'] ?? ''),
			'test_account' => (bool) ($row['testAccount'] ?? false),
			'manager' => (bool) ($row['manager'] ?? false),
			'status' => $row['status'] ?? null,
			'currency' => $row['currencyCode'] ?? null,
			'time_zone' => $row['timeZone'] ?? null,
		];
		$this->sets->saveSnapshot($project->id, (string) $connection['customer_id'], self::SNAPSHOT_ACCOUNT, 'account', $data);

		return $data;
	}
}
