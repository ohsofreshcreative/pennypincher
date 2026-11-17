<?php

namespace App\PennyPincher\Infrastructure\Ai;

use RuntimeException;

/**
 * Wywołanie Messages API Anthropic (Claude) przez WordPress HTTP API – tylko host api.anthropic.com, HTTPS.
 * Surowe HTTP zamiast SDK: projekt nie ma zależności Composera dla integracji, a testy podstawiają odpowiedzi
 * filtrem `pre_http_request` (bez prawdziwych wywołań). Klucz wyłącznie ze stałej/zmiennej środowiskowej
 * PP_ANTHROPIC_API_KEY, tylko w nagłówku – nigdy w logach, komunikatach ani odpowiedziach.
 */
class ClaudeClient
{
	public const URL = 'https://api.anthropic.com/v1/messages';

	public const MODEL = 'claude-opus-5-5';

	/** Ceny USD za 1 mln tokenów (wejście / wyjście) – do limitu miesięcznego. */
	public const PRICE_INPUT = 4.0;

	public const PRICE_OUTPUT = 20.0;

	public const TIMEOUT = 90;

	public function apiKey(): ?string
	{
		return self::value('PP_ANTHROPIC_API_KEY');
	}

	public function isConfigured(): bool
	{
		return $this->apiKey() !== null;
	}

	/** Miesięczny limit kosztu na organizację (USD); domyślnie 10. */
	public function monthlyLimit(): float
	{
		$value = self::value('PP_AI_MONTHLY_LIMIT_USD');

		return $value !== null && is_numeric($value) && (float) $value >= 0 ? (float) $value : 10.0;
	}

	/**
	 * @param array<int, array{role: string, content: mixed}> $messages
	 * @return array{text: string, cost: float}
	 * @throws RuntimeException komunikat bezpieczny do pokazania użytkownikowi
	 */
	public function send(string $system, array $messages): array
	{
		$key = $this->apiKey() ?? throw new RuntimeException('Czat nie jest skonfigurowany (brak klucza API na serwerze).');

		$response = wp_remote_post(self::URL, [
			'headers' => [
				'x-api-key' => $key,
				'anthropic-version' => '2023-06-01',
				'anthropic-beta' => 'server-side-fallback-2026-07-01',
				'content-type' => 'application/json',
			],
			'body' => wp_json_encode([
				'model' => self::MODEL,
				'max_tokens' => 4000,
				'output_config' => ['effort' => 'low'],
				'fallbacks' => 'default',
				'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
				'messages' => $messages,
			]),
			'timeout' => self::TIMEOUT,
			'redirection' => 0,
			'sslverify' => true,
		]);

		if (is_wp_error($response)) {
			throw new RuntimeException('Nie udało się połączyć z Claude. Spróbuj ponownie za chwilę.');
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode((string) wp_remote_retrieve_body($response), true);

		if ($status !== 200 || ! is_array($body)) {
			throw new RuntimeException(match (true) {
				$status === 401 || $status === 403 => 'Klucz API Claude jest nieprawidłowy.',
				$status === 429 => 'Za dużo zapytań do Claude – spróbuj za minutę.',
				$status >= 500 => 'Claude jest chwilowo niedostępny. Spróbuj ponownie za chwilę.',
				default => 'Claude odrzucił zapytanie (HTTP ' . $status . ').',
			});
		}

		$usage = (array) ($body['usage'] ?? []);
		$input = (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) * 1.25 + (int) ($usage['cache_read_input_tokens'] ?? 0) * 0.1;
		$cost = ($input * self::PRICE_INPUT + (int) ($usage['output_tokens'] ?? 0) * self::PRICE_OUTPUT) / 1_000_000;

		if (($body['stop_reason'] ?? null) === 'refusal') {
			return ['text' => 'Nie mogę odpowiedzieć na to pytanie. Spróbuj je sformułować inaczej.', 'cost' => $cost];
		}

		$text = '';
		foreach ((array) ($body['content'] ?? []) as $block) {
			if (($block['type'] ?? null) === 'text') {
				$text .= (string) $block['text'];
			}
		}

		return ['text' => trim($text) !== '' ? trim($text) : 'Brak odpowiedzi – spróbuj ponownie.', 'cost' => $cost];
	}

	private static function value(string $name): ?string
	{
		$value = defined($name) ? constant($name) : getenv($name);

		return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
	}
}
