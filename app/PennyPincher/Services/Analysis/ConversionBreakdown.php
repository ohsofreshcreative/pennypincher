<?php

namespace App\PennyPincher\Services\Analysis;

use App\PennyPincher\Domain\Conversions\ConversionClass;
use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Metrics\PeriodResolver;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\ConversionActionRepository;
use App\PennyPincher\Repositories\MetricsRepository;

/**
 * Metryki biznesowe konwersji na podstawie klasyfikacji działań (bez zmian w Google Ads).
 *
 * - Oryginalne konwersje Google Ads zostają bez zmian (kolumna „Konwersje”).
 * - Liczba działania: `conversions`, gdy działanie jest wliczane do konwersji, w przeciwnym razie `all_conversions`.
 * - Dane konta (campaign_scope = 0) i dane per kampania (campaign_scope > 0) to dwa widoki tych samych konwersji –
 *   nigdy ich nie sumujemy. Gdy danych per kampania brak, wartości kampanii są NULL (nie dzielimy proporcjonalnie).
 * - „Potwierdzone leady” są liczone tylko z działań sklasyfikowanych jako potwierdzone (np. import z CRM); brak takich = NULL.
 */
class ConversionBreakdown
{
	public const KEYS = ['google', 'google_micro', 'goals', 'leads', 'confirmed', 'potential', 'sales', 'micro', 'other', 'unclassified'];

	public function __construct(
		private Db $db,
		private MetricsRepository $metrics,
		private ConversionActionRepository $actions,
	) {
	}

	/**
	 * @return array{actions: array<int, array>, account: array<string, ?float>, campaigns: array<int, array<string, ?float>>, campaign_level_available: bool, confirmed_available: bool, manual: bool}
	 */
	public function forPeriod(int $projectId, DateRange $range): array
	{
		$actions = [];

		foreach ($this->actions->forProject($projectId) as $action) {
			$actions[$action['id']] = $action;
		}

		$confirmedAvailable = (bool) array_filter($actions, fn ($a) => $a['effective_class'] === ConversionClass::ConfirmedLead->value);
		$account = self::empty();
		$accountAvailable = false;
		$resolved = $this->metrics->resolve('conversion_metrics', $projectId, $range);

		if (! $resolved->isEmpty()) {
			foreach ($this->metrics->conversionActions($projectId, $resolved) as $row) {
				$action = $actions[(int) $row['id']] ?? null;

				if ($action !== null) {
					$accountAvailable = true;
					self::add($account, $action, $row);
				}
			}
		}

		$campaigns = [];
		$rows = $this->campaignRows($projectId, $range);

		foreach ($rows as $row) {
			$action = $actions[(int) $row['conversion_action_id']] ?? null;

			if ($action === null) {
				continue;
			}

			$campaignId = (int) $row['campaign_scope'];
			$campaigns[$campaignId] ??= self::empty();
			self::add($campaigns[$campaignId], $action, $row);
		}

		if (! $accountAvailable) {
			$account = array_fill_keys(self::KEYS, null);
		} elseif (! $confirmedAvailable) {
			$account['confirmed'] = null;
		}

		foreach ($campaigns as $id => $values) {
			if (! $confirmedAvailable) {
				$campaigns[$id]['confirmed'] = null;
			}
		}

		return [
			'actions' => $actions,
			'account' => $account,
			'campaigns' => $campaigns,
			'campaign_level_available' => $rows !== [] || $this->metrics->apiCoverage($projectId, 'conversions_by_campaign', $range) !== [],
			'confirmed_available' => $confirmedAvailable,
			'manual' => (bool) array_filter($actions, fn ($a) => $a['classification_source'] === 'manual'),
		];
	}

	/**
	 * Wiersze per kampania i działanie: dane z API (w oknach pokrycia) + dzienne dane z importów spoza tych okien.
	 */
	private function campaignRows(int $projectId, DateRange $range): array
	{
		$apiRanges = PeriodResolver::clipAndMerge($this->metrics->apiCoverage($projectId, 'conversions_by_campaign', $range), $range);
		$parts = [];
		$params = [$projectId];

		foreach ($apiRanges as [$from, $to]) {
			$parts[] = "(m.source = 'api' AND m.date_from BETWEEN ? AND ?)";
			array_push($params, $from, $to);
		}

		$importSql = "(m.source = 'import' AND m.date_from BETWEEN ? AND ?";
		array_push($params, $range->fromString(), $range->toString());

		foreach ($apiRanges as [$from, $to]) {
			$importSql .= ' AND m.date_from NOT BETWEEN ? AND ?';
			array_push($params, $from, $to);
		}

		$parts[] = $importSql . ')';

		return $this->db->select(
			sprintf(
				"SELECT m.campaign_scope, m.conversion_action_id, SUM(m.conversions) AS conversions, SUM(m.all_conversions) AS all_conversions
				FROM %s m WHERE m.project_id = ? AND m.campaign_scope > 0 AND m.granularity = 'day' AND (%s)
				GROUP BY m.campaign_scope, m.conversion_action_id",
				$this->db->table('conversion_metrics'),
				implode(' OR ', $parts)
			),
			$params
		);
	}

	private static function empty(): array
	{
		return array_fill_keys(self::KEYS, 0.0);
	}

	private static function add(array &$bucket, array $action, array $row): void
	{
		$google = (float) ($row['conversions'] ?? 0);
		$counted = (int) ($action['include_in_conversions'] ?? 1) === 1 || $action['include_in_conversions'] === null ? $google : (float) ($row['all_conversions'] ?? 0);
		$bucket['google'] += $google;

		if ($action['effective_goal']) {
			$bucket['goals'] += $counted;
		}

		$key = match ($action['effective_class']) {
			'lead' => 'leads',
			'confirmed_lead' => 'confirmed',
			'potential_lead' => 'potential',
			'sale' => 'sales',
			'micro' => 'micro',
			'other' => 'other',
			default => 'unclassified',
		};
		$bucket[$key] += $counted;

		if ($key === 'micro') {
			$bucket['google_micro'] += $google;
		}

		if ($key === 'confirmed') {
			$bucket['leads'] += $counted;
		}
	}
}
