<?php

/**
 * Penny Pincher – lekki runner testów (bez PHPUnit, którego projekt nie ma w zależnościach).
 *
 * Uruchamianie (z katalogu motywu):
 *   wp eval-file tests/run.php                 – wszystkie testy (jednostkowe + integracyjne z bazą)
 *   wp eval-file tests/run.php unit            – tylko jednostkowe
 *   php tests/run.php unit                     – jednostkowe bez WordPressa
 *   PP_WP_LOAD=/ścieżka/wp-load.php php tests/run.php
 *
 * Testy integracyjne działają w transakcji wycofywanej po każdym teście – nie zostawiają danych w bazie.
 * Wymagają tabel InnoDB (domyślne) i uruchamiają brakujące migracje przed startem.
 */

$args = isset($args) && is_array($args) ? $args : array_slice($argv ?? [], 1);
$ppSuite = $args[0] ?? 'all';
$ppFilter = $args[1] ?? null;

$themeDir = dirname(__DIR__);

if (! defined('ABSPATH') && $ppSuite !== 'unit') {
	$wpLoad = getenv('PP_WP_LOAD') ?: dirname($themeDir, 3) . '/wp-load.php';

	if (! file_exists($wpLoad)) {
		fwrite(STDERR, "Nie znaleziono wp-load.php ({$wpLoad}). Uruchom przez `wp eval-file tests/run.php` albo ustaw PP_WP_LOAD.\n");
		exit(1);
	}

	$_SERVER['HTTP_HOST'] ??= 'localhost';
	$_SERVER['REQUEST_URI'] ??= '/';
	require $wpLoad;
}

if (! defined('ABSPATH')) {
	require $themeDir . '/vendor/autoload.php';
	require __DIR__ . '/wp-stubs.php';
}

require_once __DIR__ . '/TestCase.php';

if (defined('ABSPATH')) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once __DIR__ . '/IntegrationTestCase.php';
}

$integration = defined('ABSPATH') && function_exists('get_option') && class_exists(\Roots\Acorn\Application::class) && \Roots\Acorn\Application::getInstance() !== null && $ppSuite !== 'unit';

if ($integration) {
	$app = \Roots\Acorn\Application::getInstance();

	// Poza WP-CLI Acorn nie uruchamia kernela konsoli – bez tego providery aplikacji nie są zarejestrowane.
	if (! $app->hasBeenBootstrapped()) {
		$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
	}

	$app->make(\App\PennyPincher\Infrastructure\Database\Migrator::class)->migrate();
}

$ppSuites = $ppSuite === 'all' ? ['Unit', 'Integration'] : [ucfirst($ppSuite)];
$ppResults = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
$ppFailures = [];

foreach ($ppSuites as $dir) {
	if ($dir === 'Integration' && ! $integration) {
		echo "Pomijam testy integracyjne (brak WordPressa/bazy).\n";
		continue;
	}

	foreach (glob(__DIR__ . "/{$dir}/*Test.php") as $file) {
		require_once $file;
		$class = 'Tests\\' . $dir . '\\' . basename($file, '.php');

		foreach (get_class_methods($class) as $method) {
			if (! str_starts_with($method, 'test') || ($ppFilter && ! str_contains($class . '::' . $method, $ppFilter))) {
				continue;
			}

			$test = new $class;

			try {
				$test->setUp();
				$test->{$method}();
				$ppResults['passed']++;
				echo '.';
			} catch (\Tests\SkipTest $e) {
				$ppResults['skipped']++;
				echo 'S';
			} catch (\Throwable $e) {
				$ppResults['failed']++;
				$ppFailures[] = sprintf("%s::%s\n  %s: %s\n  %s:%d", $class, $method, get_class($e), $e->getMessage() . ($e instanceof \App\PennyPincher\Domain\Support\ValidationFailed ? ' ' . implode(' | ', $e->errors) : ''), $e->getFile(), $e->getLine());
				echo 'F';
			} finally {
				try {
					$test->tearDown();
				} catch (\Throwable $e) {
					$ppFailures[] = "{$class}::{$method} (tearDown): " . $e->getMessage();
				}
			}
		}
	}
}

echo "\n\n";

foreach ($ppFailures as $failure) {
	echo "FAIL {$failure}\n\n";
}

printf("Wynik: %d zaliczonych, %d niezaliczonych, %d pominiętych.\n", $ppResults['passed'], $ppResults['failed'], $ppResults['skipped']);

if ($ppResults['failed'] > 0) {
	if (class_exists('WP_CLI')) {
		WP_CLI::halt(1);
	}

	exit(1);
}
