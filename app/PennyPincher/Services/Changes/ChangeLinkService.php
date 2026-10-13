<?php

namespace App\PennyPincher\Services\Changes;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\ChangeSetRepository;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Services\GoogleAds\WriteGuard;

/**
 * Dane dla przycisków „Przygotuj zmianę” w modułach analizy (rekomendacje, Budget Leaks, Action Plan, planer):
 * najnowszy zestaw zmian dla każdego źródła i to, czy użytkownik może przygotowywać zmiany.
 */
class ChangeLinkService
{
	public function __construct(private Db $db, private ChangeSetRepository $sets, private GoogleAdsConnectionRepository $connections, private WriteGuard $guard, private ChangeProposalService $proposals)
	{
	}

	/**
	 * @param array<int, int|string> $sourceIds
	 * @return array{sets: array<int, array<string, mixed>>, canPrepare: bool}
	 */
	public function forSources(Project $project, string $sourceType, array $sourceIds): array
	{
		return [
			'sets' => $this->sets->latestForSources($project->id, $sourceType, $sourceIds),
			'canPrepare' => $this->canPrepare($project),
		];
	}

	/**
	 * Powody braku operacji dla rekomendacji (null – można przygotować zmianę). Rekomendacje konwersji sprawdzane są
	 * wg ConversionFieldPolicy: gdy żadne działanie nie jest edytowalne przez API – działanie ręczne.
	 *
	 * @param array<int, array<string, mixed>> $recommendations
	 * @return array<int, ?string>
	 */
	public function recommendationBlockers(Project $project, array $recommendations): array
	{
		$out = [];

		foreach ($recommendations as $rec) {
			$out[(int) $rec['id']] = $this->proposals->recommendationBlocker($project, $rec);
		}

		return $out;
	}

	/**
	 * Działania z Action Plan: null – co najmniej jedna rekomendacja ma operacje Google Ads; inaczej powód.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, ?string> id działania => powód braku operacji
	 */
	public function actionItemBlockers(Project $project, array $items): array
	{
		$ids = array_values(array_unique(array_merge([], ...array_map(fn ($item) => array_map('intval', (array) ($item['recommendation_ids'] ?? [])), $items))));
		$recs = $ids === [] ? [] : array_column($this->db->select(
			'SELECT id, type, title FROM ' . $this->db->table('recommendations') . ' WHERE project_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
			[$project->id, ...$ids]
		), null, 'id');
		$cache = [];
		$out = [];

		foreach ($items as $item) {
			$reasons = [];
			$out[(int) $item['id']] = self::OUTSIDE_API;

			foreach ((array) ($item['recommendation_ids'] ?? []) as $id) {
				if (! isset($recs[(int) $id]) || ChangeProposalService::deployable($recs[(int) $id]) !== null) {
					continue;
				}

				$blocker = $cache[(int) $id] ??= ($this->proposals->recommendationBlocker($project, $recs[(int) $id]) ?? '');

				if ($blocker === '') {
					$out[(int) $item['id']] = null;
					continue 2;
				}

				$reasons[] = $blocker;
			}

			if ($reasons !== []) {
				$out[(int) $item['id']] = implode(' ', array_values(array_unique($reasons)));
			}
		}

		return $out;
	}

	public const OUTSIDE_API = 'To działanie wymaga pracy poza Google Ads API (np. strona, pomiar, decyzja) – bez operacji do wdrożenia.';

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, bool> id działania => czy da się przygotować zmianę
	 */
	public function deployableActionItems(Project $project, array $items): array
	{
		return array_map(fn ($reason) => $reason === null, $this->actionItemBlockers($project, $items));
	}

	public function canPrepare(Project $project): bool
	{
		return Policy::allows($project->role, Ability::PrepareChanges)
			&& $this->guard->mode($this->connections->currentForProject($project->id))->canPrepare();
	}
}
