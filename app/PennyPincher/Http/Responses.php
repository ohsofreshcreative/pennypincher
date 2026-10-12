<?php

namespace App\PennyPincher\Http;

use Illuminate\Http\Response;

final class Responses
{
	public static function error(int $status, string $title, string $message): Response
	{
		return response(
			view('app.pages.error', ['status' => $status, 'title' => $title, 'message' => $message, 'pageTitle' => $title]),
			$status
		)->withHeaders(self::securityHeaders());
	}

	/**
	 * @return array<string, string>
	 */
	public static function securityHeaders(): array
	{
		$headers = [
			'X-Frame-Options' => 'SAMEORIGIN',
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy' => 'same-origin',
			'Cache-Control' => 'no-store, private',
			'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
		];

		// HSTS tylko przez HTTPS – przez HTTP przeglądarki i tak go ignorują, a lokalnie nie chcemy go utrwalać.
		if (is_ssl()) {
			$headers['Strict-Transport-Security'] = 'max-age=31536000';
		}

		return $headers;
	}
}
