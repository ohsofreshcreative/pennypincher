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

	/**
	 * Etap 2: dane z Google Ads API obok importów, synchronizacja i audyty.
	 *
	 * - metryki dostają kolumnę `source` (import|api) i trafiają do unikalnego klucza, więc dane z API
	 *   nie nadpisują historii z importów; o tym, które źródło liczyć, decyduje PeriodResolver,
	 * - `import_id` staje się opcjonalne (wiersze z API mają `sync_run_id`),
	 * - nowe tabele: połączenia Google Ads, przebiegi synchronizacji, pokrycie dat danymi API, audyty.
	 *
	 * Każda zmiana jest wykonywana tylko, jeśli jeszcze jej nie ma – migracja jest bezpieczna przy ponowieniu.
	 */
	public static function googleAds(Db $db, string $charset): array
	{
		$t = fn (string $name) => $db->table($name);
		$p = $db->wpdb()->prefix;
		$statements = [];

		$hasColumn = fn (string $table, string $column) => $db->scalar(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
			[$t($table), $column]
		) !== null;

		$hasIndex = fn (string $table, string $index) => $db->scalar(
			'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
			[$t($table), $index]
		) !== null;

		$hasTable = fn (string $table) => $db->scalar(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
			[$t($table)]
		) !== null;

		// Tabele połączeń i przebiegów muszą istnieć przed kluczami obcymi w metrykach.
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('google_ads_connections')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			customer_id VARCHAR(20) NULL,
			customer_name VARCHAR(255) NULL,
			login_customer_id VARCHAR(20) NULL,
			currency CHAR(3) NULL,
			time_zone VARCHAR(64) NULL,
			google_email VARCHAR(255) NULL,
			refresh_token TEXT NULL,
			access_token TEXT NULL,
			access_token_expires_at DATETIME NULL,
			scopes VARCHAR(500) NULL,
			status VARCHAR(30) NOT NULL,
			last_error TEXT NULL,
			connected_by BIGINT UNSIGNED NULL,
			connected_at DATETIME NULL,
			disconnected_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_gac_project (project_id, status),
			KEY pp_gac_customer (customer_id),
			CONSTRAINT {$p}pp_fk_gac_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('sync_runs')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			connection_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL,
			date_from DATE NOT NULL,
			date_to DATE NOT NULL,
			steps LONGTEXT NOT NULL,
			step_index INT UNSIGNED NOT NULL DEFAULT 0,
			steps_total INT UNSIGNED NOT NULL DEFAULT 0,
			records_imported INT UNSIGNED NOT NULL DEFAULT 0,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			log LONGTEXT NULL,
			requested_by BIGINT UNSIGNED NULL,
			next_attempt_at DATETIME NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_sync_project (project_id, created_at),
			KEY pp_sync_queue (status, next_attempt_at),
			CONSTRAINT {$p}pp_fk_sync_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_sync_connection FOREIGN KEY (connection_id) REFERENCES {$t('google_ads_connections')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		// Zakresy dat, za które dane z API są kompletne (także dni bez aktywności – API ich nie zwraca).
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('data_coverage')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			source VARCHAR(10) NOT NULL,
			dataset VARCHAR(40) NOT NULL,
			date_from DATE NOT NULL,
			date_to DATE NOT NULL,
			sync_run_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_coverage_window (project_id, source, dataset, date_from, date_to),
			CONSTRAINT {$p}pp_fk_coverage_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('audit_runs')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL,
			score TINYINT UNSIGNED NULL,
			score_breakdown LONGTEXT NULL,
			data_quality LONGTEXT NULL,
			date_from DATE NOT NULL,
			date_to DATE NOT NULL,
			rules_version VARCHAR(20) NOT NULL,
			trigger_type VARCHAR(20) NOT NULL,
			requested_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_audit_project (project_id, created_at),
			CONSTRAINT {$p}pp_fk_audit_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('audit_findings')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			audit_run_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			rule_id VARCHAR(20) NOT NULL,
			category VARCHAR(40) NOT NULL,
			severity VARCHAR(20) NOT NULL,
			title VARCHAR(255) NOT NULL,
			description TEXT NOT NULL,
			evidence LONGTEXT NULL,
			recommendation TEXT NOT NULL,
			entity_type VARCHAR(40) NULL,
			entity_id BIGINT UNSIGNED NULL,
			fingerprint CHAR(40) NOT NULL,
			penalty DECIMAL(6,2) NOT NULL DEFAULT 0,
			score_dimension VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			status_changed_by BIGINT UNSIGNED NULL,
			status_changed_at DATETIME NULL,
			detected_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_finding_run (audit_run_id, severity),
			KEY pp_finding_fingerprint (project_id, fingerprint),
			CONSTRAINT {$p}pp_fk_finding_run FOREIGN KEY (audit_run_id) REFERENCES {$t('audit_runs')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_finding_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$metricTables = [
			'campaign_metrics' => 'campaign_id',
			'keyword_metrics' => 'keyword_id',
			'search_term_metrics' => 'search_term_id',
			'conversion_metrics' => 'conversion_action_id',
		];

		foreach ($metricTables as $table => $entity) {
			if (! $hasColumn($table, 'source')) {
				$statements[] = "ALTER TABLE {$t($table)} ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'import' AFTER date_to";
			}

			if (! $hasColumn($table, 'sync_run_id')) {
				$statements[] = "ALTER TABLE {$t($table)} ADD COLUMN sync_run_id BIGINT UNSIGNED NULL AFTER import_id, ADD KEY pp_{$table}_sync (sync_run_id)";
			}

			$statements[] = "ALTER TABLE {$t($table)} MODIFY import_id BIGINT UNSIGNED NULL";

			$keyColumns = $table === 'conversion_metrics'
				? "{$entity}, campaign_scope, source, granularity, date_from, date_to"
				: "{$entity}, source, granularity, date_from, date_to";
			$oldIndex = $table === 'conversion_metrics' ? 'pp_conversion_metrics_period' : "pp_{$table}_period";

			if (! $hasIndex($table, "pp_{$table}_src_period")) {
				// Najpierw nowy indeks (zaczyna się od kolumny klucza obcego), dopiero potem usunięcie starego.
				$statements[] = "ALTER TABLE {$t($table)} ADD UNIQUE KEY pp_{$table}_src_period ({$keyColumns})";
			}

			if ($hasIndex($table, $oldIndex)) {
				$statements[] = "ALTER TABLE {$t($table)} DROP INDEX {$oldIndex}";
			}

			if (! $hasIndex($table, "pp_{$table}_project_src")) {
				$statements[] = "ALTER TABLE {$t($table)} ADD KEY pp_{$table}_project_src (project_id, source, granularity, date_from)";
			}
		}

		if (! $hasColumn('campaigns', 'settings')) {
			$statements[] = "ALTER TABLE {$t('campaigns')} ADD COLUMN settings LONGTEXT NULL AFTER bidding_strategy";
		}

		if (! $hasColumn('ad_groups', 'status')) {
			$statements[] = "ALTER TABLE {$t('ad_groups')} ADD COLUMN status VARCHAR(20) NULL AFTER name";
		}

		foreach ([
			'type' => 'VARCHAR(64) NULL',
			'category_code' => 'VARCHAR(64) NULL',
			'primary_for_goal' => 'TINYINT(1) NULL',
			'counting_type' => 'VARCHAR(40) NULL',
			'settings' => 'LONGTEXT NULL',
		] as $column => $definition) {
			if (! $hasColumn('conversion_actions', $column)) {
				$statements[] = "ALTER TABLE {$t('conversion_actions')} ADD COLUMN {$column} {$definition}";
			}
		}

		return $statements;
	}

	/**
	 * Etap 3: okresy audytu, kontekst biznesowy, klasyfikacja konwersji, wykluczenia z API,
	 * Budget Leaks, rekomendacje, okazje słów kluczowych i DataForSEO. Wyłącznie zmiany addytywne.
	 */
	public static function analysis(Db $db, string $charset): array
	{
		$t = fn (string $name) => $db->table($name);
		$p = $db->wpdb()->prefix;
		$statements = [];

		$hasColumn = fn (string $table, string $column) => $db->scalar(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
			[$t($table), $column]
		) !== null;

		$hasIndex = fn (string $table, string $index) => $db->scalar(
			'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
			[$t($table), $index]
		) !== null;

		foreach (['business_context' => 'LONGTEXT NULL', 'dataforseo_monthly_limit' => 'DECIMAL(10,2) NULL'] as $column => $definition) {
			if (! $hasColumn('projects', $column)) {
				$statements[] = "ALTER TABLE {$t('projects')} ADD COLUMN {$column} {$definition}";
			}
		}

		foreach ([
			'business_class' => 'VARCHAR(20) NULL',
			'business_goal' => 'TINYINT(1) NULL',
			'classified_by' => 'BIGINT UNSIGNED NULL',
			'classified_at' => 'DATETIME NULL',
		] as $column => $definition) {
			if (! $hasColumn('conversion_actions', $column)) {
				$statements[] = "ALTER TABLE {$t('conversion_actions')} ADD COLUMN {$column} {$definition}";
			}
		}

		if (! $hasColumn('audit_runs', 'range_preset')) {
			$statements[] = "ALTER TABLE {$t('audit_runs')} ADD COLUMN range_preset VARCHAR(20) NULL AFTER date_to";
		}

		if (! $hasIndex('audit_runs', 'pp_audit_period')) {
			$statements[] = "ALTER TABLE {$t('audit_runs')} ADD KEY pp_audit_period (project_id, date_from, date_to)";
		}

		// Słowa wykluczające z Google Ads (kampania, grupa reklam, listy wspólne) – tylko odczyt.
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('negative_keywords')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			natural_key CHAR(40) NOT NULL,
			level VARCHAR(20) NOT NULL,
			campaign_id BIGINT UNSIGNED NULL,
			ad_group_id BIGINT UNSIGNED NULL,
			shared_set_external_id VARCHAR(64) NULL,
			shared_set_name VARCHAR(255) NULL,
			text VARCHAR(500) NOT NULL,
			normalized VARCHAR(500) NOT NULL,
			match_type VARCHAR(20) NULL,
			sync_run_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_negative_key (project_id, natural_key),
			KEY pp_negative_project (project_id, level),
			CONSTRAINT {$p}pp_fk_negative_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('analysis_runs')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(30) NOT NULL,
			status VARCHAR(20) NOT NULL,
			date_from DATE NOT NULL,
			date_to DATE NOT NULL,
			range_preset VARCHAR(20) NULL,
			summary LONGTEXT NULL,
			trigger_type VARCHAR(20) NOT NULL,
			requested_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_analysis_project (project_id, type, date_from, date_to),
			CONSTRAINT {$p}pp_fk_analysis_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('budget_leaks')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			analysis_run_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			rule_id VARCHAR(20) NOT NULL,
			category VARCHAR(30) NOT NULL,
			fingerprint CHAR(40) NOT NULL,
			title VARCHAR(255) NOT NULL,
			entity_type VARCHAR(30) NULL,
			entity_id BIGINT UNSIGNED NULL,
			campaign_id BIGINT UNSIGNED NULL,
			ad_group_id BIGINT UNSIGNED NULL,
			subject VARCHAR(500) NULL,
			cost_micros BIGINT NULL,
			clicks BIGINT NULL,
			conversions DECIMAL(18,4) NULL,
			valuable_conversions DECIMAL(18,4) NULL,
			confidence VARCHAR(10) NOT NULL,
			priority VARCHAR(10) NOT NULL,
			priority_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			savings_micros BIGINT NULL,
			savings_group VARCHAR(80) NULL,
			reason TEXT NOT NULL,
			action TEXT NOT NULL,
			evidence LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			status_changed_by BIGINT UNSIGNED NULL,
			status_changed_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_leak_run (analysis_run_id, priority_score),
			KEY pp_leak_fingerprint (project_id, fingerprint),
			CONSTRAINT {$p}pp_fk_leak_run FOREIGN KEY (analysis_run_id) REFERENCES {$t('analysis_runs')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_leak_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('negative_suggestions')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			analysis_run_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			fingerprint CHAR(40) NOT NULL,
			phrase VARCHAR(255) NOT NULL,
			reason_code VARCHAR(30) NOT NULL,
			reason TEXT NOT NULL,
			related_terms LONGTEXT NULL,
			cost_micros BIGINT NULL,
			clicks BIGINT NULL,
			conversions DECIMAL(18,4) NULL,
			confidence VARCHAR(10) NOT NULL,
			level VARCHAR(20) NOT NULL,
			campaign_id BIGINT UNSIGNED NULL,
			match_type VARCHAR(20) NOT NULL,
			already_excluded TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_negsug_run (analysis_run_id),
			KEY pp_negsug_fingerprint (project_id, fingerprint),
			CONSTRAINT {$p}pp_fk_negsug_run FOREIGN KEY (analysis_run_id) REFERENCES {$t('analysis_runs')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_negsug_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('keyword_opportunities')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			analysis_run_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			fingerprint CHAR(40) NOT NULL,
			phrase VARCHAR(255) NOT NULL,
			sources VARCHAR(60) NOT NULL,
			score TINYINT UNSIGNED NOT NULL,
			score_breakdown LONGTEXT NULL,
			confidence VARCHAR(10) NOT NULL,
			intent VARCHAR(20) NULL,
			search_volume INT UNSIGNED NULL,
			cpc_micros BIGINT NULL,
			cpc_currency CHAR(3) NULL,
			competition VARCHAR(20) NULL,
			suggested_campaign_id BIGINT UNSIGNED NULL,
			suggested_ad_group_id BIGINT UNSIGNED NULL,
			reason TEXT NOT NULL,
			evidence LONGTEXT NULL,
			data_updated_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_opp_run (analysis_run_id, score),
			KEY pp_opp_fingerprint (project_id, fingerprint),
			CONSTRAINT {$p}pp_fk_opp_run FOREIGN KEY (analysis_run_id) REFERENCES {$t('analysis_runs')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_opp_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('recommendations')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			fingerprint CHAR(40) NOT NULL,
			source VARCHAR(30) NOT NULL,
			type VARCHAR(40) NOT NULL,
			title VARCHAR(255) NOT NULL,
			description TEXT NOT NULL,
			rationale TEXT NOT NULL,
			evidence LONGTEXT NULL,
			action TEXT NOT NULL,
			campaign_id BIGINT UNSIGNED NULL,
			ad_group_id BIGINT UNSIGNED NULL,
			priority VARCHAR(10) NOT NULL,
			priority_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			confidence VARCHAR(10) NOT NULL,
			impact_micros BIGINT NULL,
			impact_label VARCHAR(255) NULL,
			date_from DATE NOT NULL,
			date_to DATE NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			note TEXT NULL,
			is_obsolete TINYINT(1) NOT NULL DEFAULT 0,
			obsolete_reason VARCHAR(255) NULL,
			last_analysis_run_id BIGINT UNSIGNED NULL,
			first_seen_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_rec_fingerprint (project_id, fingerprint),
			KEY pp_rec_list (project_id, is_obsolete, status, priority_score),
			CONSTRAINT {$p}pp_fk_rec_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('recommendation_events')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			recommendation_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			event VARCHAR(20) NOT NULL,
			from_status VARCHAR(20) NULL,
			to_status VARCHAR(20) NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_recevent_rec (recommendation_id, created_at),
			CONSTRAINT {$p}pp_fk_recevent_rec FOREIGN KEY (recommendation_id) REFERENCES {$t('recommendations')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_recevent_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		// DataForSEO: zadania w kolejce (płatne – tylko po zgodzie użytkownika), pamięć podręczna odpowiedzi, wyniki.
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('dataforseo_tasks')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			organization_id BIGINT UNSIGNED NOT NULL,
			endpoint VARCHAR(120) NOT NULL,
			payload LONGTEXT NOT NULL,
			payload_hash CHAR(40) NOT NULL,
			status VARCHAR(20) NOT NULL,
			estimated_cost DECIMAL(10,4) NULL,
			cost DECIMAL(10,4) NULL,
			from_cache TINYINT(1) NOT NULL DEFAULT 0,
			result_count INT UNSIGNED NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NULL,
			last_error TEXT NULL,
			error_code VARCHAR(20) NULL,
			requested_by BIGINT UNSIGNED NULL,
			approved_at DATETIME NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_dfs_queue (status, next_attempt_at),
			KEY pp_dfs_project (project_id, created_at),
			KEY pp_dfs_org_month (organization_id, finished_at),
			CONSTRAINT {$p}pp_fk_dfs_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('dataforseo_cache')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cache_key CHAR(40) NOT NULL,
			endpoint VARCHAR(120) NOT NULL,
			response LONGTEXT NOT NULL,
			cost DECIMAL(10,4) NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_dfs_cache_key (cache_key)
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('keyword_ideas')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			task_id BIGINT UNSIGNED NULL,
			keyword VARCHAR(255) NOT NULL,
			normalized VARCHAR(255) NOT NULL,
			location_code INT UNSIGNED NOT NULL,
			language_code VARCHAR(10) NOT NULL,
			seed VARCHAR(255) NULL,
			search_volume INT UNSIGNED NULL,
			cpc_micros BIGINT NULL,
			competition VARCHAR(20) NULL,
			competition_index TINYINT UNSIGNED NULL,
			low_bid_micros BIGINT NULL,
			high_bid_micros BIGINT NULL,
			source VARCHAR(60) NOT NULL,
			fetched_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_idea_key (project_id, normalized, location_code, language_code, source),
			CONSTRAINT {$p}pp_fk_idea_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		return $statements;
	}
}
