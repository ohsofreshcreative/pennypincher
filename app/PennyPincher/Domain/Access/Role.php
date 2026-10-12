<?php

namespace App\PennyPincher\Domain\Access;

/**
 * Role użytkownika w organizacji.
 */
enum Role: string
{
	case Owner = 'owner';
	case Admin = 'admin';
	case Member = 'member';

	public function label(): string
	{
		return match ($this) {
			self::Owner => 'Właściciel',
			self::Admin => 'Administrator',
			self::Member => 'Członek',
		};
	}

	public function rank(): int
	{
		return match ($this) {
			self::Owner => 3,
			self::Admin => 2,
			self::Member => 1,
		};
	}

	public function atLeast(self $role): bool
	{
		return $this->rank() >= $role->rank();
	}

	/**
	 * @return array<string, string>
	 */
	public static function options(): array
	{
		$options = [];

		foreach (self::cases() as $case) {
			$options[$case->value] = $case->label();
		}

		return $options;
	}
}
