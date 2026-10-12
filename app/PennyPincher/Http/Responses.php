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
		return [
			'X-Frame-Options' => 'SAMEORIGIN',
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy' => 'same-origin',
			'Cache-Control' => 'no-store, private',
		];
	}
}
