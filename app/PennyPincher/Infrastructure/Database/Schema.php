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
	/**
	 * Prompt 4: Keyword Intelligence 2.0, Campaign Planner (z budżetem), Action Plan, Monitoring, kursy walut.
	 * Wyłącznie addytywnie: nowe tabele i kolumny (warunkowo), bez zmian istniejących danych.
	 */
	public static function planning(Db $db, string $charset): array
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

		if (! $hasColumn('projects', 'keyword_seeds')) {
			$statements[] = "ALTER TABLE {$t('projects')} ADD COLUMN keyword_seeds LONGTEXT NULL";
		}

		// DataForSEO zwraca CPC Google Ads w USD – waluta zapisywana jawnie (dotychczasowe wiersze: USD).
		if (! $hasColumn('keyword_ideas', 'cpc_currency')) {
			$statements[] = "ALTER TABLE {$t('keyword_ideas')} ADD COLUMN cpc_currency CHAR(3) NOT NULL DEFAULT 'USD' AFTER cpc_micros";
		}

		foreach ([
			'score_version' => "VARCHAR(10) NOT NULL DEFAULT '1.0'",
			'confidence_score' => 'TINYINT UNSIGNED NULL',
			'explanation' => 'LONGTEXT NULL',
			'normalized' => 'VARCHAR(255) NULL',
			'cluster_key' => 'CHAR(40) NULL',
			'quality_flags' => 'VARCHAR(255) NULL',
			'cpc_project_micros' => 'BIGINT NULL',
			'fx_rate' => 'DECIMAL(18,8) NULL',
			'fx_date' => 'DATE NULL',
			'fx_source' => 'VARCHAR(80) NULL',
		] as $column => $definition) {
			if (! $hasColumn('keyword_opportunities', $column)) {
				$statements[] = "ALTER TABLE {$t('keyword_opportunities')} ADD COLUMN {$column} {$definition}";
			}
		}

		if (! $hasIndex('keyword_opportunities', 'pp_opp_cluster')) {
			$statements[] = "ALTER TABLE {$t('keyword_opportunities')} ADD KEY pp_opp_cluster (analysis_run_id, cluster_key)";
		}

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('keyword_clusters')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			analysis_run_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			cluster_key CHAR(40) NOT NULL,
			name VARCHAR(255) NOT NULL,
			topic VARCHAR(255) NULL,
			service VARCHAR(255) NULL,
			intent VARCHAR(20) NULL,
			phrase_count INT UNSIGNED NOT NULL DEFAULT 0,
			members LONGTEXT NULL,
			volume_max INT UNSIGNED NULL,
			volume_sum INT UNSIGNED NULL,
			volume_known INT UNSIGNED NOT NULL DEFAULT 0,
			cpc_project_micros BIGINT NULL,
			cpc_original LONGTEXT NULL,
			score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			confidence VARCHAR(10) NOT NULL,
			suggested_campaign_id BIGINT UNSIGNED NULL,
			suggested_ad_group_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_cluster_run (analysis_run_id, cluster_key),
			KEY pp_cluster_project (project_id, cluster_key),
			CONSTRAINT {$p}pp_fk_cluster_run FOREIGN KEY (analysis_run_id) REFERENCES {$t('analysis_runs')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_cluster_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		// Decyzje użytkownika o frazach – jedna na frazę w projekcie, niezależna od przebiegów analiz i synchronizacji.
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('keyword_decisions')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			normalized VARCHAR(255) NOT NULL,
			phrase VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			cluster_key CHAR(40) NULL,
			plan_id BIGINT UNSIGNED NULL,
			note TEXT NULL,
			decided_by BIGINT UNSIGNED NULL,
			decided_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_kwdec_phrase (project_id, normalized),
			KEY pp_kwdec_status (project_id, status),
			CONSTRAINT {$p}pp_fk_kwdec_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('keyword_decision_events')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			decision_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			event VARCHAR(20) NOT NULL,
			from_status VARCHAR(20) NULL,
			to_status VARCHAR(20) NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_kwdecev (decision_id, created_at),
			CONSTRAINT {$p}pp_fk_kwdecev_dec FOREIGN KEY (decision_id) REFERENCES {$t('keyword_decisions')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_kwdecev_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('exchange_rates')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			currency_from CHAR(3) NOT NULL,
			currency_to CHAR(3) NOT NULL,
			rate DECIMAL(18,8) NOT NULL,
			rate_date DATE NOT NULL,
			source VARCHAR(80) NOT NULL,
			fetched_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_fx_key (currency_from, currency_to, rate_date, source),
			KEY pp_fx_latest (currency_from, currency_to, rate_date)
		) ENGINE=InnoDB {$charset}";

		// Campaign Planner (plany nie są wysyłane do Google Ads).
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('campaign_plans')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			goal VARCHAR(40) NULL,
			service VARCHAR(255) NULL,
			audience TEXT NULL,
			location VARCHAR(255) NULL,
			language VARCHAR(10) NULL,
			currency CHAR(3) NOT NULL,
			monthly_budget_micros BIGINT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			scenario VARCHAR(20) NOT NULL DEFAULT 'balanced',
			scenario_config LONGTEXT NULL,
			notes TEXT NULL,
			copied_from_id BIGINT UNSIGNED NULL,
			created_by BIGINT UNSIGNED NULL,
			approved_by BIGINT UNSIGNED NULL,
			approved_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_plan_project (project_id, status, updated_at),
			CONSTRAINT {$p}pp_fk_plan_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('plan_campaigns')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'search',
			budget_micros BIGINT NULL,
			final_url VARCHAR(2048) NULL,
			notes TEXT NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_plancmp_plan (plan_id, position),
			CONSTRAINT {$p}pp_fk_plancmp_plan FOREIGN KEY (plan_id) REFERENCES {$t('campaign_plans')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_plancmp_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('plan_ad_groups')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_campaign_id BIGINT UNSIGNED NOT NULL,
			plan_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			cluster_key CHAR(40) NULL,
			final_url VARCHAR(2048) NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_plangrp_campaign (plan_campaign_id, position),
			KEY pp_plangrp_plan (plan_id),
			CONSTRAINT {$p}pp_fk_plangrp_campaign FOREIGN KEY (plan_campaign_id) REFERENCES {$t('plan_campaigns')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_plangrp_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('plan_keywords')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_ad_group_id BIGINT UNSIGNED NOT NULL,
			plan_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			phrase VARCHAR(255) NOT NULL,
			normalized VARCHAR(255) NOT NULL,
			match_type VARCHAR(10) NOT NULL,
			intent VARCHAR(20) NULL,
			score TINYINT UNSIGNED NULL,
			confidence VARCHAR(10) NULL,
			cpc_micros BIGINT NULL,
			cpc_currency CHAR(3) NULL,
			cpc_project_micros BIGINT NULL,
			search_volume INT UNSIGNED NULL,
			source VARCHAR(40) NOT NULL,
			term_clicks INT UNSIGNED NULL,
			term_cost_micros BIGINT NULL,
			term_conversions DECIMAL(18,4) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_plankw_unique (plan_ad_group_id, normalized, match_type),
			KEY pp_plankw_plan (plan_id, normalized),
			CONSTRAINT {$p}pp_fk_plankw_group FOREIGN KEY (plan_ad_group_id) REFERENCES {$t('plan_ad_groups')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_plankw_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('plan_negatives')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			level VARCHAR(10) NOT NULL,
			plan_campaign_id BIGINT UNSIGNED NULL,
			plan_ad_group_id BIGINT UNSIGNED NULL,
			scope_key VARCHAR(40) NOT NULL,
			phrase VARCHAR(255) NOT NULL,
			normalized VARCHAR(255) NOT NULL,
			match_type VARCHAR(10) NOT NULL,
			source VARCHAR(40) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_planneg_unique (plan_id, scope_key, normalized, match_type),
			CONSTRAINT {$p}pp_fk_planneg_plan FOREIGN KEY (plan_id) REFERENCES {$t('campaign_plans')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_planneg_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('plan_ads')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_ad_group_id BIGINT UNSIGNED NOT NULL,
			plan_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			headlines LONGTEXT NULL,
			descriptions LONGTEXT NULL,
			path1 VARCHAR(30) NULL,
			path2 VARCHAR(30) NULL,
			final_url VARCHAR(2048) NULL,
			cta VARCHAR(60) NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_planad_group (plan_ad_group_id),
			CONSTRAINT {$p}pp_fk_planad_group FOREIGN KEY (plan_ad_group_id) REFERENCES {$t('plan_ad_groups')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_planad_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('plan_events')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			event VARCHAR(30) NOT NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_planev (plan_id, created_at),
			CONSTRAINT {$p}pp_fk_planev_plan FOREIGN KEY (plan_id) REFERENCES {$t('campaign_plans')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		// Action Plan – jedno działanie na odcisk w projekcie; status i notatki należą do użytkownika.
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('action_items')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			fingerprint CHAR(40) NOT NULL,
			category VARCHAR(30) NOT NULL,
			title VARCHAR(255) NOT NULL,
			description TEXT NOT NULL,
			rationale TEXT NULL,
			details LONGTEXT NULL,
			sources VARCHAR(255) NULL,
			recommendation_ids LONGTEXT NULL,
			depends_on LONGTEXT NULL,
			priority VARCHAR(10) NOT NULL,
			priority_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			confidence VARCHAR(10) NOT NULL,
			effort VARCHAR(10) NOT NULL,
			expected_effect VARCHAR(255) NULL,
			campaign_id BIGINT UNSIGNED NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'todo',
			auto_blocked TINYINT(1) NOT NULL DEFAULT 0,
			note TEXT NULL,
			is_obsolete TINYINT(1) NOT NULL DEFAULT 0,
			date_from DATE NULL,
			date_to DATE NULL,
			first_seen_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_action_fp (project_id, fingerprint),
			KEY pp_action_list (project_id, is_obsolete, status, priority_score),
			CONSTRAINT {$p}pp_fk_action_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('action_item_events')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			action_item_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			event VARCHAR(20) NOT NULL,
			from_status VARCHAR(20) NULL,
			to_status VARCHAR(20) NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_actionev (action_item_id, created_at),
			CONSTRAINT {$p}pp_fk_actionev_item FOREIGN KEY (action_item_id) REFERENCES {$t('action_items')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_actionev_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		// Monitoring – alert per odcisk w projekcie (deduplikacja), historia zdarzeń, przebiegi (idempotencja po synchronizacji).
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('alerts')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			fingerprint CHAR(40) NOT NULL,
			type VARCHAR(40) NOT NULL,
			severity VARCHAR(10) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			title VARCHAR(255) NOT NULL,
			message TEXT NOT NULL,
			evidence LONGTEXT NULL,
			campaign_id BIGINT UNSIGNED NULL,
			recommendation_id BIGINT UNSIGNED NULL,
			condition_active TINYINT(1) NOT NULL DEFAULT 1,
			occurrences INT UNSIGNED NOT NULL DEFAULT 1,
			period_from DATE NULL,
			period_to DATE NULL,
			muted_until DATETIME NULL,
			first_detected_at DATETIME NOT NULL,
			last_detected_at DATETIME NOT NULL,
			resolved_at DATETIME NULL,
			last_notified_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_alert_fp (project_id, fingerprint),
			KEY pp_alert_list (project_id, status, last_detected_at),
			CONSTRAINT {$p}pp_fk_alert_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('alert_events')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			alert_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			event VARCHAR(20) NOT NULL,
			from_status VARCHAR(20) NULL,
			to_status VARCHAR(20) NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_alertev (alert_id, created_at),
			CONSTRAINT {$p}pp_fk_alertev_alert FOREIGN KEY (alert_id) REFERENCES {$t('alerts')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_alertev_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('monitoring_runs')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			sync_run_id BIGINT UNSIGNED NULL,
			trigger_type VARCHAR(20) NOT NULL,
			summary LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_monrun_sync (project_id, sync_run_id),
			KEY pp_monrun_project (project_id, created_at),
			CONSTRAINT {$p}pp_fk_monrun_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		return $statements;
	}

	/**
	 * Prompt 5: Change Center – zestawy zmian Google Ads, operacje, historia, próby, powiązania z identyfikatorami
	 * Google Ads, odczyty stanu konta; uprawnienie wykonawcy i zgoda na zapis per połączenie. Wyłącznie addytywnie.
	 *
	 * @return array<int, string>
	 */
	public static function changes(Db $db, string $charset): array
	{
		$t = fn (string $name) => $db->table($name);
		$p = $db->wpdb()->prefix;
		$statements = [];

		$hasColumn = fn (string $table, string $column) => $db->scalar(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
			[$t($table), $column]
		) !== null;

		// Uprawnienie „wykonawca zmian” nadaje właściciel organizacji (domyślnie nikt).
		if (! $hasColumn('organization_users', 'can_execute_changes')) {
			$statements[] = "ALTER TABLE {$t('organization_users')} ADD COLUMN can_execute_changes TINYINT(1) NOT NULL DEFAULT 0";
		}

		// Zgoda na zapis per połączenie – działa tylko, gdy serwer ma PP_GOOGLE_ADS_WRITE_MODE = write_enabled.
		foreach (['write_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0', 'write_enabled_by' => 'BIGINT UNSIGNED NULL', 'write_enabled_at' => 'DATETIME NULL'] as $column => $definition) {
			if (! $hasColumn('google_ads_connections', $column)) {
				$statements[] = "ALTER TABLE {$t('google_ads_connections')} ADD COLUMN {$column} {$definition}";
			}
		}

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('change_sets')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			organization_id BIGINT UNSIGNED NOT NULL,
			connection_id BIGINT UNSIGNED NULL,
			customer_id VARCHAR(20) NULL,
			title VARCHAR(255) NOT NULL,
			description TEXT NULL,
			source_type VARCHAR(40) NOT NULL DEFAULT 'manual',
			source_id BIGINT UNSIGNED NULL,
			source_label VARCHAR(255) NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'draft',
			risk_level VARCHAR(10) NOT NULL DEFAULT 'low',
			budget_impact_micros BIGINT NULL,
			version INT UNSIGNED NOT NULL DEFAULT 1,
			approved_version INT UNSIGNED NULL,
			approved_hash CHAR(64) NULL,
			approved_by BIGINT UNSIGNED NULL,
			approved_at DATETIME NULL,
			high_risk_confirmed TINYINT(1) NOT NULL DEFAULT 0,
			validation LONGTEXT NULL,
			deploy_requested_by BIGINT UNSIGNED NULL,
			deploy_requested_at DATETIME NULL,
			deploy_key CHAR(36) NULL,
			worker_token CHAR(36) NULL,
			next_attempt_at DATETIME NULL,
			deploy_started_at DATETIME NULL,
			deploy_finished_at DATETIME NULL,
			verified_at DATETIME NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			rollback_of BIGINT UNSIGNED NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_chset_deploy (deploy_key),
			KEY pp_chset_project (project_id, status, updated_at),
			KEY pp_chset_queue (status, next_attempt_at),
			KEY pp_chset_source (project_id, source_type, source_id),
			CONSTRAINT {$p}pp_fk_chset_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('change_operations')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			change_set_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			position INT UNSIGNED NOT NULL DEFAULT 0,
			op_uuid CHAR(36) NOT NULL,
			type VARCHAR(60) NOT NULL,
			resource_type VARCHAR(40) NOT NULL,
			resource_name VARCHAR(255) NULL,
			temp_ref VARCHAR(60) NULL,
			atomic_group VARCHAR(60) NULL,
			depends_on LONGTEXT NULL,
			label VARCHAR(255) NOT NULL,
			payload LONGTEXT NOT NULL,
			before_state LONGTEXT NULL,
			before_source VARCHAR(10) NULL,
			before_read_at DATETIME NULL,
			current_state LONGTEXT NULL,
			risk VARCHAR(10) NOT NULL DEFAULT 'low',
			warnings LONGTEXT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'pending',
			result_resource_name VARCHAR(255) NULL,
			error_code VARCHAR(100) NULL,
			error_message TEXT NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			applied_at DATETIME NULL,
			verified_at DATETIME NULL,
			verification LONGTEXT NULL,
			local_type VARCHAR(30) NULL,
			local_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_chop_uuid (op_uuid),
			KEY pp_chop_set (change_set_id, position),
			KEY pp_chop_resource (project_id, resource_name),
			CONSTRAINT {$p}pp_fk_chop_set FOREIGN KEY (change_set_id) REFERENCES {$t('change_sets')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_chop_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('change_events')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			change_set_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			operation_id BIGINT UNSIGNED NULL,
			user_id BIGINT UNSIGNED NULL,
			event VARCHAR(40) NOT NULL,
			from_status VARCHAR(30) NULL,
			to_status VARCHAR(30) NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_chev_set (change_set_id, id),
			CONSTRAINT {$p}pp_fk_chev_set FOREIGN KEY (change_set_id) REFERENCES {$t('change_sets')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_chev_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		// Każda próba kontaktu z Google Ads przy zmianie (walidacja, wykonanie, uzgodnienie, weryfikacja) – bez treści odpowiedzi i sekretów.
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('change_attempts')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			change_set_id BIGINT UNSIGNED NOT NULL,
			project_id BIGINT UNSIGNED NOT NULL,
			operation_ids VARCHAR(500) NULL,
			kind VARCHAR(30) NOT NULL,
			outcome VARCHAR(20) NOT NULL,
			customer_id VARCHAR(20) NULL,
			http_status SMALLINT UNSIGNED NULL,
			request_id VARCHAR(100) NULL,
			error_code VARCHAR(100) NULL,
			message TEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY pp_chatt_set (change_set_id, id),
			CONSTRAINT {$p}pp_fk_chatt_set FOREIGN KEY (change_set_id) REFERENCES {$t('change_sets')} (id) ON DELETE CASCADE,
			CONSTRAINT {$p}pp_fk_chatt_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		// Powiązania lokalnych obiektów (plan kampanii, grupa, słowo…) z zasobami utworzonymi w Google Ads – chroni przed ponownym utworzeniem.
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('google_ads_links')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			customer_id VARCHAR(20) NOT NULL,
			local_type VARCHAR(30) NOT NULL,
			local_id BIGINT UNSIGNED NOT NULL,
			resource_type VARCHAR(40) NOT NULL,
			resource_name VARCHAR(255) NOT NULL,
			change_operation_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_gal_local (project_id, customer_id, local_type, local_id),
			KEY pp_gal_resource (project_id, resource_name),
			CONSTRAINT {$p}pp_fk_gal_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		// Odczyty stanu konta na żądanie (reklamy, cele konwersji, budżety) – pamięć podręczna widoków, nie źródło decyzji o wdrożeniu.
		$statements[] = "CREATE TABLE IF NOT EXISTS {$t('google_ads_snapshots')} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id BIGINT UNSIGNED NOT NULL,
			customer_id VARCHAR(20) NOT NULL,
			kind VARCHAR(40) NOT NULL,
			scope_key VARCHAR(191) NOT NULL,
			data LONGTEXT NOT NULL,
			read_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY pp_gasnap_key (project_id, customer_id, kind, scope_key),
			CONSTRAINT {$p}pp_fk_gasnap_project FOREIGN KEY (project_id) REFERENCES {$t('projects')} (id) ON DELETE CASCADE
		) ENGINE=InnoDB {$charset}";

		return $statements;
	}
}
