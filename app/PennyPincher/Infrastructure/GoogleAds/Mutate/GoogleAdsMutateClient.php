<?php

namespace App\PennyPincher\Infrastructure\GoogleAds\Mutate;

use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsClient;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleHttp;

/**
 * Jedyne miejsce w aplikacji wysyłające operacje zapisu do Google Ads API:
 * POST https://googleads.googleapis.com/{wersja}/customers/{id}/googleAds:mutate
 * (MutateGoogleAdsRequest: mutate_operations, partial_failure, validate_only, response_content_type).
 *
 * Zabezpieczenia niezależne od wyższych warstw (obrona w głąb):
 * - wykonanie (validate_only = false) tylko przy PP_GOOGLE_ADS_WRITE_MODE = write_enabled,
 * - walidacja w Google (validate_only = true) tylko przy zapisie albo jawnie włączonej PP_GOOGLE_ADS_API_VALIDATION,
 * - brak odpowiedzi / błąd serwera po wysłaniu wykonania = wynik NIEZNANY (AmbiguousMutateException) – nigdy
 *   nie ponawiamy automatycznie, bo Google mógł zmianę przyjąć,
 * - komunikaty bez tokenów i bez pełnych odpowiedzi API.
 */
class GoogleAdsMutateClient
{
	public function __construct(private GoogleAdsConfig $config, private GoogleHttp $http, private GoogleAdsClient $client)
	{
	}

	/**
	 * @param array<int, array<string, mixed>> $operations obiekty MutateOperation (REST JSON)
	 * @throws WriteBlockedException gdy tryb nie pozwala na żądanie
	 * @throws AmbiguousMutateException gdy nie wiadomo, czy Google przyjął wykonanie
	 * @throws GoogleAdsApiException przy pewnym odrzuceniu całego żądania (autoryzacja, uprawnienia, limit)
	 */
	public function mutate(string $accessToken, string $customerId, ?string $loginCustomerId, array $operations, bool $validateOnly, bool $partialFailure): MutateResult
	{
		$mode = $this->config->writeMode();

		if (! $validateOnly && ! $mode->canWrite()) {
			throw new WriteBlockedException('Zapis do Google Ads jest wyłączony w konfiguracji serwera (tryb: ' . $mode->label() . ').');
		}

		if ($validateOnly && ! $mode->canWrite() && ! ($mode->canPrepare() && $this->config->apiValidationEnabled())) {
			throw new WriteBlockedException('Walidacja przez Google Ads API jest wyłączona (PP_GOOGLE_ADS_API_VALIDATION).');
		}

		$customerId = (string) preg_replace('/\D/', '', $customerId);

		if ($customerId === '' || $operations === []) {
			throw new \InvalidArgumentException('Brak konta albo operacji do wysłania.');
		}

		$url = sprintf('%s/%s/customers/%s/googleAds:mutate', GoogleAdsConfig::API_HOST, $this->config->apiVersion(), $customerId);
		$body = wp_json_encode([
			'mutateOperations' => array_values($operations),
			'partialFailure' => $partialFailure,
			'validateOnly' => $validateOnly,
			'responseContentType' => 'RESOURCE_NAME_ONLY',
		]);

		try {
			$response = $this->http->request('POST', $url, $this->client->headers($accessToken, $loginCustomerId) + ['Content-Type' => 'application/json'], $body, 60);
		} catch (GoogleAdsApiException $e) {
			// Brak odpowiedzi (timeout, zerwane połączenie): przy wykonaniu nie wiemy, czy zmiana została zapisana.
			if (! $validateOnly && $e->kind === GoogleAdsApiException::TRANSIENT) {
				throw new AmbiguousMutateException('Brak odpowiedzi Google Ads po wysłaniu zmian (' . mb_substr($e->getMessage(), 0, 200) . '). Stan konta trzeba sprawdzić przed ponowieniem.');
			}

			throw $e;
		}

		$requestId = $response['headers']['request-id'] ?? null;

		if ($response['status'] === 200 && is_array($response['body'])) {
			return $this->parseSuccess($response['body'], count($operations), $requestId, $validateOnly);
		}

		$error = GoogleHttp::errorFromResponse($response, 'Zmiana Google Ads');
		$apiStatus = is_array($response['body']) ? (string) ($response['body']['error']['status'] ?? '') : '';

		// Błąd serwera Google po wysłaniu wykonania – wynik nieznany (np. INTERNAL, DEADLINE_EXCEEDED, UNAVAILABLE).
		if (! $validateOnly && ($response['status'] >= 500 || in_array($apiStatus, ['INTERNAL', 'DEADLINE_EXCEEDED', 'UNAVAILABLE', 'ABORTED', 'UNKNOWN'], true))) {
			throw new AmbiguousMutateException('Google Ads zwrócił błąd serwera po wysłaniu zmian (HTTP ' . $response['status'] . ($error->requestId ? ', request ID: ' . $error->requestId : '') . '). Stan konta trzeba sprawdzić przed ponowieniem.', $error->requestId);
		}

		// Autoryzacja, uprawnienia, limit zapytań – żądanie odrzucone w całości, nic nie zapisano.
		if (in_array($error->kind, [GoogleAdsApiException::AUTH, GoogleAdsApiException::PERMISSION, GoogleAdsApiException::QUOTA, GoogleAdsApiException::CONFIG], true)) {
			throw $error;
		}

		// Błędy walidacji (400): całe żądanie odrzucone; błędy przypisane do operacji wg ścieżki mutate_operations[i].
		$failures = self::failures(is_array($response['body']) ? ($response['body']['error'] ?? []) : []);
		$result = new MutateResult($validateOnly, false, $requestId ?? $error->requestId);

		for ($i = 0; $i < count($operations); $i++) {
			$result->errors[$i] = $failures[$i] ?? ['code' => $failures[-1]['code'] ?? 'REQUEST_REJECTED', 'message' => $failures[-1]['message'] ?? 'Żądanie odrzucone przez Google Ads (inna operacja w tym samym atomowym żądaniu była nieprawidłowa).'];
		}

		$result->requestError = mb_substr($error->getMessage(), 0, 500);

		return $result;
	}

