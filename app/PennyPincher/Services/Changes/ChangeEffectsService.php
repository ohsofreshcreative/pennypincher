<?php

namespace App\PennyPincher\Services\Changes;

use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
use App\PennyPincher\Domain\GoogleAds\Changes\ResourceName;
use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use DateTimeImmutable;

/**
 * Monitoring efektów wdrożonych zmian: metryki kampanii objętych zmianą przed i po wdrożeniu (te same długości okien).
 *
 * To porównanie, nie dowód przyczynowości: na wynik wpływają też sezonowość, konkurencja, inne zmiany i opóźnione
 * konwersje. Przy zbyt krótkim okresie lub małej liczbie kliknięć pokazujemy brak wiarygodnego porównania.
 */
class ChangeEffectsService
{
	public const WINDOW_DAYS = 14;

	public const MIN_DAYS_AFTER = 7;

	public const MIN_CLICKS = 30;

	public function __construct(private Db $db, private ChangeSetRepository $sets, private MetricsRepository $metrics)
	{
	}

	/**
	 * @return array{available: bool, message: string, deployed_on: ?string, before: ?array, after: ?array, campaigns: array<int, string>, other_changes: array<int, array>, notes: array<int, string>}
	 */
	public function forSet(Project $project, array $set, ?DateTimeImmutable $today = null): array
	{
		$result = ['available' => false, 'message' => '', 'deployed_on' => null, 'before' => null, 'after' => null, 'campaigns' => [], 'other_changes' => [], 'notes' => []];

		if (! in_array($set['status'], ChangeStatus::DEPLOYED, true) || $set['deploy_finished_at'] === null) {
			$result['message'] = 'Efekty można ocenić dopiero po wdrożeniu.';

			return $result;
		}

		$tz = $project->timezone();
		$deployed = (new DateTimeImmutable($set['deploy_finished_at'] . ' UTC'))->setTimezone($tz)->setTime(0, 0);
		$yesterday = ($today ?? new DateTimeImmutable('now', $tz))->setTime(0, 0)->modify('-1 day');
		$result['deployed_on'] = $deployed->format('Y-m-d');
		$campaigns = $this->affectedCampaigns($project, $set);
		$result['campaigns'] = array_values($campaigns);
		$accountLevel = $campaigns === [];

		$afterFrom = $deployed->modify('+1 day');
		$afterTo = min($yesterday, $deployed->modify('+' . self::WINDOW_DAYS . ' days'));
		$daysAfter = $afterTo >= $afterFrom ? (int) $afterFrom->diff($afterTo)->days + 1 : 0;

		if ($daysAfter < self::MIN_DAYS_AFTER) {
			$result['message'] = sprintf('Za wcześnie na porównanie – minęło %d %s od wdrożenia (potrzeba co najmniej %d pełnych dni).', $daysAfter, $daysAfter === 1 ? 'dzień' : 'dni', self::MIN_DAYS_AFTER);

			return $result;
		}

		$before = new DateRange($deployed->modify('-' . $daysAfter . ' days'), $deployed->modify('-1 day'));
		$after = new DateRange($afterFrom, $afterTo);
		$sum = function (DateRange $range) use ($project, $campaigns, $accountLevel) {
			$resolved = $this->metrics->resolve('campaign_metrics', $project->id, $range);

			if ($resolved->isEmpty() || ! $resolved->isComplete()) {
				return null;
			}

			if ($accountLevel) {
				return $this->metrics->campaignTotals($project->id, $resolved);
			}

			$total = ['impressions' => 0, 'clicks' => 0, 'cost_micros' => 0, 'conversions' => 0.0];

			foreach (array_keys($campaigns) as $campaignId) {
				$row = $this->metrics->campaignTotals($project->id, $resolved, $campaignId);

				foreach ($total as $key => $value) {
					$total[$key] += $row[$key] ?? 0;
				}
			}

			return $total;
		};

		$result['before'] = ($sum($before) ?? []) + ['from' => $before->fromString(), 'to' => $before->toString()];
		$result['after'] = ($sum($after) ?? []) + ['from' => $after->fromString(), 'to' => $after->toString()];

		if (! isset($result['before']['clicks'], $result['after']['clicks'])) {
			$result['message'] = 'Brak pełnych danych w jednym z okresów – porównanie byłoby niewiarygodne. Poczekaj na synchronizację.';

			return $result;
		}

		if ((int) $result['before']['clicks'] < self::MIN_CLICKS || (int) $result['after']['clicks'] < self::MIN_CLICKS) {
			$result['message'] = sprintf('Za mało kliknięć do wiarygodnego porównania (przed: %d, po: %d; potrzeba min. %d w każdym okresie).', (int) $result['before']['clicks'], (int) $result['after']['clicks'], self::MIN_CLICKS);

			return $result;
		}

		$result['available'] = true;
		$result['message'] = sprintf('Porównanie %d dni przed i %d dni po wdrożeniu (%s).', $daysAfter, $daysAfter, $accountLevel ? 'całe konto' : 'kampanie objęte zmianą');
		$result['other_changes'] = $this->otherChanges($project, $set, $before->fromString(), $after->toString());
		$result['notes'] = [
			'Różnica nie oznacza, że całą zmianę wyników spowodowała ta operacja – wpływają na nie też sezonowość, konkurencja w aukcjach i inne zmiany na koncie.',
			'Konwersje mogą spływać z opóźnieniem (nawet kilka dni) – wynik „po” może jeszcze wzrosnąć.',
		];

		if ($result['other_changes'] !== []) {
			$result['notes'][] = 'W tym samym okresie wdrożono też inne zestawy zmian – ich efekty nakładają się.';
		}

		return $result;
	}

