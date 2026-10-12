<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Infrastructure\Database\Db;

/**
 * Połączenia projektów z kontami Google Ads. Tokeny są zapisane zaszyfrowane (SecretBox) i nigdy
 * nie są zwracane przez metody listujące.
 */
class GoogleAdsConnectionRepository
{
	public const STATUS_PENDING = 'pending_account';

	public const STATUS_ACTIVE = 'active';

	public const STATUS_REAUTH = 'reauth_required';

	public const STATUS_DISCONNECTED = 'disconnected';

	public const STATUS_ERROR = 'error';

	public const LIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_REAUTH, self::STATUS_ERROR];

	private const PUBLIC_COLUMNS = 'id, project_id, customer_id, customer_name, login_customer_id, currency, time_zone, google_email, scopes, status, last_error, connected_by, connected_at, disconnected_at, access_token_expires_at, created_at, updated_at';

	public function __construct(private Db $db)
	{
	}

	/**
	 * Bieżące (niezerwane) połączenie projektu. Struktura danych pozwala na wiele kont w projekcie,
	 * na tym etapie interfejs obsługuje jedno aktywne.
	 */
	public function currentForProject(int $projectId): ?array
	{
		$placeholders = implode(', ', array_fill(0, count(self::LIVE_STATUSES), '?'));

		return $this->db->first(
			sprintf('SELECT %s FROM %s WHERE project_id = ? AND status IN (%s) ORDER BY id DESC LIMIT 1', self::PUBLIC_COLUMNS, $this->db->table('google_ads_connections'), $placeholders),
			[$projectId, ...self::LIVE_STATUSES]
		);
	}

	public function find(int $connectionId): ?array
	{
		return $this->db->first(
			sprintf('SELECT %s FROM %s WHERE id = ?', self::PUBLIC_COLUMNS, $this->db->table('google_ads_connections')),
			[$connectionId]
		);
	}

	/**
	 * @return array{refresh_token: ?string, access_token: ?string, access_token_expires_at: ?string}|null zaszyfrowane wartości
	 */
	public function secrets(int $connectionId): ?array
	{
		return $this->db->first(
			'SELECT refresh_token, access_token, access_token_expires_at FROM ' . $this->db->table('google_ads_connections') . ' WHERE id = ?',
			[$connectionId]
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function activeConnections(): array
	{
		return $this->db->select(
			sprintf('SELECT %s FROM %s WHERE status = ? ORDER BY id', self::PUBLIC_COLUMNS, $this->db->table('google_ads_connections')),
			[self::STATUS_ACTIVE]
		);
	}

	public function create(array $values): int
	{
		$now = $this->db->now();

		return $this->db->insert('google_ads_connections', $values + ['created_at' => $now, 'updated_at' => $now]);
	}

	public function update(int $connectionId, array $values): void
	{
		$this->db->update('google_ads_connections', $values + ['updated_at' => $this->db->now()], ['id' => $connectionId]);
	}
}
