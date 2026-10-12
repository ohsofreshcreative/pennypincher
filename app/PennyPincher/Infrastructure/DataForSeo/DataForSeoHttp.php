<?php

namespace App\PennyPincher\Infrastructure\DataForSeo;

/**
 * Wywołania oficjalnego REST API DataForSEO v3 (Basic Auth) przez WordPress HTTP API.
 * W testach odpowiedzi są podstawiane filtrem `pre_http_request` – testy nie wykonują płatnych zapytań.
 *
 * Dane logowania trafiają wyłącznie do nagłówka Authorization żądań na hosty DataForSEO.
 */
class DataForSeoHttp
{
	/** Zalecany przez DataForSEO limit czasu odpowiedzi klienta (sekundy). */
	public const TIMEOUT = 120;

	public function __construct(private DataForSeoConfig $config)
	{
	}

	/**
	 * @param array<int, array<string, mixed>>|null $tasks zadania (dla endpointów live: dokładnie jedno)
	 * @return array<string, mixed> zdekodowana odpowiedź (koperta z `tasks`)
	 * @throws DataForSeoException
	 */
	public function request(string $method, string $path, ?array $tasks = null): array
	{
		if (! $this->config->isConfigured()) {
			throw DataForSeoException::of(DataForSeoException::CONFIG);
		}

		$url = $this->config->baseUrl() . ltrim($path, '/');

		if (! in_array((string) wp_parse_url($url, PHP_URL_HOST), DataForSeoConfig::HOSTS, true) || wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || str_contains($path, '..')) {
			throw DataForSeoException::of(DataForSeoException::CONFIG, null, null, 'niedozwolony adres');
		}

		$response = wp_remote_request($url, [
			'method' => $method,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode($this->config->login() . ':' . $this->config->password()),
				'Content-Type' => 'application/json',
			],
			'body' => $tasks !== null ? wp_json_encode($tasks) : null,
			'timeout' => self::TIMEOUT,
			'redirection' => 0,
			'sslverify' => true,
			'user-agent' => 'PennyPincher/1.0 (+WordPress)',
		]);

		if (is_wp_error($response)) {
			// Komunikat WP_Error nie zawiera nagłówków – można go bezpiecznie pokazać (bez adresu z danymi).
			throw DataForSeoException::of(DataForSeoException::TRANSIENT, null, null, $response->get_error_message());
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode((string) wp_remote_retrieve_body($response), true);

		return self::check($status, is_array($body) ? $body : null);
	}

	/**
	 * Klasyfikacja odpowiedzi: kod HTTP, kod koperty (status_code) i kod pierwszego zadania.
	 *
	 * @throws DataForSeoException
	 */
	public static function check(int $httpStatus, ?array $body): array
	{
		$envelopeCode = isset($body['status_code']) ? (int) $body['status_code'] : null;
		$task = $body['tasks'][0] ?? null;
		$taskCode = is_array($task) && isset($task['status_code']) ? (int) $task['status_code'] : null;

		foreach ([$envelopeCode, $taskCode] as $code) {
			if ($code !== null && $code !== 20000) {
				$message = (string) ($code === $envelopeCode ? ($body['status_message'] ?? '') : ($task['status_message'] ?? ''));

				throw DataForSeoException::of(self::kind($code, $httpStatus), $code, $httpStatus, $message);
			}
		}

		if ($httpStatus >= 400 || $body === null) {
			throw DataForSeoException::of(self::kind(null, $httpStatus), null, $httpStatus);
		}

		if (! isset($body['tasks']) || ! is_array($body['tasks'])) {
			throw DataForSeoException::of(DataForSeoException::RESPONSE, $envelopeCode, $httpStatus, 'brak listy zadań');
		}

		return $body;
	}

	public static function kind(?int $code, int $httpStatus): string
	{
		return match (true) {
			$code === 40100, $code === 40101, $httpStatus === 401 => DataForSeoException::AUTH,
			$code === 40200, $code === 40210, $httpStatus === 402 => DataForSeoException::FUNDS,
			$code === 40203 => DataForSeoException::COST_LIMIT,
			$code === 40202, $code === 40209, $httpStatus === 429 => DataForSeoException::RATE,
			$code !== null && $code >= 50000, $httpStatus >= 500 => DataForSeoException::TRANSIENT,
			$code !== null && $code >= 40000, $httpStatus >= 400 => DataForSeoException::INVALID,
			default => DataForSeoException::RESPONSE,
		};
	}
}
