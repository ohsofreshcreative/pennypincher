<?php

namespace App\PennyPincher\Domain\Metrics;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Zamknięty zakres dat [from, to] (włącznie z oboma końcami), bez godzin.
 */
final class DateRange
{
	public const PRESETS = [
		'all' => 'Wszystkie dane',
		'last_7' => 'Ostatnie 7 dni',
		'last_30' => 'Ostatnie 30 dni',
		'last_90' => 'Ostatnie 90 dni',
		'this_month' => 'Ten miesiąc',
		'last_month' => 'Poprzedni miesiąc',
		'custom' => 'Własny zakres',
	];

	public readonly DateTimeImmutable $from;

	public readonly DateTimeImmutable $to;

	public function __construct(DateTimeImmutable|string $from, DateTimeImmutable|string $to, public readonly string $preset = 'custom')
	{
		$from = self::date($from);
		$to = self::date($to);

		if ($from > $to) {
			[$from, $to] = [$to, $from];
		}

		$this->from = $from;
		$this->to = $to;
	}

	public static function fromPreset(string $preset, DateTimeZone $timezone, ?DateTimeImmutable $today = null): self
	{
		$today = ($today ?? new DateTimeImmutable('now', $timezone))->setTime(0, 0);

		return match ($preset) {
			'last_7' => new self($today->modify('-7 days'), $today->modify('-1 day'), $preset),
			'last_30' => new self($today->modify('-30 days'), $today->modify('-1 day'), $preset),
			'last_90' => new self($today->modify('-90 days'), $today->modify('-1 day'), $preset),
			'this_month' => new self($today->modify('first day of this month'), $today, $preset),
			'last_month' => new self($today->modify('first day of last month'), $today->modify('last day of last month'), $preset),
			default => throw new InvalidArgumentException("Nieznany zakres: {$preset}"),
		};
	}

	public function days(): int
	{
		return (int) $this->from->diff($this->to)->days + 1;
	}

	public function fromString(): string
	{
		return $this->from->format('Y-m-d');
	}

	public function toString(): string
	{
		return $this->to->format('Y-m-d');
	}

	public function contains(string $date): bool
	{
		return $date >= $this->fromString() && $date <= $this->toString();
	}

	public function label(): string
	{
		return $this->from->format('d.m.Y') . ' – ' . $this->to->format('d.m.Y');
	}

	public static function isValidDate(string $value): bool
	{
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

		return $date !== false && $date->format('Y-m-d') === $value;
	}

	private static function date(DateTimeImmutable|string $value): DateTimeImmutable
	{
		if ($value instanceof DateTimeImmutable) {
			return $value->setTime(0, 0);
		}

		if (! self::isValidDate($value)) {
			throw new InvalidArgumentException("Nieprawidłowa data: {$value}");
		}

		return DateTimeImmutable::createFromFormat('!Y-m-d', $value);
	}
}
