<?php

namespace App\PennyPincher\Http\Middleware;

use App\PennyPincher\Http\Responses;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Support\AppUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wymaga zalogowanego użytkownika WordPressa z dostępem do aplikacji.
 */
class Authenticate
{
	public function __construct(private AccessService $access)
	{
	}

	public function handle(Request $request, Closure $next): Response
	{
		$userId = get_current_user_id();

		if ($userId === 0) {
			$target = $request->isMethod('GET') ? $request->fullUrl() : AppUrl::to();

			return redirect()->to(AppUrl::login($target));
		}

		if (! $this->access->canUseApp($userId)) {
			return Responses::error(403, 'Brak dostępu do aplikacji', 'Twoje konto nie należy jeszcze do żadnej organizacji. Poproś właściciela organizacji o dodanie Cię jako członka.');
		}

		return $next($request);
	}
}
