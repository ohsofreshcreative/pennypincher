<?php

namespace App\PennyPincher\Http\Middleware;

use App\PennyPincher\Http\Responses;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ochrona CSRF formularzy aplikacji przez nonce WordPressa (pole `_pp_nonce`).
 */
class VerifyNonce
{
	public const ACTION = 'pennypincher_app';

	public const FIELD = '_pp_nonce';

	public function handle(Request $request, Closure $next): Response
	{
		if (! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
			$nonce = (string) $request->input(self::FIELD, '');

			if (! wp_verify_nonce($nonce, self::ACTION)) {
				return Responses::error(419, 'Formularz wygasł', 'Odśwież stronę i spróbuj ponownie. Formularz był otwarty zbyt długo albo sesja się zmieniła.');
			}
		}

		return $next($request);
	}
}
