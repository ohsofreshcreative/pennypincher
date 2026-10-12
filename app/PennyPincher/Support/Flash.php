<?php

namespace App\PennyPincher\Support;

/**
 * Jednorazowe komunikaty po przekierowaniu (bez sesji PHP – w transiencie użytkownika).
 */
final class Flash
{
	public static function set(string $type, string $message): void
	{
		$userId = get_current_user_id();

		if ($userId > 0) {
			set_transient(self::key($userId), ['type' => $type, 'message' => $message], 5 * MINUTE_IN_SECONDS);
		}
	}

	/**
	 * @return array{type: string, message: string}|null
	 */
	public static function pull(): ?array
	{
		$userId = get_current_user_id();

		if ($userId === 0) {
			return null;
		}

		$flash = get_transient(self::key($userId));

		if ($flash !== false) {
			delete_transient(self::key($userId));
		}

		return is_array($flash) ? $flash : null;
	}

	private static function key(int $userId): string
	{
		return 'pp_flash_' . $userId;
	}
}