	private function parseSuccess(array $body, int $count, ?string $requestId, bool $validateOnly): MutateResult
	{
		$result = new MutateResult($validateOnly, true, $requestId);
		$failures = isset($body['partialFailureError']) ? self::failures((array) $body['partialFailureError']) : [];

		foreach (array_values((array) ($body['mutateOperationResponses'] ?? [])) as $i => $response) {
			$name = null;

			foreach ((array) $response as $value) {
				if (is_array($value) && ! empty($value['resourceName'])) {
					$name = (string) $value['resourceName'];
				}
			}

			if ($name !== null) {
				$result->resourceNames[$i] = $name;
			}
		}

		for ($i = 0; $i < $count; $i++) {
			if (isset($failures[$i])) {
				$result->errors[$i] = $failures[$i];
				unset($result->resourceNames[$i]);
			} elseif (! $validateOnly && ! isset($result->resourceNames[$i])) {
				$result->errors[$i] = ['code' => 'NO_RESULT', 'message' => 'Google Ads nie zwrócił wyniku tej operacji.'];
			}
		}

		return $result;
	}

	/**
	 * Błędy GoogleAdsFailure → [indeks operacji => [code, message]] (indeks -1 = błąd bez wskazanej operacji).
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	public static function failures(array $status): array
	{
		$out = [];

		foreach ((array) ($status['details'] ?? []) as $detail) {
			foreach ((array) ($detail['errors'] ?? []) as $error) {
				$index = -1;

				foreach ((array) ($error['location']['fieldPathElements'] ?? []) as $element) {
					if (($element['fieldName'] ?? '') === 'mutate_operations' && isset($element['index'])) {
						$index = (int) $element['index'];
						break;
					}
				}

				$code = is_array($error['errorCode'] ?? null) && $error['errorCode'] !== [] ? implode('.', array_map(fn ($k, $v) => "{$k}:{$v}", array_keys($error['errorCode']), $error['errorCode'])) : 'UNKNOWN';
				$message = mb_substr((string) ($error['message'] ?? ''), 0, 500);

				if (! isset($out[$index])) {
					$out[$index] = ['code' => mb_substr($code, 0, 100), 'message' => $message];
				} elseif (mb_strlen($out[$index]['message']) < 800) {
					$out[$index]['message'] .= ' | ' . $message;
				}
			}
		}

		if ($out === [] && ! empty($status['message'])) {
			$out[-1] = ['code' => (string) ($status['status'] ?? 'ERROR'), 'message' => mb_substr((string) $status['message'], 0, 500)];
		}

		return $out;
	}
}
