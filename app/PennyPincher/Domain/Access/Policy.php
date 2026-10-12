<?php

namespace App\PennyPincher\Domain\Access;

/**
 * Jedno miejsce decydujące o uprawnieniach na podstawie roli w organizacji.
 * Brak członkostwa = brak dostępu (także dla administratorów WordPressa).
 */
class Policy
{
	public static function allows(?Role $role, Ability $ability): bool
	{
		return $role !== null && $role->atLeast($ability->minimumRole());
	}
}
