<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\Assistant\AiChatService;
use App\PennyPincher\Support\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * „Zapytaj Claude” (pp2_ai_chat): POST z pytaniem, odpowiedź JSON. Nonce sprawdza middleware, dostęp do projektu
 * AccessService (404 dla cudzych). Bez flagi – 404.
 */
class AiChatController extends Controller
{
	public function __construct(AccessService $access, private AiChatService $chat)
	{
		parent::__construct($access);
	}

	public function ask(int $project, Request $request): JsonResponse
	{
		$project = $this->access->project($project);

		if (! Feature::enabled(Feature::AI_CHAT)) {
			abort(404);
		}

		$history = json_decode((string) $request->input('history', '[]'), true);

		try {
			$answer = $this->chat->ask($project, (string) $request->input('question', ''), is_array($history) ? $history : [], (string) $request->input('section', '') ?: null, (string) $request->input('path', ''), array_values((array) $request->input('images', [])));
		} catch (RuntimeException $e) {
			return response()->json(['error' => $e->getMessage()], 422);
		}

		return response()->json(['answer' => $answer]);
	}
}
