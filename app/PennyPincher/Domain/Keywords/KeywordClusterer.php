<?php

namespace App\PennyPincher\Domain\Keywords;

use App\PennyPincher\Domain\Analysis\Statistics;
use App\PennyPincher\Domain\Projects\BusinessContext;

/**
 * Grupowanie fraz (Keyword Opportunities) w klastry o podobnym znaczeniu i tej samej intencji.
 *
 * 1. Kosz intencji: zakupowa/nieokreślona razem, pozostałe osobno (informacyjna nie trafi do klastra zakupowego).
 * 2. Modyfikatory (cena, tanio, za darmo, lokalizacja) muszą być takie same.
 * 3. Podobieństwo pojęć (`PhraseSimilarity`) do frazy wiodącej klastra ≥ progu albo zawieranie się zbiorów pojęć.
 *
 * Wolumen klastra jest szacunkiem: podajemy maksimum (najpopularniejsza fraza) i sumę jako górną granicę – podobne
 * frazy mogą dotyczyć tych samych wyszukiwań, więc suma nie oznacza niezależnego ruchu.
 */
final class KeywordClusterer
{
	public const THRESHOLD = 0.6;

	public function __construct(private PhraseSimilarity $similarity = new LexicalSimilarity())
	{
	}

	/**
	 * @param array<int, array<string, mixed>> $rows frazy z polami: phrase, normalized, intent, score, confidence,
	 *        search_volume, cpc_micros, cpc_currency, cpc_project_micros, suggested_campaign_id, suggested_ad_group_id
	 * @return array{clusters: array<string, array<string, mixed>>, assignments: array<string, string>} assignments: normalized => cluster_key
	 */
	public function cluster(array $rows, BusinessContext $context): array
	{
		usort($rows, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ((int) ($b['search_volume'] ?? 0) <=> (int) ($a['search_volume'] ?? 0)) ?: strcmp($a['normalized'], $b['normalized']));

		$groups = [];

		foreach ($rows as $row) {
			$signature = $this->similarity->signature($row['phrase']);
			$bucket = in_array($row['intent'], ['commercial', 'unknown', null], true) ? 'purchase' : (string) $row['intent'];
			$best = null;
			$bestScore = 0.0;

			foreach ($groups as $index => $group) {
				if ($group['bucket'] !== $bucket || ! $this->similarity->compatible($group['signature'], $signature)) {
					continue;
				}

				$score = $this->similarity->similarity($group['signature'], $signature);
				$a = $group['signature']['core'] ?? [];
				$b = $signature['core'] ?? [];
				$nested = $a !== [] && $b !== [] && count(array_intersect($a, $b)) >= 2 && (array_diff($a, $b) === [] || array_diff($b, $a) === []);

				if (($score >= self::THRESHOLD || $nested) && $score > $bestScore) {
					$best = $index;
					$bestScore = max($score, 0.0001);
				}
			}

			if ($best === null) {
				$groups[] = ['bucket' => $bucket, 'signature' => $signature, 'members' => [$row]];
			} else {
				$groups[$best]['members'][] = $row;
			}
		}

		$clusters = [];
		$assignments = [];

		foreach ($groups as $group) {
			$cluster = $this->describe($group, $context);
			$clusters[$cluster['cluster_key']] = $cluster;

			foreach ($group['members'] as $member) {
				$assignments[$member['normalized']] = $cluster['cluster_key'];
			}
		}

		uasort($clusters, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($b['phrase_count'] <=> $a['phrase_count']));

		return ['clusters' => $clusters, 'assignments' => $assignments];
	}

