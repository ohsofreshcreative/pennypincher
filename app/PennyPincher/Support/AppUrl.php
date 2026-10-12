<?php

namespace App\PennyPincher\Support;

/**
 * Adresy aplikacji. Zawsze ze slashem na końcu, zgodnie z konwencją WordPressa.
 */
final class AppUrl
{
	public const PREFIX = 'app';

	public static function to(string $path = '', array $query = []): string
	{
		$path = trim($path, '/');
		$url = home_url('/' . self::PREFIX . '/' . ($path !== '' ? $path . '/' : ''));
		$query = array_filter($query, fn ($value) => $value !== null && $value !== '');

		return $query === [] ? $url : add_query_arg(array_map('rawurlencode', $query), $url);
	}

	public static function project(int $projectId, string $path = '', array $query = []): string
	{
		return self::to('projects/' . $projectId . ($path !== '' ? '/' . trim($path, '/') : ''), $query);
	}

	public static function login(?string $redirect = null): string
	{
		return self::to('login', ['redirect_to' => $redirect]);
	}

	/**
	 * Przekierowanie tylko w obrębie aplikacji (ochrona przed open redirect).
	 */
	public static function safeRedirect(?string $target): string
	{
		$fallback = self::to();

		if (! $target) {
			return $fallback;
		}

		$target = wp_validate_redirect($target, $fallback);
		$path = (string) wp_parse_url($target, PHP_URL_PATH);
		$base = (string) wp_parse_url(self::to(), PHP_URL_PATH);

		return str_starts_with(trailingslashit($path), $base) ? $target : $fallback;
	}
}
