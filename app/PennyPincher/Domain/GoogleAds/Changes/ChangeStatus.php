<?php

namespace App\PennyPincher\Domain\GoogleAds\Changes;

/**
 * Statusy zestawu zmian (Change Center) i pojedynczych operacji.
 *
 * Zestaw: Szkic → Do przeglądu → Zatwierdzony → Walidacja → Gotowy do wdrożenia → Wdrażanie (kolejka/worker) →
 * Wdrożony / Częściowo wdrożony / Błąd / Wymaga uzgodnienia; w każdej chwili przed wdrożeniem – Anulowany.
 * „Zatwierdzony” oznacza akceptację propozycji, a nie wysłanie czegokolwiek do Google Ads.
 */
final class ChangeStatus
{
	public const DRAFT = 'draft';

	public const REVIEW = 'ready_for_review';

	public const APPROVED = 'approved';

	public const VALIDATING = 'validating';

	public const READY = 'ready_to_deploy';

	public const DEPLOYING = 'deploying';

	public const APPLIED = 'applied';

	public const PARTIAL = 'partially_applied';

	public const FAILED = 'failed';

	public const CANCELLED = 'cancelled';

	public const RECONCILE = 'needs_reconciliation';

	public const LABELS = [
		self::DRAFT => 'Szkic',
		self::REVIEW => 'Do przeglądu',
		self::APPROVED => 'Zatwierdzony',
		self::VALIDATING => 'Walidacja',
		self::READY => 'Gotowy do wdrożenia',
		self::DEPLOYING => 'Wdrażanie',
		self::APPLIED => 'Wdrożony',
		self::PARTIAL => 'Częściowo wdrożony',
		self::FAILED => 'Błąd wdrożenia',
		self::CANCELLED => 'Anulowany',
		self::RECONCILE => 'Wymaga uzgodnienia',
	];

	/** Krótkie wyjaśnienie dla użytkownika – co status znaczy dla konta Google Ads. */
	public const EXPLANATIONS = [
		self::DRAFT => 'Szkic – nic nie zostało wysłane do Google Ads. Możesz edytować operacje.',
		self::REVIEW => 'Czeka na przegląd i zatwierdzenie przez administratora. Nic nie zostało wysłane do Google Ads.',
		self::APPROVED => 'Zatwierdzony – propozycja zaakceptowana, ale jeszcze niewdrożona.',
		self::VALIDATING => 'Trwa sprawdzanie operacji przed wdrożeniem.',
		self::READY => 'Zatwierdzony i sprawdzony – czeka na świadome uruchomienie wdrożenia. Nic nie zostało jeszcze zmienione w Google Ads.',
		self::DEPLOYING => 'W kolejce lub w trakcie wysyłania do Google Ads – to jeszcze nie jest potwierdzenie wdrożenia.',
		self::APPLIED => 'Wdrożony – Google Ads przyjął wszystkie operacje. Sprawdź wynik weryfikacji (odczyt stanu po zmianie).',
		self::PARTIAL => 'Część operacji wdrożono, część nie – szczegóły przy każdej operacji.',
		self::FAILED => 'Google Ads odrzucił operacje albo wdrożenie zatrzymano – na koncie nic się nie zmieniło (szczegóły przy operacjach).',
		self::CANCELLED => 'Anulowany – zestaw nie zostanie wdrożony.',
		self::RECONCILE => 'Nie wiadomo, czy Google Ads przyjął zmianę (np. przekroczenie czasu odpowiedzi). Przed ponowieniem trzeba odczytać rzeczywisty stan konta („Uzgodnij stan”).',
	];

	/** Statusy, w których operacje można edytować. */
	public const EDITABLE = [self::DRAFT, self::REVIEW];

	/** Statusy, z których można anulować (przed wysłaniem do Google Ads). */
	public const CANCELLABLE = [self::DRAFT, self::REVIEW, self::APPROVED, self::READY, self::FAILED];

	/** Statusy końcowe po próbie wdrożenia (do historii i efektów). */
	public const DEPLOYED = [self::APPLIED, self::PARTIAL];

	public const TONES = [
		self::DRAFT => 'neutral', self::REVIEW => 'warn', self::APPROVED => 'info', self::VALIDATING => 'info', self::READY => 'info',
		self::DEPLOYING => 'info', self::APPLIED => 'ok', self::PARTIAL => 'warn', self::FAILED => 'danger', self::CANCELLED => 'neutral', self::RECONCILE => 'danger',
	];

	// --- Operacje ---------------------------------------------------------------------------------

	public const OP_PENDING = 'pending';

	public const OP_SENDING = 'sending';

	public const OP_APPLIED = 'applied';

	public const OP_VERIFIED = 'verified';

	public const OP_MISMATCH = 'verify_mismatch';

	public const OP_FAILED = 'failed';

	public const OP_SKIPPED = 'skipped';

	public const OP_CONFLICT = 'conflict';

	public const OP_RECONCILE = 'needs_reconciliation';

	public const OP_LABELS = [
		self::OP_PENDING => 'Oczekuje',
		self::OP_SENDING => 'Wysyłana',
		self::OP_APPLIED => 'Przyjęta przez API',
		self::OP_VERIFIED => 'Zweryfikowana',
		self::OP_MISMATCH => 'Stan po zmianie inny niż oczekiwany',
		self::OP_FAILED => 'Odrzucona',
		self::OP_SKIPPED => 'Pominięta (zależność)',
		self::OP_CONFLICT => 'Konflikt – stan zmieniony poza Penny Pincher',
		self::OP_RECONCILE => 'Wynik nieznany – wymaga uzgodnienia',
	];

	public const OP_TONES = [
		self::OP_PENDING => 'neutral', self::OP_SENDING => 'info', self::OP_APPLIED => 'info', self::OP_VERIFIED => 'ok', self::OP_MISMATCH => 'warn',
		self::OP_FAILED => 'danger', self::OP_SKIPPED => 'neutral', self::OP_CONFLICT => 'danger', self::OP_RECONCILE => 'danger',
	];

	/** Operacje, które Google Ads przyjął (nie wolno ich wysłać ponownie). */
	public const OP_DONE = [self::OP_APPLIED, self::OP_VERIFIED, self::OP_MISMATCH];

	public const RISKS = ['low' => 'Niskie', 'medium' => 'Średnie', 'high' => 'Wysokie'];

	public static function maxRisk(array $risks): string
	{
		return in_array('high', $risks, true) ? 'high' : (in_array('medium', $risks, true) ? 'medium' : 'low');
	}
}
