<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Konfiguracja wdrożenia i ochrony plików – sprawdzana statycznie (bez łączenia z serwerem).
 */
class DeploymentConfigTest extends TestCase
{
	private function root(): string
	{
		return dirname(__DIR__, 2);
	}

	public function testThemeHtaccessAllowsOnlyStaticAssets(): void
	{
		$htaccess = (string) file_get_contents($this->root() . '/.htaccess');

		$this->assertContains('RewriteRule ^ - [F,L]', $htaccess);
		$this->assertContains('public/build/assets/', $htaccess);
		$this->assertContains('Options -Indexes', $htaccess);

		preg_match('/RewriteRule \^\((.+)\)\$ - \[L\]/', $htaccess, $m);
		$allowed = '#^(' . $m[1] . ')$#';

		foreach (['public/build/assets/pp-app-abc.css', 'resources/images/signet.svg', 'resources/fonts/PolySans Neutral.woff2', 'style.css'] as $path) {
			$this->assertTrue((bool) preg_match($allowed, $path), "Dostępne: {$path}");
		}

		foreach (['key', 'composer.json', 'vendor/autoload.php', 'app/PennyPincher/Services/AuditService.php', 'routes/app.php', 'public/build/manifest.json', '.git/config', 'scripts/deploy.env', 'resources/views/app/pages/login.blade.php', 'functions.php', 'tests/run.php', 'resources/images/x.php'] as $path) {
			$this->assertFalse((bool) preg_match($allowed, $path), "Zablokowane: {$path}");
		}
	}

	public function testSecretsAndDeployConfigAreIgnoredByGit(): void
	{
		$ignore = (string) file_get_contents($this->root() . '/.gitignore');

		foreach (['scripts/deploy.env', 'deploy/', '.env', "\nkey\n"] as $entry) {
			$this->assertContains($entry, $ignore);
		}
	}

	public function testDeployScriptIsSafe(): void
	{
		$deploy = (string) file_get_contents($this->root() . '/scripts/deploy.sh');

		$this->assertContains('SHA256:G3BAf5zZ4Vff3QXCHmboFPmVKNlR5KSQ5Rlm8UlpVy0', $deploy, 'Ujawniony klucz SSH jest zablokowany.');
		$this->assertContains('pp:migrate', $deploy);
		$this->assertContains('db export', $deploy, 'Kopia bazy przed wdrożeniem.');
		$this->assertContains("rm -rf \"\$BUILD/key\"", $deploy, 'Plik key nie trafia na serwer.');
		$this->assertContains('--check', $deploy);
		$this->assertFalse(str_contains($deploy, 'db import'), 'Wdrożenie nie nadpisuje bazy produkcyjnej.');
		$this->assertFalse(str_contains($deploy, 'core download') || str_contains($deploy, 'core install'), 'Wdrożenie nie instaluje WordPressa.');
		$this->assertFalse((bool) preg_match('/(DB_PASSWORD|PASSWORD=|PRIVATE KEY)/', $deploy));

		$rollback = (string) file_get_contents($this->root() . '/scripts/rollback.sh');
		$this->assertContains('--database', $rollback, 'Przywrócenie bazy tylko na wyraźne żądanie.');
	}

	public function testNoPrivateKeysInSourceFilesOtherThanKnownLeakedKey(): void
	{
		$iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS),
			fn ($file) => ! in_array($file->getFilename(), ['vendor', 'node_modules', '.git', 'build', 'fixtures'], true)
		));

		$found = [];

		foreach ($iterator as $file) {
			$relative = substr($file->getPathname(), strlen($this->root()) + 1);

			// Plik `key` to znany ujawniony klucz (do rotacji i usunięcia przez właściciela) – nie odczytujemy go.
			if ($relative === 'key' || $file->getSize() > 2_000_000) {
				continue;
			}

			$content = (string) file_get_contents($file->getPathname());

			if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|"private_key"\s*:|AIza[0-9A-Za-z_-]{35}|GOCSPX-[0-9A-Za-z_-]{20,}|1\/\/0[0-9A-Za-z_-]{30,}/', $content)) {
				$found[] = $relative;
			}
		}

		$this->assertSame([], $found, 'Pliki zawierające wyglądające na prawdziwe sekrety.');
	}

	public function testWpCronIsNotTheOnlySchedulerAndCommandsExist(): void
	{
		foreach (['SyncWorkCommand', 'SyncProjectCommand', 'AuditCommand', 'GenerateKeyCommand', 'GoogleAdsCheckCommand', 'GoogleAdsDiagnoseCommand'] as $command) {
			$this->assertTrue(file_exists($this->root() . "/app/PennyPincher/Console/{$command}.php"), $command);
		}

		$provider = (string) file_get_contents($this->root() . '/app/PennyPincher/Providers/PennyPincherServiceProvider.php');
		$this->assertContains('SyncWorkCommand::class', $provider);
		$this->assertContains('Hardening::register()', $provider);
	}
}
