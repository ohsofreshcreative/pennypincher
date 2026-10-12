<?php

namespace App\PennyPincher\Domain\Analysis;

use App\PennyPincher\Domain\Metrics\DateRange;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Okres analizy (audyt, Budget Leaks, rekomendacje, okazje słów kluczowych).
 * Okresy kończą się wczoraj (dzisiejszy dzień nie jest zakończony) w strefie czasowej projektu.
 */
final class AnalysisPeriod
{
	public const PRESETS = [
		'last_30' => 'Ostatnie 30 dni',
		'last_90' => 'Ostatnie 90 dni',
		'last_180' => 'Ostatnie 6 miesięcy',
		'last_365' => 'Ostatnie 12 miesięcy',
		'custom' => 'Własny zakres',
	];

	public const DEFAULT = 'last_30';

	/** Najdłuższy własny zakres – ochrona wydajności zapytań. */
	public const MAX_DAYS = 731;

	public function __construct(public readonly DateRange $range, public readonly string $preset)
	{
	}

	public static function fromPreset(string $preset, DateTimeZone $timezone, ?DateTimeImmutable $today = null): self
	{
		$today = ($today ?? new DateTimeImmutable('now', $timezone))->setTime(0, 0);
		$days = match ($preset) {
			'last_90' => 90,
			'last_180' => 182,
			'last_365' => 365,
			default => 30,
		};
		$preset = array_key_exists($preset, self::PRESETS) && $preset !== 'custom' ? $preset : self::DEFAULT;

		return new self(new DateRange($today->modify("-{$days} days"), $today->modify('-1 day'), $preset), $preset);
	}

	/**
	 * Własny zakres: poprawne daty, nie w przyszłości, maksymalnie MAX_DAYS dni (dłuższy jest skracany od początku).
	 */
	public static function custom(string $from, string $to, DateTimeZone $timezone, ?DateTimeImmutable $today = null): ?self
	{
		if (! DateRange::isValidDate($from) || ! DateRange::isValidDate($to)) {
			return null;
		}

		$today = ($today ?? new DateTimeImmutable('now', $timezone))->setTime(0, 0);
		$range = new DateRange($from, $to, 'custom');
		$end = $range->to > $today ? $today : $range->to;
		$start = $range->from > $end ? $end : $range->from;

		if ((int) $start->diff($end)->days + 1 > self::MAX_DAYS) {
			$start = $end->modify('-' . (self::MAX_DAYS - 1) . ' days');
		}

		return new self(new DateRange($start, $end, 'custom'), 'custom');
	}

	/**
	 * Poprzedni okres tej samej długości (do porównań).
	 */
	public function previous(): DateRange
	{
		$days = $this->range->days();

		return new DateRange($this->range->from->modify("-{$days} days"), $this->range->from->modify('-1 day'), 'custom');
	}

	public function days(): int
	{
		return $this->range->days();
	}

	public function label(): string
	{
		return $this->preset === 'custom' ? $this->range->label() : self::PRESETS[$this->preset] . ' (' . $this->range->label() . ')';
	}

	/**
	 * Klucz okresu do porównywania wyników analiz (ten sam preset i te same daty).
	 */
	public function key(): string
	{
		return $this->preset . ':' . $this->range->fromString() . ':' . $this->range->toString();
	}
}
