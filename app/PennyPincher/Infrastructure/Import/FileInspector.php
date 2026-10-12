<?php

namespace App\PennyPincher\Infrastructure\Import;

use App\PennyPincher\Domain\Import\InvalidValue;

/**
 * Walidacja przesłanego pliku: rozmiar, rozszerzenie i rzeczywisty format (sygnatura + treść).
 * Plik nigdy nie jest zapisywany w katalogu publicznym – czytamy go z katalogu tymczasowego PHP i odrzucamy.
 */
final class FileInspector
{
	public const EXTENSIONS = ['csv' => 'csv', 'tsv' => 'csv', 'txt' => 'csv', 'xlsx' => 'xlsx'];

	public static function maxBytes(): int
	{
		$configured = (int) apply_filters('pennypincher/import/max_bytes', 15 * 1024 * 1024);

		return max(1, min($configured, (int) wp_max_upload_size()));
	}

	/**
	 * @param array{name?: string, tmp_name?: string, error?: int, size?: int}|null $upload wpis z $_FILES
	 * @return array{path: string, name: string, format: string, size: int, hash: string}
	 */
	public static function inspect(?array $upload): array
	{
		if (! $upload || ! isset($upload['error'])) {
			throw new InvalidValue('Wybierz plik z raportem.');
		}

		$error = (int) $upload['error'];

		if ($error === UPLOAD_ERR_NO_FILE) {
			throw new InvalidValue('Wybierz plik z raportem.');
		}

		if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
			throw new InvalidValue(sprintf('Plik jest za duży. Maksymalny rozmiar to %s.', size_format(self::maxBytes())));
		}

		if ($error !== UPLOAD_ERR_OK) {
			throw new InvalidValue('Przesyłanie pliku nie powiodło się. Spróbuj ponownie.');
		}

		$path = (string) ($upload['tmp_name'] ?? '');

		if ($path === '' || ! is_uploaded_file($path)) {
			throw new InvalidValue('Przesyłanie pliku nie powiodło się. Spróbuj ponownie.');
		}

		return self::inspectPath($path, (string) ($upload['name'] ?? 'raport'));
	}

	/**
	 * Wersja bez sprawdzania is_uploaded_file (CLI, dane demonstracyjne, testy).
	 *
	 * @return array{path: string, name: string, format: string, size: int, hash: string}
	 */
	public static function inspectPath(string $path, string $originalName): array
	{
		$name = sanitize_file_name(wp_basename($originalName)) ?: 'raport';
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$size = (int) filesize($path);

		if (! isset(self::EXTENSIONS[$extension])) {
			throw new InvalidValue('Nieobsługiwany typ pliku. Prześlij eksport Google Ads w formacie CSV lub XLSX.');
		}

		if ($size === 0) {
			throw new InvalidValue('Plik jest pusty.');
		}

		if ($size > self::maxBytes()) {
			throw new InvalidValue(sprintf('Plik jest za duży. Maksymalny rozmiar to %s.', size_format(self::maxBytes())));
		}

		$format = self::EXTENSIONS[$extension];
		$head = (string) file_get_contents($path, false, null, 0, 8);

		if ($format === 'xlsx' && ! str_starts_with($head, "PK\x03\x04")) {
			throw new InvalidValue('Plik ma rozszerzenie .xlsx, ale nie jest arkuszem Excela.');
		}

		if ($format === 'csv') {
			if (str_starts_with($head, "PK\x03\x04")) {
				throw new InvalidValue('Plik wygląda na arkusz Excela – zmień rozszerzenie na .xlsx albo wyeksportuj CSV.');
			}

			if (str_starts_with($head, '%PDF') || str_starts_with($head, "\x7FELF") || str_starts_with($head, 'MZ') || preg_match('/^\s*<\?php/i', $head)) {
				throw new InvalidValue('Plik nie jest prawidłowym plikiem CSV.');
			}
		}

		return [
			'path' => $path,
			'name' => $name,
			'format' => $format,
			'size' => $size,
			'hash' => hash_file('sha256', $path),
		];
	}
}