	private function describe(array $group, BusinessContext $context): array
	{
		$members = $group['members'];
		$lead = $members[0];
		$signature = $group['signature'];
		$core = $signature['core'] ?? [];
		$modifiers = $signature['modifiers'] ?? [];
		$scores = array_map(fn ($m) => (int) $m['score'], $members);
		rsort($scores);
		$top = array_slice($scores, 0, 3);
		$volumes = array_values(array_filter(array_map(fn ($m) => $m['search_volume'] ?? null, $members), fn ($v) => $v !== null));
		$projectCpc = array_values(array_filter(array_map(fn ($m) => $m['cpc_project_micros'] ?? null, $members), fn ($v) => $v !== null));
		$original = [];

		foreach ($members as $m) {
			if (($m['cpc_micros'] ?? null) !== null && ($m['cpc_currency'] ?? null) !== null) {
				$original[$m['cpc_currency']][] = (int) $m['cpc_micros'];
			}
		}

		$ranks = array_map(fn ($m) => Statistics::rank((string) $m['confidence']), $members);
		$rank = array_sum($ranks) / max(1, count($ranks));

		return [
			'cluster_key' => sha1($group['bucket'] . '|' . implode(',', $modifiers) . '|' . implode(',', $core ?: [$lead['normalized']])),
			'name' => mb_strtoupper(mb_substr($lead['phrase'], 0, 1)) . mb_substr($lead['phrase'], 1),
			'topic' => Concepts::label([...$core, ...$modifiers]),
			'service' => $this->service([...$core], $context),
			'intent' => $group['bucket'] === 'purchase' ? ($this->dominantIntent($members) ?? 'unknown') : $group['bucket'],
			'phrase_count' => count($members),
			'members' => array_map(fn ($m) => $m['normalized'], $members),
			'volume_max' => $volumes === [] ? null : max($volumes),
			'volume_sum' => $volumes === [] ? null : array_sum($volumes),
			'volume_known' => count($volumes),
			'cpc_project_micros' => $projectCpc === [] ? null : self::median($projectCpc),
			'cpc_original' => array_map(fn ($values) => self::median($values), $original),
			'score' => (int) round(0.5 * $top[0] + 0.5 * (array_sum($top) / count($top))),
			'confidence' => $rank >= 2.5 ? Statistics::HIGH : ($rank >= 1.6 ? Statistics::MEDIUM : Statistics::LOW),
			'suggested_campaign_id' => self::mode(array_map(fn ($m) => $m['suggested_campaign_id'] ?? null, $members)),
			'suggested_ad_group_id' => self::mode(array_map(fn ($m) => $m['suggested_ad_group_id'] ?? null, $members)),
		];
	}

	/**
	 * Usługa z kontekstu biznesowego najlepiej pasująca do pojęć klastra (najpierw usługi priorytetowe).
	 *
	 * @param array<int, string> $core
	 */
	private function service(array $core, BusinessContext $context): ?string
	{
		$core = array_values(array_diff($core, ['service', 'company']));

		if ($core === []) {
			return null;
		}

		$best = null;
		$bestScore = 0.0;
		$lines = [...$context->priorityTexts(), ...BusinessContext::lines($context->get('products_services'))];

		foreach ($lines as $line) {
			$concepts = array_values(array_diff(Concepts::of($line), Concepts::MODIFIERS, ['service', 'company']));
			$union = array_unique([...$core, ...$concepts]);

			if ($union === []) {
				continue;
			}

			$score = count(array_intersect($core, $concepts)) / count($union);

			if ($score > $bestScore) {
				$best = $line;
				$bestScore = $score;
			}
		}

		return $bestScore >= 0.34 ? $best : null;
	}

	private function dominantIntent(array $members): ?string
	{
		$counts = array_count_values(array_map(fn ($m) => (string) $m['intent'], $members));
		arsort($counts);

		return array_key_first($counts);
	}

	private static function median(array $values): int
	{
		sort($values);
		$n = count($values);

		return $n % 2 ? (int) $values[intdiv($n, 2)] : (int) round(($values[$n / 2 - 1] + $values[$n / 2]) / 2);
	}

	private static function mode(array $values): ?int
	{
		$values = array_filter($values, fn ($v) => $v !== null);

		if ($values === []) {
			return null;
		}

		$counts = array_count_values(array_map('intval', $values));
		arsort($counts);

		return (int) array_key_first($counts);
	}
}