	/**
	 * Kampanie (lokalne id => nazwa) objęte operacjami zestawu.
	 *
	 * @return array<int, string>
	 */
	public function affectedCampaigns(Project $project, array $set): array
	{
		$campaignExternal = [];
		$adGroupExternal = [];

		foreach ($this->sets->operations((int) $set['id']) as $op) {
			if (! in_array($op['status'], ChangeStatus::OP_DONE, true)) {
				continue;
			}

			$resource = OperationCatalog::resource($op['type']);
			$name = (string) ($op['result_resource_name'] ?? $op['resource_name'] ?? '');

			switch ($resource) {
				case 'campaign':
					$campaignExternal[] = ResourceName::id($name);
					break;
				case 'campaign_criterion':
					$campaignExternal[] = (string) ResourceName::parentId($name);
					break;
				case 'campaign_budget':
					foreach ((array) ($op['before_state']['campaigns'] ?? []) as $campaign) {
						$campaignExternal[] = ResourceName::id((string) ($campaign['resource_name'] ?? ''));
					}
					break;
				case 'ad_group':
					$adGroupExternal[] = ResourceName::id($name);
					break;
				case 'ad_group_criterion':
				case 'ad_group_ad':
					$adGroupExternal[] = (string) ResourceName::parentId($name);
					break;
				case 'campaign_conversion_goal':
					$campaignExternal[] = explode('~', ResourceName::id($name))[0];
					break;
			}
		}

		$t = fn (string $table) => $this->db->table($table);
		$out = [];

		if ($campaignExternal !== []) {
			foreach ($this->db->select("SELECT id, name FROM {$t('campaigns')} WHERE project_id = ? AND external_id IN (" . implode(',', array_fill(0, count($campaignExternal), '?')) . ')', [$project->id, ...array_values(array_unique($campaignExternal))]) as $row) {
				$out[(int) $row['id']] = (string) $row['name'];
			}
		}

		if ($adGroupExternal !== []) {
			foreach ($this->db->select("SELECT c.id, c.name FROM {$t('ad_groups')} g INNER JOIN {$t('campaigns')} c ON c.id = g.campaign_id WHERE g.project_id = ? AND g.external_id IN (" . implode(',', array_fill(0, count($adGroupExternal), '?')) . ')', [$project->id, ...array_values(array_unique($adGroupExternal))]) as $row) {
				$out[(int) $row['id']] = (string) $row['name'];
			}
		}

		return $out;
	}

	/**
	 * Wdrożone zestawy zmian projektu z ostatnich dni – do sekcji „Efekty zmian” w Monitoringu.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent(Project $project, int $days = 60): array
	{
		return array_map([ChangeSetRepository::class, 'decodeSet'], $this->db->select(
			'SELECT * FROM ' . $this->db->table('change_sets') . " WHERE project_id = ? AND status IN ('applied', 'partially_applied') AND deploy_finished_at >= ? ORDER BY deploy_finished_at DESC LIMIT 20",
			[$project->id, gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)]
		));
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function otherChanges(Project $project, array $set, string $from, string $to): array
	{
		return $this->db->select(
			'SELECT id, title, deploy_finished_at FROM ' . $this->db->table('change_sets') . " WHERE project_id = ? AND id <> ? AND status IN ('applied', 'partially_applied') AND deploy_finished_at BETWEEN ? AND ? ORDER BY deploy_finished_at",
			[$project->id, (int) $set['id'], $from . ' 00:00:00', $to . ' 23:59:59']
		);
	}
}
