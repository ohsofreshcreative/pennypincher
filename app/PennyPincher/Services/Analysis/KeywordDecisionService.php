<?php

namespace App\PennyPincher\Services\Analysis;

use App\PennyPincher\Domain\Analysis\Text;
use App\PennyPincher\Domain\Keywords\KeywordStatus;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\AnalysisRepository;
use App\PennyPincher\Repositories\KeywordDecisionRepository;

/**
 * Decyzje użytkownika o frazach (akceptacja, odrzucenie, przywrócenie) – pojedynczo albo dla całego klastra.
 * Decyzja jest przypisana do frazy znormalizowanej w projekcie, więc przetrwa ponowną analizę, synchronizację
 * i ponowne pobranie DataForSEO; każda zmiana trafia do historii.
 */
class KeywordDecisionService
{
	public function __construct(private Db $db, private KeywordDecisionRepository $decisions, private AnalysisRepository $analyses)
	{
	}

	/**
	 * @param array<int, string> $phrases frazy (oryginalne – zostaną znormalizowane)
	 * @return int liczba zmienionych decyzji
	 */
	public function setStatus(Project $project, array $phrases, string $status, int $userId, ?string $note = null, array $extra = []): int
	{
		if (! in_array($status, [...KeywordStatus::MANUAL, KeywordStatus::IN_PLAN], true)) {
			throw new ValidationFailed(['status' => 'Nieprawidłowy status frazy.']);
		}

		$note = self::cleanNote($note);
		$changed = 0;

		$this->db->transaction(function () use ($project, $phrases, $status, $userId, $note, $extra, &$changed) {
			foreach (array_slice(array_unique($phrases), 0, 500) as $phrase) {
				$normalized = mb_substr(Text::normalize((string) $phrase), 0, 255);

				if ($normalized === '') {
					continue;
				}

				$existing = $this->decisions->find($project->id, $normalized);

				if ($existing !== null && $existing['status'] === $status && $note === null) {
					continue;
				}

				[$id, $from] = $this->decisions->setStatus($project->id, $normalized, (string) $phrase, $status, $userId, $extra + ($note !== null ? ['note' => $note] : []));
				$this->decisions->addEvent($id, $project->id, $from === null ? 'created' : 'status', $userId, $from, $status, $note);
				$changed++;
			}
		});

		return $changed;
	}

	/**
	 * Decyzja dla wszystkich fraz klastra z najnowszej analizy (np. „Zaakceptuj klaster”).
	 */
	public function setClusterStatus(Project $project, int $runId, string $clusterKey, string $status, int $userId): int
	{
		$cluster = $this->analyses->findCluster($runId, $project->id, $clusterKey);

		if ($cluster === null) {
			throw new ValidationFailed(['cluster' => 'Nie znaleziono klastra w tej analizie.']);
		}

		$phrases = array_map(fn ($row) => $row['phrase'], $this->analyses->opportunitiesByNormalized($runId, $project->id, $cluster['members']));

		return $this->setStatus($project, $phrases, $status, $userId, null, ['cluster_key' => $clusterKey]);
	}

	private static function cleanNote(?string $note): ?string
	{
		$note = trim(wp_strip_all_tags((string) $note));

		if (mb_strlen($note) > 1000) {
			throw new ValidationFailed(['note' => 'Notatka może mieć najwyżej 1000 znaków.']);
		}

		return $note === '' ? null : $note;
	}
}
