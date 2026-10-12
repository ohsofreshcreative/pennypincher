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
	public function __construct(private Db $db, private ChangeSetRepository $sets, private GoogleAdsConnectionRepository $connections, private WriteGuard $guard)
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
	 * Działania z Action Plan, które mają co najmniej jedną rekomendację z operacjami Google Ads.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, bool> id działania => czy da się przygotować zmianę
	 */
	public function deployableActionItems(Project $project, array $items): array
	{
		$ids = array_values(array_unique(array_merge([], ...array_map(fn ($item) => array_map('intval', (array) ($item['recommendation_ids'] ?? [])), $items))));
		$types = $ids === [] ? [] : array_column($this->db->select(
			'SELECT id, type FROM ' . $this->db->table('recommendations') . ' WHERE project_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
			[$project->id, ...$ids]
		), 'type', 'id');
		$out = [];

		foreach ($items as $item) {
			$out[(int) $item['id']] = array_filter((array) ($item['recommendation_ids'] ?? []), fn ($id) => in_array($types[(int) $id] ?? '', ChangeProposalService::DEPLOYABLE, true)) !== [];
		}

		return $out;
	}

	public function canPrepare(Project $project): bool
	{
		return Policy::allows($project->role, Ability::PrepareChanges)
			&& $this->guard->mode($this->connections->currentForProject($project->id))->canPrepare();
	}
}
