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
			['PP_GOOGLE_ADS_WRITE_MODE (Change Center)', $config->writeMode()->value . ' – ' . $config->writeMode()->label()],
			['PP_GOOGLE_ADS_API_VALIDATION', $config->apiValidationEnabled() ? 'włączone (validateOnly, bez zapisu)' : 'wyłączone'],
			['PP_GOOGLE_ADS_WRITE_CUSTOMERS (allowlista zapisu)', $config->writeCustomerAllowlist() === [] ? 'pusta – zapis niemożliwy na żadnym koncie' : implode(', ', array_map([GoogleAdsConfig::class, 'formatCustomer'], $config->writeCustomerAllowlist()))],
			['PP_GOOGLE_ADS_BLOCKED_CUSTOMERS (blokady)', $config->blockedWriteCustomers() === [] ? 'brak' : implode(', ', array_map([GoogleAdsConfig::class, 'formatCustomer'], $config->blockedWriteCustomers()))],
			['PP_GOOGLE_ADS_ALLOW_SERVING (blokada emisji)', $config->servingAllowed() ? 'emisja DOZWOLONA' : 'blokada włączona – kampanie, grupy i reklamy tylko wstrzymane'],
			['Redirect URI (dodaj w Google Cloud)', $config->redirectUri()],
		];

		$this->table(['Ustawienie', 'Stan'], $rows);

		if (! str_starts_with($config->redirectUri(), 'https://')) {
			$this->warn('Redirect URI nie używa HTTPS – Google zaakceptuje go tylko dla localhost.');
		}

		if ($config->writeMode()->canWrite()) {
			$this->warn('Zapis do Google Ads jest dozwolony na tym serwerze (write_enabled) – tylko dla kont z allowlisty. Na produkcji przed świadomą decyzją używaj prepare_only.');
		}

		if (array_intersect($config->writeCustomerAllowlist(), $config->blockedWriteCustomers()) !== []) {
			$this->warn('Allowlista zawiera konto z listy blokad – takie konto pozostaje zablokowane.');
		}

		if ($config->servingAllowed()) {
			$this->warn('Blokada emisji reklam jest WYŁĄCZONA (PP_GOOGLE_ADS_ALLOW_SERVING) – Penny Pincher może włączać kampanie, grupy i reklamy.');
		}

		$ok = $config->isConfigured() && SecretBox::isConfigured();
		$ok ? $this->info('Konfiguracja kompletna. Połączenie zweryfikujesz, łącząc projekt w /app/.') : $this->error('Konfiguracja niekompletna – zobacz docs/GOOGLE_ADS.md.');

		return $ok ? self::SUCCESS : self::FAILURE;
	}
}
