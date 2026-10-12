<?php

namespace App\PennyPincher\Domain\Audit\Rules;

use App\PennyPincher\Domain\Audit\AuditContext;
use App\PennyPincher\Domain\Audit\Category;
use App\PennyPincher\Domain\Audit\Dimension;
use App\PennyPincher\Domain\Audit\Severity;
use App\PennyPincher\Support\Format;

/**
 * Wspólna logika reguł trendów (PF001–PF003): porównanie wskaźnika kampanii między okresem audytu
 * a poprzednim okresem tej samej długości, tylko przy wystarczającej liczbie zdarzeń w obu okresach.
 */
abstract class PerformanceTrendRule extends BaseRule
{
	protected const CATEGORY = Category::PERFORMANCE;

	protected const DIMENSION = Dimension::Risk;

	/** Metryka bazowa wymagana w obu okresach i jej minimum (próba). */
	abstract protected function sample(): array;

	/** Wartość wskaźnika dla metryk okresu albo null, gdy nie da się jej policzyć. */
	abstract protected function value(array $metrics): ?float;

	/** Próg zmiany w % (dodatni = wzrost jest problemem, ujemny = spadek). */
	abstract protected function threshold(): float;

	abstract protected function format(float $value, string $currency): string;

	abstract protected function title(string $campaign, float $change): string;

	abstract protected function recommendation(): string;

	protected function skip(AuditContext $context): bool
	{
		return false;
	}

	public function evaluate(AuditContext $context): array
	{
		if ($this->skip($context)) {
			return [];
		}

		[$metric, $minimum] = $this->sample();
		$findings = [];

		foreach ($context->campaigns as $campaign) {
			$current = $campaign['current'] ?? [];
			$previous = $campaign['previous'] ?? [];

			if (($current[$metric] ?? null) === null || ($previous[$metric] ?? null) === null || $current[$metric] < $minimum || $previous[$metric] < $minimum) {
				continue;
			}

			$now = $this->value($current);
			$before = $this->value($previous);

			if ($now === null || $before === null || $before <= 0) {
				continue;
			}

			$change = self::change($before, $now);
			$threshold = $this->threshold();

			if ($change === null || ($threshold > 0 ? $change < $threshold : $change > $threshold)) {
				continue;
			}

			$findings[] = $this->finding(
				Severity::Warning,
				$this->title((string) $campaign['name'], $change),
				$this->description(),
				[
					['Kampania', (string) $campaign['name']],
					['Poprzedni okres (' . Format::period($context->previousRange->fromString(), $context->previousRange->toString()) . ')', $this->format($before, $context->currency)],
					['Okres audytu (' . Format::period($context->range->fromString(), $context->range->toString()) . ')', $this->format($now, $context->currency)],
					['Zmiana', ($change > 0 ? '+' : '') . self::percent($change)],
					['Próba (' . $this->sampleLabel() . ')', Format::number($previous[$metric]) . ' → ' . Format::number($current[$metric])],
				],
				$this->recommendation(),
				'campaign',
				(int) $campaign['id'],
			);
		}

		return $findings;
	}

	protected function sampleLabel(): string
	{
		return match ($this->sample()[0]) {
			'clicks' => 'kliknięcia',
			'impressions' => 'wyświetlenia',
			'conversions' => 'konwersje',
			default => $this->sample()[0],
		};
	}
}
