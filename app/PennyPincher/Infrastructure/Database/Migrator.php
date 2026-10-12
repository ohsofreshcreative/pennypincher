<?php

namespace App\PennyPincher\Infrastructure\Database;

use RuntimeException;

/**
 * Wersjonowane migracje schematu aplikacji.
 *
 * - każda migracja ma stały identyfikator i jest wykonywana dokładnie raz (tabela `pp_schema_migrations`),
 * - migracje są addytywne: nigdy nie usuwamy tabel ani kolumn z danymi,
 * - równoległe uruchomienia blokuje GET_LOCK,
 * - opcja `pp_schema_version` pozwala tanio sprawdzić, czy trzeba cokolwiek robić.
 */
class Migrator
{
	public const OPTION = 'pp_schema_version';

	public function __construct(private Db $db)
	{
	}

	/**
	 * @return array<string, callable(Db, string): array<int, string>>
	 */
	public function migrations(): array
	{
		return [
			'2026_10_12_000001_initial_schema' => [Schema::class, 'initial'],
			'2026_10_12_000002_view_through_conversions' => [Schema::class, 'viewThroughConversions'],
		];
	}

	public function latestVersion(): string
	{
		$keys = array_keys($this->migrations());

		return (string) end($keys);
	}

	public function isCurrent(): bool
	{
		return get_option(self::OPTION) === $this->latestVersion();
	}

	/**
	 * @return array<int, string> zastosowane migracje
	 */
	public function migrate(): array
	{
		$applied = [];
		$lock = $this->db->wpdb()->prefix . 'pp_migrate';

		if ((int) $this->db->scalar('SELECT GET_LOCK(?, 30)', [$lock]) !== 1) {
			throw new RuntimeException('Nie udało się uzyskać blokady migracji. Spróbuj ponownie za chwilę.');
		}

		try {
			$this->db->statement(sprintf(
				'CREATE TABLE IF NOT EXISTS %s (
					version VARCHAR(191) NOT NULL,
					applied_at DATETIME NOT NULL,
					PRIMARY KEY (version)
				) ENGINE=InnoDB %s',
				$this->db->table('schema_migrations'),
				$this->db->wpdb()->get_charset_collate()
			));

			$done = array_flip($this->db->column('SELECT version FROM ' . $this->db->table('schema_migrations')));
			$charset = $this->db->wpdb()->get_charset_collate();

			foreach ($this->migrations() as $version => $migration) {
				if (isset($done[$version])) {
					continue;
				}

				foreach (call_user_func($migration, $this->db, $charset) as $statement) {
					$this->db->statement($statement);
				}

				$this->db->insert('schema_migrations', ['version' => $version, 'applied_at' => $this->db->now()]);
				$applied[] = $version;
			}

			update_option(self::OPTION, $this->latestVersion(), true);
		} finally {
			$this->db->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
		}

		return $applied;
	}
}
