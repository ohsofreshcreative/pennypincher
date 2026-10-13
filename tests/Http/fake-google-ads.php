<?php

/**
 * Atrapa Google OAuth / Google Ads API dla testów HTTP formularzy (serwer PHP uruchomiony lokalnie).
 *
 * Używać WYŁĄCZNIE lokalnie: run.sh dołącza ten plik jako tymczasową wtyczkę must-use lokalnej instalacji
 * (wp-content/mu-plugins/pp-http-fake.php, usuwaną po teście) i ustawia PP_FAKE_GOOGLE_ADS=1
 * oraz PP_FAKE_GOOGLE_ADS_STATE=<plik stanu>.
 * Bez tych dwóch warunków plik nic nie robi. Nie jest częścią paczki produkcyjnej (katalog tests/ jest wykluczony).
 *
 * Każde żądanie do Google (OAuth, searchStream, mutate) obsługuje GoogleAdsMock + FakeGoogleAdsAccount;
 * nieobsłużony adres kończy się błędem – nic nie wychodzi do sieci. Stan konta (atrapy) jest zapisywany w pliku,
 * żeby kolejne żądania HTTP widziały wcześniejsze odczyty.
 */

if (getenv('PP_FAKE_GOOGLE_ADS') !== '1' || ! getenv('PP_FAKE_GOOGLE_ADS_STATE')) {
	return;
}

add_filter('pre_http_request', static function ($preempt, $args, $url) {
	$host = (string) parse_url((string) $url, PHP_URL_HOST);

	// Tylko ruch do Google; resztę (np. NBP, DataForSEO) blokujemy, żeby test nie wychodził do sieci.
	if (! in_array($host, ['oauth2.googleapis.com', 'googleads.googleapis.com', 'accounts.google.com'], true)) {
		return new WP_Error('pp_fake_blocked', 'Test HTTP: zablokowano żądanie do ' . $host);
	}

	static $mock = null;
	static $fake = null;
	$file = (string) getenv('PP_FAKE_GOOGLE_ADS_STATE');

	if ($mock === null) {
		require_once __DIR__ . '/../Support/GoogleAdsMock.php';
		require_once __DIR__ . '/../Support/FakeGoogleAdsAccount.php';
		$mock = (new Tests\Support\GoogleAdsMock())->withDefaultAccount();
		$fake = (new Tests\Support\FakeGoogleAdsAccount())->install($mock);
	}

	$state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

	if (is_array($state)) {
		$fake->import($state);
	}

	$response = $mock->respond((array) $args, (string) $url);
	file_put_contents($file, json_encode($fake->export()), LOCK_EX);

	return $response;
}, 1, 3);
