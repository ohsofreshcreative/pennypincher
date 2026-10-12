<?php

namespace App\PennyPincher\Infrastructure\Database;

/**
 * Definicje migracji. Każda metoda zwraca listę zapytań DDL.
 *
 * Konwencje:
 * - kwoty pieniężne: BIGINT w mikrojednostkach waluty (1 PLN = 1 000 000), jak `cost_micros` w Google Ads API,
 * - konwersje: DECIMAL (Google Ads raportuje konwersje ułamkowe przy atrybucji opartej na danych),
 * - NULL = brak danych w źródle (nigdy nie zamieniamy go na 0),
 * - metryki mają ziarnistość `day` albo `period` i zawsze zakres dat oraz import źródłowy,
 * - `natural_key` (sha1) to stabilny klucz encji używany do idempotentnych ponownych importów.
 */
class Schema
{
	public static function initial(Db $db, string $charset): array
	{
		$t = fn (string $name) => $db->table($name);

		// Nazwy kluczy obcych są globalne w obrębie bazy, więc zawierają prefiks tabel WordPressa.
		$p = $db->wpdb()->prefix;

		$statements = [];

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('organizations')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_org_slug (slug)
		) ENGINE=InnoDB {$charset}";

		// user_id wskazuje na tabelę WordPressa `users`, której nie obejmujemy kluczem obcym
		// (WP zarządza nią sam) – spójność utrzymuje hook `deleted_user`.
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('organization_users')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_org_user (organization_id, user_id),
			KEY pp_org_user_user (user_id),
			CONSTRAINT {$p}pp_fk_ou_org FOREIGN KEY (organization_id) REFERENCES {$t('organizations')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('projects')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			organization_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			platform VARCHAR(32) NOT NULL DEFAULT 'google_ads',
			currency CHAR(3) NOT NULL DEFAULT 'PLN',
			timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Warsaw',
			monthly_budget_micros BIGINT NULL,
			website_url VARCHAR(2048) NULL,
			landing_page_url VARCHAR(2048) NULL,
			business_description TEXT NULL,
			target_customer TEXT NULL,
			min_project_value_micros BIGINT NULL,
			max_project_value_micros BIGINT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			is_demo TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_project_slug (organization_id, slug),
			KEY pp_project_org_status (organization_id, status),
			CONSTRAINT {$p}pp_fk_project_org FOREIGN KEY (organization_id) REFERENCES {$t('organizations')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('imports')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			report_type VARCHAR(40) NULL,
			status VARCHAR(20) NOT NULL,
			original_filename VARCHAR(255) NOT NULL,
			file_format VARCHAR(10) NOT NULL,
			file_size INT UNSIGNED NOT NULL DEFAULT 0,
			file_hash CHAR(64) NOT NULL,
			encoding VARCHAR(20) NULL,
			delimiter VARCHAR(4) NULL,
			header_row INT UNSIGNED NULL,
			number_format VARCHAR(10) NULL,
			column_mapping LONGTEXT NULL,
			meta LONGTEXT NULL,
			granularity VARCHAR(10) NULL,
			date_from DATE NULL,
			date_to DATE NULL,
			currency CHAR(3) NULL,
			rows_total INT UNSIGNED NOT NULL DEFAULT 0,
			rows_imported INT UNSIGNED NOT NULL DEFAULT 0,
			rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
			errors LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY pp_import_project (project_id, created_at),
			KEY pp_import_hash (project_id, file_hash),
			CONSTRAINT {$p}pp_fk_import_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		// Surowe wiersze pliku między uploadem a zatwierdzeniem importu (podgląd + mapowanie kolumn).
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('import_rows')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			import_id BIGINT UNSIGNED NOT NULL,
			line_no INT UNSIGNED NOT NULL,
			cells LONGTEXT NOT NULL,
			PRIMARY KEY (id),
			KEY pp_import_rows_import (import_id, line_no),
			CONSTRAINT {$p}pp_fk_rows_import FOREIGN KEY (import_id) REFERENCES {$t('imports')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('campaigns')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			natural_key CHAR(40) NOT NULL,
			external_id VARCHAR(64) NULL,
			name VARCHAR(512) NOT NULL,
			status VARCHAR(20) NULL,
			status_raw VARCHAR(128) NULL,
			channel_type VARCHAR(128) NULL,
			budget_micros BIGINT NULL,
			budget_period VARCHAR(64) NULL,
			bidding_strategy VARCHAR(191) NULL,
			last_import_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_campaign_key (project_id, natural_key),
			KEY pp_campaign_external (project_id, external_id),
			KEY pp_campaign_status (project_id, status),
			CONSTRAINT {$p}pp_fk_campaign_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_campaign_import FOREIGN KEY (last_import_id) REFERENCES {$t('imports')} (id) ON DELETE SET NULL
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('ad_groups')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			campaign_id BIGINT UNSIGNED NOT NULL,
			natural_key CHAR(40) NOT NULL,
			external_id VARCHAR(64) NULL,
			name VARCHAR(512) NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_ad_group_key (project_id, natural_key),
			KEY pp_ad_group_campaign (campaign_id),
			CONSTRAINT {$p}pp_fk_ad_group_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_ad_group_campaign FOREIGN KEY (campaign_id) REFERENCES {$t('campaigns')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('keywords')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			campaign_id BIGINT UNSIGNED NOT NULL,
			ad_group_id BIGINT UNSIGNED NULL,
			natural_key CHAR(40) NOT NULL,
			external_id VARCHAR(64) NULL,
			text VARCHAR(512) NOT NULL,
			match_type VARCHAR(32) NULL,
			status VARCHAR(20) NULL,
			status_raw VARCHAR(128) NULL,
			quality_score TINYINT UNSIGNED NULL,
			max_cpc_micros BIGINT NULL,
			last_import_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_keyword_key (project_id, natural_key),
			KEY pp_keyword_campaign (campaign_id),
			CONSTRAINT {$p}pp_fk_keyword_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_keyword_campaign FOREIGN KEY (campaign_id) REFERENCES {$t('campaigns')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_keyword_ad_group FOREIGN KEY (ad_group_id) REFERENCES {$t('ad_groups')} (id) ON DELETE SET NULL,
			CONSTRAINT {$p}pp_fk_keyword_import FOREIGN KEY (last_import_id) REFERENCES {$t('imports')} (id) ON DELETE SET NULL
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('search_terms')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			campaign_id BIGINT UNSIGNED NOT NULL,
			ad_group_id BIGINT UNSIGNED NULL,
			natural_key CHAR(40) NOT NULL,
			term VARCHAR(512) NOT NULL,
			match_type VARCHAR(32) NULL,
			keyword_text VARCHAR(512) NULL,
			added_excluded VARCHAR(64) NULL,
			last_import_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_search_term_key (project_id, natural_key),
			KEY pp_search_term_campaign (project_id, campaign_id),
			KEY pp_search_term_term (project_id, term(64)),
			CONSTRAINT {$p}pp_fk_st_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_st_campaign FOREIGN KEY (campaign_id) REFERENCES {$t('campaigns')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_st_ad_group FOREIGN KEY (ad_group_id) REFERENCES {$t('ad_groups')} (id) ON DELETE SET NULL,
			CONSTRAINT {$p}pp_fk_st_import FOREIGN KEY (last_import_id) REFERENCES {$t('imports')} (id) ON DELETE SET NULL
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('conversion_actions')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			natural_key CHAR(40) NOT NULL,
			external_id VARCHAR(64) NULL,
			name VARCHAR(512) NOT NULL,
			category VARCHAR(191) NULL,
			source VARCHAR(191) NULL,
			status VARCHAR(20) NULL,
			status_raw VARCHAR(128) NULL,
			include_in_conversions TINYINT(1) NULL,
			last_import_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_conversion_action_key (project_id, natural_key),
			CONSTRAINT {$p}pp_fk_ca_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_ca_import FOREIGN KEY (last_import_id) REFERENCES {$t('imports')} (id) ON DELETE SET NULL
		) ENGINE=InnoDB {$charset}";

		$metricColumns = "
			granularity VARCHAR(10) NOT NULL,
			date_from DATE NOT NULL,
			date_to DATE NOT NULL,
			impressions BIGINT UNSIGNED NULL,
			clicks BIGINT UNSIGNED NULL,
			cost_micros BIGINT NULL,
			conversions DECIMAL(18,4) NULL,
			conversions_value_micros BIGINT NULL,
			all_conversions DECIMAL(18,4) NULL,
			currency CHAR(3) NULL,
			import_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,";

		foreach ([
			'campaign_metrics' => ['campaign_id', 'campaigns'],
			'keyword_metrics' => ['keyword_id', 'keywords'],
			'search_term_metrics' => ['search_term_id', 'search_terms'],
		] as $table => [$column, $parent]) {
			$statements[] = "CREATE TABLE IF NOT EXISTS {$t($table)} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				project_id BIGINT UNSIGNED NOT NULL,
				{$column} BIGINT UNSIGNED NOT NULL,
				{$metricColumns}
				PRIMARY KEY (id),
				UNIQUE KEY pp_{$table}_period ({$column}, granularity, date_from, date_to),
				KEY pp_{$table}_project_period (project_id, granularity, date_from, date_to),
				KEY pp_{$table}_import (import_id),
				CONSTRAINT {$p}pp_fk_{$table}_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE,
				CONSTRAINT {$p}pp_fk_{$table}_entity FOREIGN KEY ({$column}) REFERENCES {$t($parent)} (id) ON DELETE CASCADE,
				CONSTRAINT {$p}pp_fk_{$table}_import FOREIGN KEY (import_id) REFERENCES {$t('imports')} (id) ON DELETE CASCADE
			) ENGINE=InnoDB {$charset}";
		}

		// campaign_scope = 0 oznacza dane dla całego konta (raport bez podziału na kampanie).
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('conversion_metrics')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			conversion_action_id BIGINT UNSIGNED NOT NULL,
			campaign_scope BIGINT UNSIGNED NOT NULL DEFAULT 0,
			granularity VARCHAR(10) NOT NULL,
			date_from DATE NOT NULL,
			date_to DATE NOT NULL,
			conversions DECIMAL(18,4) NULL,
			all_conversions DECIMAL(18,4) NULL,
			conversions_value_micros BIGINT NULL,
			all_conversions_value_micros BIGINT NULL,
			currency CHAR(3) NULL,
			import_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_conversion_metrics_period (conversion_action_id, campaign_scope, granularity, date_from, date_to),
			KEY pp_conversion_metrics_project_period (project_id, granularity, date_from, date_to),
			CONSTRAINT {$p}pp_fk_cm_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_cm_action FOREIGN KEY (conversion_action_id) REFERENCES {$t('conversion_actions')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_cm_import FOREIGN KEY (import_id) REFERENCES {$t('imports')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		return $statements;
	}

	/**
	 * Konwersje po obejrzeniu (view-through) jako osobna kolumna – nie są sumowane z konwersjami.
	 * Kolumny dodajemy tylko, jeśli ich jeszcze nie ma (MySQL nie obsługuje ADD COLUMN IF NOT EXISTS).
	 */
	public static function viewThroughConversions(Db $db, string $charset): array
	{
		$statements = [];

		foreach (['campaign_metrics', 'keyword_metrics', 'search_term_metrics', 'conversion_metrics'] as $table) {
			$exists = $db->scalar(
				'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
				[$db->table($table), 'view_through_conversions']
			);

			if ($exists === null) {
				$statements[] = "ALTER TABLE {$db->table($table)} ADD COLUMN view_through_conversions DECIMAL(18,4) NULL AFTER conversions";
			}
		}

		return $statements;
	}
}
