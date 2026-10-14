<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Infrastructure\Security\SecretBox;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Services\GoogleAds\ConnectionService;
use Tests\IntegrationTestCase;
use Tests\Support\GoogleAdsMock;

require_once dirname(__DIR__) . '/Support/GoogleAdsMock.php';

/**
 * Wspólna konfiguracja testów integracji Google Ads: konfiguracja w zmiennych środowiskowych
 * (tylko na czas testu), atrapa HTTP i pomocnicze tworzenie połączenia.
 */
abstract class GoogleAdsTestCase extends IntegrationTestCase
{
	protected const ENV = ['PP_GOOGLE_ADS_CLIENT_ID', 'PP_GOOGLE_ADS_CLIENT_SECRET', 'PP_ENCRYPTION_KEY', 'PP_ENCRYPTION_KEY_PREVIOUS', 'PP_GOOGLE_ADS_DEVELOPER_TOKEN', 'PP_GOOGLE_ADS_LOGIN_CUSTOMER_ID', 'PP_GOOGLE_ADS_WRITE_MODE', 'PP_GOOGLE_ADS_API_VALIDATION', 'PP_GOOGLE_ADS_WRITE_CUSTOMERS', 'PP_GOOGLE_ADS_BLOCKED_CUSTOMERS', 'PP_GOOGLE_ADS_ALLOW_SERVING'];

	protected GoogleAdsMock $mock;

	private array $previousEnv = [];

	public function setUp(): void
	{
		parent::setUp();

		foreach (self::ENV as $name) {
			if (defined($name)) {
				throw new \Tests\SkipTest("Stała {$name} jest zdefiniowana w wp-config.php – testy z atrapą wymagają konfiguracji przez zmienne środowiskowe.");
			}

			$this->previousEnv[$name] = getenv($name);
		}

		putenv('PP_GOOGLE_ADS_CLIENT_ID=test-client.apps.googleusercontent.com');
		putenv('PP_GOOGLE_ADS_CLIENT_SECRET=test-secret-value');
		putenv('PP_ENCRYPTION_KEY=' . SecretBox::generateKey());
		putenv('PP_ENCRYPTION_KEY_PREVIOUS');
		putenv('PP_GOOGLE_ADS_DEVELOPER_TOKEN');
		putenv('PP_GOOGLE_ADS_LOGIN_CUSTOMER_ID');
		// Tryb zapisu: domyślny (prepare_only) – testy zapisu włączają go jawnie i wyłącznie dla atrapy API.
		putenv('PP_GOOGLE_ADS_WRITE_MODE');
		putenv('PP_GOOGLE_ADS_API_VALIDATION');
		// Allowlista pusta, blokada emisji włączona – jak domyślna konfiguracja serwera.
		putenv('PP_GOOGLE_ADS_WRITE_CUSTOMERS');
		putenv('PP_GOOGLE_ADS_BLOCKED_CUSTOMERS');
		putenv('PP_GOOGLE_ADS_ALLOW_SERVING');

		$this->mock = (new GoogleAdsMock())->install();
	}

	public function tearDown(): void
	{
		$this->mock->uninstall();

		foreach ($this->previousEnv as $name => $value) {
			putenv($value === false ? $name : "{$name}={$value}");
		}

		parent::tearDown();
	}

	/**
	 * Pełny przepływ: zgoda → callback (wymiana kodu) → wybór konta. Zwraca aktywne połączenie.
	 */
	protected function connect(Project $project, int $userId): array
	{
		$service = $this->make(ConnectionService::class);
		$url = $service->begin($project, $userId);
		parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
		$state = $service->consumeState($query['state'], $userId);
		$service->complete($project, $userId, 'mock-code', $state['verifier']);

		$connection = $this->make(GoogleAdsConnectionRepository::class)->currentForProject($project->id);
		$service->selectAccount($project, $connection, GoogleAdsMock::CUSTOMER);

		return $this->make(GoogleAdsConnectionRepository::class)->currentForProject($project->id);
	}
}
