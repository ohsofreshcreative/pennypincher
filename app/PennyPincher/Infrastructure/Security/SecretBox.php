<?php

namespace App\PennyPincher\Infrastructure\Security;

use RuntimeException;

/**
 * Szyfrowanie sekretów (tokeny OAuth) kluczem spoza repozytorium.
 *
 * - klucz: stała lub zmienna środowiskowa `PP_ENCRYPTION_KEY` (32 bajty w base64, `wp acorn pp:key:generate`),
 * - rotacja: poprzedni klucz w `PP_ENCRYPTION_KEY_PREVIOUS` – odszyfrujemy nim stare wartości, nowe szyfrujemy bieżącym,
 * - algorytm: libsodium secretbox (XSalsa20-Poly1305) z losowym nonce; format `v1:<base64(nonce|ciphertext)>`.
 */
class SecretBox
{
	private const PREFIX = 'v1:';

	public static function isConfigured(): bool
	{
		return self::key('PP_ENCRYPTION_KEY') !== null;
	}

	public function encrypt(string $plain): string
	{
		$key = self::key('PP_ENCRYPTION_KEY');

		if ($key === null) {
			throw new RuntimeException('Brak klucza szyfrującego PP_ENCRYPTION_KEY w konfiguracji serwera.');
		}

		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

		return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
	}

	public function decrypt(string $encrypted): string
	{
		if (! str_starts_with($encrypted, self::PREFIX)) {
			throw new RuntimeException('Nieznany format zaszyfrowanej wartości.');
		}

		$raw = base64_decode(substr($encrypted, strlen(self::PREFIX)), true);

		if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
			throw new RuntimeException('Uszkodzona zaszyfrowana wartość.');
		}

		$nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

		foreach (['PP_ENCRYPTION_KEY', 'PP_ENCRYPTION_KEY_PREVIOUS'] as $name) {
			$key = self::key($name);

			if ($key !== null) {
				$plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);

				if ($plain !== false) {
					return $plain;
				}
			}
		}

		throw new RuntimeException('Nie udało się odszyfrować wartości – zmieniony klucz szyfrujący?');
	}

	public static function generateKey(): string
	{
		return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
	}

	private static function key(string $name): ?string
	{
		$value = defined($name) ? (string) constant($name) : (string) getenv($name);

		if ($value === '') {
			return null;
		}

		$key = base64_decode($value, true);

		if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
			throw new RuntimeException("{$name} musi być 32-bajtowym kluczem w base64 (wygeneruj: wp acorn pp:key:generate).");
		}

		return $key;
	}
}
