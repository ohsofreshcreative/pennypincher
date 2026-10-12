<?php

namespace App\PennyPincher\Domain\Leaks;

use App\PennyPincher\Domain\Analysis\Statistics;

/**
 * Potencjalny wyciek budżetu. Szacunek oszczędności to średni miesięczny koszt problemu w okresie
 * (nie gwarancja przyszłych oszczędności); liczony tylko przy pewności średniej lub wysokiej.
 */
final class Leak
{
	public const CATEGORIES = [
		'search_terms' => 'Wyszukiwane hasła',
		'keywords' => 'Słowa kluczowe',
		'campaigns' => 'Kampanie',
		'measurement' => 'Jakość pomiaru',
	];

	public const PRIORITIES = ['high' => 'Wysoki', 'medium' => 'Średni', 'low' => 'Niski'];

	/**
	 * @param array<int, array{0: string, 1: string}> $evidence
	 * @param array<int, array{0: int, 1: int}> $termIds hasła objęte problemem: id => [id kampanii, miesięczny koszt]
	 *        (do łączenia nakładających się oszczędności)
	 */
	public function __construct(
		public readonly string $ruleId,
		public readonly string $category,
		public readonly string $title,
		public readonly string $reason,
		public readonly string $action,
		public readonly string $confidence,
		public readonly int $importance,
		public readonly ?string $entityType = null,
		public readonly ?int $entityId = null,
		public readonly ?int $campaignId = null,
		public readonly ?int $adGroupId = null,
		public readonly ?string $subject = null,
		public readonly ?int $costMicros = null,
		public readonly ?int $clicks = null,
		public readonly ?float $conversions = null,
		public readonly ?float $valuableConversions = null,
		public readonly ?int $savingsMicros = null,
		public readonly array $evidence = [],
		public readonly array $termIds = [],
		public readonly ?int $impactOverride = null,
	) {
	}

	public function fingerprint(): string
	{
		return sha1(implode('|', [$this->ruleId, $this->entityType ?? '', $this->entityId ?? '', mb_strtolower((string) $this->subject)]));
	}

	/**
	 * Priorytet 0–100: wpływ (koszt lub oszczędność względem wydatków okresu, maks. 45) + pewność (maks. 35)
	 * + istotność biznesowa (maks. 20). Sam koszt nie wystarczy do wysokiego priorytetu.
	 */
	public function priorityScore(int $periodSpendMicros): int
	{
		$impact = $this->impactOverride ?? ($periodSpendMicros > 0 && $this->costMicros !== null
			? (int) min(45, round($this->costMicros / $periodSpendMicros * 100 * 1.5))
			: 0);
		$confidence = match ($this->confidence) {
			Statistics::HIGH => 35,
			Statistics::MEDIUM => 22,
			default => 8,
		};

		return (int) max(0, min(100, $impact + $confidence + max(0, min(20, $this->importance))));
	}

	public static function priority(int $score): string
	{
		return match (true) {
			$score >= 60 => 'high',
			$score >= 35 => 'medium',
			default => 'low',
		};
	}
}
