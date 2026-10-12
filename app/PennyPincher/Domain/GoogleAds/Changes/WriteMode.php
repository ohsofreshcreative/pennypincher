<?php

namespace App\PennyPincher\Domain\GoogleAds\Changes;

/**
 * Tryb pracy z kontem Google Ads.
 *
 * - READ_ONLY – tylko odczyt (bez przygotowywania zmian),
 * - PREPARE_ONLY – przygotowanie, przegląd, zatwierdzanie i walidacja zmian; wdrożenie zablokowane (domyślny),
 * - WRITE_ENABLED – wdrażanie zatwierdzonych zmian przez uprawnionego wykonawcę.
 *
 * Tryb efektywny = najbardziej restrykcyjny z: konfiguracji serwera (stała PP_GOOGLE_ADS_WRITE_MODE) i zgody
 * na zapis zapisanej przy połączeniu. Ustawienie w interfejsie nigdy samo nie włącza zapisu.
 */
enum WriteMode: string
{
	case ReadOnly = 'read_only';
	case PrepareOnly = 'prepare_only';
	case WriteEnabled = 'write_enabled';

	public static function fromConfig(?string $value): self
	{
		if ($value === null || trim($value) === '') {
			return self::PrepareOnly;
		}

		// Nieznana wartość = najbardziej restrykcyjny tryb (literówka w konfiguracji nie może włączyć zapisu).
		return self::tryFrom(strtolower(trim($value))) ?? self::ReadOnly;
	}

	public function rank(): int
	{
		return match ($this) {
			self::ReadOnly => 0,
			self::PrepareOnly => 1,
			self::WriteEnabled => 2,
		};
	}

	public static function min(self $a, self $b): self
	{
		return $a->rank() <= $b->rank() ? $a : $b;
	}

	public function canPrepare(): bool
	{
		return $this !== self::ReadOnly;
	}

	public function canWrite(): bool
	{
		return $this === self::WriteEnabled;
	}

	public function label(): string
	{
		return match ($this) {
			self::ReadOnly => 'Tylko odczyt',
			self::PrepareOnly => 'Przygotowanie zmian (bez zapisu)',
			self::WriteEnabled => 'Zapis włączony',
		};
	}

	public function description(): string
	{
		return match ($this) {
			self::ReadOnly => 'Penny Pincher tylko odczytuje dane konta. Przygotowywanie zmian jest wyłączone.',
			self::PrepareOnly => 'Możesz przygotowywać, sprawdzać i zatwierdzać zmiany, ale nic nie zostanie wysłane do Google Ads. Zapis włącza się osobno: konfiguracja serwera + zgoda właściciela dla konta.',
			self::WriteEnabled => 'Zatwierdzone zmiany może wdrożyć uprawniony wykonawca. Każde wdrożenie wymaga świadomego kliknięcia.',
		};
	}
}
