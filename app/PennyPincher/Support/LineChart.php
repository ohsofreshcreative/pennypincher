<?php

namespace App\PennyPincher\Support;

/**
 * Geometria prostego wykresu liniowego renderowanego jako SVG po stronie serwera.
 * Brakujące dni (null) przerywają linię – nie rysujemy zer, których nie ma w danych.
 */
final class LineChart
{
	public const WIDTH = 600;

	public const HEIGHT = 160;

	private const PAD_TOP = 12;

	private const PAD_BOTTOM = 4;

	/**
	 * @param array<int, array<string, mixed>> $series
	 * @param callable(mixed): string $formatValue
	 * @return array{segments: array<int, string>, area: array<int, string>, points: array<int, array{x: float, y: float|null, label: string, date: string}>, max: float, maxLabel: string, ticks: array<int, array{x: float, label: string}>, slot: float, empty: bool}
	 */
	public static function build(array $series, string $key, callable $formatValue): array
	{
		$values = array_map(fn ($row) => $row[$key] === null ? null : (float) $row[$key], $series);
		$known = array_filter($values, fn ($v) => $v !== null);
		$max = $known === [] ? 0.0 : max($known);
		$max = $max <= 0 ? 1.0 : $max * 1.1;
		$count = max(1, count($series));
		$slot = self::WIDTH / $count;
		$plotHeight = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
		$baseline = self::HEIGHT - self::PAD_BOTTOM;

		$points = [];
		$segments = [];
		$areas = [];
		$current = [];

		foreach ($series as $i => $row) {
			$x = round($slot * $i + $slot / 2, 2);
			$value = $values[$i];
			$y = $value === null ? null : round($baseline - ($value / $max) * $plotHeight, 2);

			$points[] = [
				'x' => $x,
				'y' => $y,
				'label' => Format::date($row['date']) . ': ' . ($value === null ? 'brak danych' : $formatValue($row[$key])),
				'date' => $row['date'],
			];

			if ($y === null) {
				self::flush($current, $segments, $areas, $baseline);
				continue;
			}

			$current[] = [$x, $y];
		}

		self::flush($current, $segments, $areas, $baseline);

		$ticks = [];
		$step = max(1, (int) ceil($count / 6));

		for ($i = 0; $i < $count; $i += $step) {
			$ticks[] = ['x' => $points[$i]['x'], 'label' => date('d.m', strtotime($series[$i]['date']))];
		}

		return [
			'segments' => $segments,
			'area' => $areas,
			'points' => $points,
			'max' => $max / 1.1,
			'maxLabel' => $known === [] ? '' : $formatValue($key === 'cost_micros' ? (int) round($max / 1.1) : $max / 1.1),
			'ticks' => $ticks,
			'slot' => $slot,
			'empty' => $known === [],
			'baseline' => $baseline,
			'top' => self::PAD_TOP,
		];
	}

	private static function flush(array &$current, array &$segments, array &$areas, float $baseline): void
	{
		if ($current === []) {
			return;
		}

		$line = 'M' . implode(' L', array_map(fn ($p) => $p[0] . ' ' . $p[1], $current));

		if (count($current) === 1) {
			// Pojedynczy punkt: krótka kreska, żeby był widoczny.
			$line = sprintf('M%s %s L%s %s', $current[0][0] - 2, $current[0][1], $current[0][0] + 2, $current[0][1]);
		}

		$segments[] = $line;
		$first = $current[0];
		$last = end($current);
		$areas[] = $line . sprintf(' L%s %s L%s %s Z', $last[0], $baseline, $first[0], $baseline);
		$current = [];
	}
}
