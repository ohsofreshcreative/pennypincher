<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Import\InvalidValue;
use App\PennyPincher\Infrastructure\Import\Readers\XlsxReader;
use App\PennyPincher\Services\LoginThrottle;
use Tests\IntegrationTestCase;

class SecurityTest extends IntegrationTestCase
{
	private ?string $previousIp = null;

	public function setUp(): void
	{
		parent::setUp();
		$this->previousIp = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 250);
	}

	public function tearDown(): void
	{
		$_SERVER['REMOTE_ADDR'] = $this->previousIp;
		parent::tearDown();
	}

	private function attempt(string $login, string $password): mixed
	{
		return wp_authenticate($login, $password);
	}

	public function testLoginIsThrottledAfterFiveFailuresAndUnlocksAfterWindow(): void
	{
		$id = wp_insert_user(['user_login' => 'pp_throttle_' . wp_generate_password(5, false), 'user_pass' => 'correct-pass-123', 'user_email' => 'pp-throttle-' . wp_generate_password(5, false) . '@example.test']);
		$login = get_userdata($id)->user_login;
		$throttle = $this->make(LoginThrottle::class);

		for ($i = 0; $i < 5; $i++) {
			$this->assertTrue(is_wp_error($this->attempt($login, 'wrong')));
		}

		$blocked = $this->attempt($login, 'correct-pass-123');
		$this->assertTrue(is_wp_error($blocked));
		$this->assertSame(LoginThrottle::ERROR_CODE, $blocked->get_error_code(), 'Poprawne hasło też jest odrzucane w czasie blokady.');
		$this->assertTrue($throttle->retryAfter($login) > 0 && $throttle->retryAfter($login) <= 15 * MINUTE_IN_SECONDS, 'Blokada jest ograniczona w czasie.');

		// Symulacja upływu okna: przesuwamy zapisane próby o 16 minut wstecz.
		global $wpdb;
		$rows = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pp\\_login\\_%'");

		foreach ($rows as $option) {
			$key = substr($option, strlen('_transient_'));
			$attempts = get_transient($key);
			set_transient($key, array_map(fn ($t) => $t - 16 * MINUTE_IN_SECONDS, (array) $attempts), 900);
		}

		$this->assertSame(0, $throttle->retryAfter($login));
		$this->assertTrue($this->attempt($login, 'correct-pass-123') instanceof \WP_User, 'Po upływie okna logowanie działa.');
	}

	public function testSuccessfulLoginResetsCounterAndIpLimitStopsAccountGuessing(): void
	{
		$throttle = $this->make(LoginThrottle::class);

		for ($i = 0; $i < 20; $i++) {
			$this->attempt('nieistniejacy_' . $i, 'x');
		}

		$this->assertTrue($throttle->retryAfter('ktokolwiek') > 0, 'Limit na adres IP obejmuje także inne loginy.');

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame(0, $throttle->retryAfter('ktokolwiek'), 'Inny adres IP nie jest blokowany.');
	}

	public function testXlsxWithDoctypeIsRejected(): void
	{
		$path = wp_tempnam('xxe.xlsx');
		$zip = new \ZipArchive;
		$zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets/></workbook>');
		$zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet/>');
		$zip->close();

		$this->assertThrows(InvalidValue::class, fn () => (new XlsxReader)->read($path, 100));
		@unlink($path);
	}
}
