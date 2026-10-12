<?php

namespace App\PennyPincher\Http\Middleware;

use App\PennyPincher\Http\Responses;
use App\PennyPincher\Infrastructure\Database\Migrator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wspólne przygotowanie żądań /app/:
 * - zdejmuje „magic quotes” dodawane przez WordPress do $_GET/$_POST,
 * - uruchamia brakujące migracje schematu (bezpieczne: tylko addytywne, z blokadą),
 * - dodaje nagłówki bezpieczeństwa i wyłącza cache.
 */
class PrepareAppRequest
{
	public function __construct(private Migrator $migrator)
	{
	}

	public function handle(Request $request, Closure $next): Response
	{
		$request->query->replace(wp_unslash($request->query->all()));
		$request->request->replace(wp_unslash($request->request->all()));

		if (! $this->migrator->isCurrent()) {
			$this->migrator->migrate();
		}

		if (! defined('DONOTCACHEPAGE')) {
			define('DONOTCACHEPAGE', true);
		}

		$response = $next($request);

		foreach (Responses::securityHeaders() as $name => $value) {
			if (! $response->headers->has($name)) {
				$response->headers->set($name, $value);
			}
		}

		return $response;
	}
}
