<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\PennyPincher\Infrastructure\Security\SecretBox;
use Illuminate\Console\Command;

/**
 * Sprawdza konfigurację integracji bez wyświetlania sekretów i bez wysyłania zapytań do Google.
 */
class GoogleAdsCheckCommand extends Command
{
	protected $signature = 'pp:google-ads:check';

	protected $description = 'Penny Pincher: sprawdza konfigurację Google Ads API (bez ujawniania sekretów)';

	public function handle(GoogleAdsConfig $config): int
	{
		$rows = [
			['PP_GOOGLE_ADS_CLIENT_ID', $config->clientId() ? 'ustawione' : 'BRAK'],
			['PP_GOOGLE_ADS_CLIENT_SECRET', $config->clientSecret() ? 'ustawione' : 'BRAK'],
			['PP_ENCRYPTION_KEY', SecretBox::isConfigured() ? 'ustawione (32 bajty)' : 'BRAK lub niepoprawny'],
			['PP_GOOGLE_ADS_DEVELOPER_TOKEN', $config->developerToken() ? 'ustawione (opcjonalne)' : 'nie ustawiono (opcjonalne)'],
			['PP_GOOGLE_ADS_LOGIN_CUSTOMER_ID', $config->defaultLoginCustomerId() ?? 'nie ustawiono (opcjonalne)'],
			['Wersja API', $config->apiVersion()],
			['Redirect URI (dodaj w Google Cloud)', $config->redirectUri()],
		];

		$this->table(['Ustawienie', 'Stan'], $rows);

		if (! str_starts_with($config->redirectUri(), 'https://')) {
			$this->warn('Redirect URI nie używa HTTPS – Google zaakceptuje go tylko dla localhost.');
		}

		$ok = $config->isConfigured() && SecretBox::isConfigured();
		$ok ? $this->info('Konfiguracja kompletna. Połączenie zweryfikujesz, łącząc projekt w /app/.') : $this->error('Konfiguracja niekompletna – zobacz docs/GOOGLE_ADS.md.');

		return $ok ? self::SUCCESS : self::FAILURE;
	}
}
