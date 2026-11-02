<?php

namespace App\PennyPincher\Services\Conversions;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Services\Analysis\ConversionBreakdown;
use App\PennyPincher\Support\AppUrl;
use App\PennyPincher\Support\Feature;

/**
 * Centrum konwersji (pp2_conversions): dane zakładek Wyniki i Historia. Liczby wyłącznie z istniejących źródeł
 * (ConversionBreakdown, MetricsRepository, Change Center) – bez nowych przeliczeń, bez sumowania konta z kampaniami.
 */
class ConversionCenterService
{
	public const TABS = [
		'results' => 'Wyniki',
		'events' => 'Cele i zdarzenia',
		'diagnostics' => 'Diagnostyka',
		'history' => 'Historia',
	];

	/** Typy operacji Change Center dotyczące konwersji i celów. */
	public const OPERATION_TYPES = ['conversion_action.create', 'conversion_action.update', 'customer_conversion_goal.update', 'campaign_conversion_goal.update'];

	public function __construct(
		private Db $db,
		private ConversionBreakdown $breakdown,
		private MetricsRepository $metrics,
	) {
	}

	/**
	 * Adres powrotu po POST z formularzy konwersji: zakładka Centrum (pole „return” = center:{zakładka}, tylko z flagą)
	 * albo dotychczasowa strona modułu.
	 */
	public static function back(int $projectId, string $fallbackPath): string
	{
		$return = (string) request()->input('return', '');

		if (Feature::enabled(Feature::CONVERSIONS) && preg_match('/^center:([a-z]+)$/', $return, $m) && isset(self::TABS[$m[1]])) {
			return AppUrl::project($projectId, 'conversions/center', ['tab' => $m[1]]);
		}

		return AppUrl::project($projectId, $fallbackPath);
	}

	public static function tab(?string $tab): string
	{
		return isset(self::TABS[$tab]) ? $tab : 'results';
	}

	/**
	 * Wyniki dla okresu: konto (Google Ads osobno od klas Penny Pincher), kampanie osobno, pokrycie i źródło danych.
	 */
	public function results(Project $project, AnalysisPeriod $period): array
	{
		$breakdown = $this->breakdown->forPeriod($project->id, $period->range);
		$names = [];

		if ($breakdown['campaigns'] !== []) {
			$ids = array_map('intval', array_keys($breakdown['campaigns']));
			foreach ($this->db->select('SELECT id, name FROM ' . $this->db->table('campaigns') . ' WHERE project_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [$project->id, ...$ids]) as $row) {
				$names[(int) $row['id']] = (string) $row['name'];
			}
		}

		$campaigns = [];

		foreach ($breakdown['campaigns'] as $id => $values) {
			$campaigns[] = ['id' => (int) $id, 'name' => $names[(int) $id] ?? 'Kampania #' . (int) $id] + $values;
		}

		usort($campaigns, fn ($a, $b) => ($b['google'] ?? -1) <=> ($a['google'] ?? -1) ?: strcmp($a['name'], $b['name']));

		return [
			'breakdown' => $breakdown,
			'resolved' => $this->metrics->resolve('conversion_metrics', $project->id, $period->range),
			'campaigns' => $campaigns,
		];
	}

	/**
	 * Historia: zestawy Change Center z operacjami na konwersjach/celach oraz ostatnie ręczne klasyfikacje.
	 */
	public function history(Project $project): array
	{
		$types = self::OPERATION_TYPES;
		$sets = $this->db->select(
			'SELECT s.id, s.title, s.status, s.created_at, s.updated_at, s.approved_at, s.deploy_finished_at, s.verified_at, COUNT(o.id) AS operations, GROUP_CONCAT(DISTINCT o.type) AS types
			FROM ' . $this->db->table('change_sets') . ' s INNER JOIN ' . $this->db->table('change_operations') . ' o ON o.change_set_id = s.id AND o.project_id = s.project_id
			WHERE s.project_id = ? AND o.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')
			GROUP BY s.id ORDER BY s.id DESC LIMIT 100',
			[$project->id, ...$types]
		);

		$classified = $this->db->select(
			sprintf('SELECT a.id, a.name, a.business_class, a.business_goal, a.classified_at, u.display_name AS user_name FROM %s a LEFT JOIN %s u ON u.ID = a.classified_by
				WHERE a.project_id = ? AND a.classified_at IS NOT NULL ORDER BY a.classified_at DESC, a.id DESC LIMIT 100', $this->db->table('conversion_actions'), $this->db->wpdb()->users),
			[$project->id]
		);

		return ['sets' => $sets, 'classified' => $classified];
	}
}
