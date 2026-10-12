<?php

namespace App\PennyPincher\Infrastructure\Fx;

use RuntimeException;

/**
 * Publiczne, bezpłatne API Narodowego Banku Polskiego (tabela A – średnie kursy walut względem PLN).
 * https://api.nbp.pl/ – tylko HTTPS, tylko host api.nbp.pl. Brak kluczy i kosztów.
 */
class NbpClient
{
	public const HOST = 'api.nbp.pl';

	public const SOURCE = 'NBP tabela A';

	/**
	 * Ostatnie kursy średnie waluty (1 jednostka waluty = mid PLN).
	 *
	 * @return array<int, array{date: string, rate: float}>
	 * @throws RuntimeException
	 */
	public function lastRates(string $currency, int $count = 5): array
	{
		$code = strtolower(preg_replace('/[^a-z]/i', '', $currency));

		if (strlen($code) !== 3) {
			throw new RuntimeException('Nieprawidłowy kod waluty.');
		}

		$url = 'https://' . self::HOST . '/api/exchangerates/rates/a/' . $code . '/last/' . max(1, min(30, $count)) . '/?format=json';
		$response = wp_remote_get($url, ['timeout' => 15, 'redirection' => 0, 'sslverify' => true, 'headers' => ['Accept' => 'application/json'], 'user-agent' => 'PennyPincher/1.0 (+WordPress)']);

		if (is_wp_error($response)) {
			throw new RuntimeException('Brak połączenia z API NBP: ' . $response->get_error_message());
		}

		$status = (int) wp_remote_retrieve_response_code($response);

		if ($status === 404) {
			return []; // waluta spoza tabeli A albo brak notowań
		}

		if ($status !== 200) {
			throw new RuntimeException("API NBP zwróciło HTTP {$status}.");
		}

		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		$body = is_array($body) ? array_change_key_case($body, CASE_LOWER) : [];
		$rates = [];

		foreach ((array) ($body['rates'] ?? []) as $item) {
			$item = is_array($item) ? array_change_key_case($item, CASE_LOWER) : [];
			$date = (string) ($item['effectivedate'] ?? '');
			$mid = $item['mid'] ?? null;

			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && is_numeric($mid) && (float) $mid > 0) {
				$rates[] = ['date' => $date, 'rate' => (float) $mid];
			}
		}

		return $rates;
	}
}
