<?php

namespace App\PennyPincher\Domain\ActionPlan;

use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;

/**
 * Wspólna kolejka Planu działania (pp2_action_plan): zakładki mapują ISTNIEJĄCE statusy rekomendacji, zadań
 * Action Plan i zestawów Change Center – bez nowych statusów i bez zmiany semantyki (np. „Zablokowane” zostaje
 * statusem zadania, widocznym w „W toku”). Statusy zmienia się wyłącznie w modułach źródłowych.
 */
final class Inbox
{
	public const TABS = [
		'review' => 'Do oceny',
		'accepted' => 'Zaakceptowane',
		'in_progress' => 'W toku',
		'done' => 'Wykonane',
		'rejected' => 'Odrzucone',
		'monitoring' => 'Monitoring i historia',
	];

	/** Zakładka => [rekomendacje, zadania, zestawy zmian, alerty]. */
	public const MAP = [
		'review' => ['recommendations' => ['new', 'to_review'], 'actions' => [], 'changes' => [ChangeStatus::DRAFT, ChangeStatus::REVIEW], 'alerts' => []],
		'accepted' => ['recommendations' => ['accepted'], 'actions' => ['todo'], 'changes' => [ChangeStatus::APPROVED, ChangeStatus::VALIDATING, ChangeStatus::READY], 'alerts' => []],
		// „W toku” = wymaga działania lub trwa: zadania w trakcie i zablokowane, wdrożenia w kolejce, błędy i niepewne wyniki.
		'in_progress' => ['recommendations' => [], 'actions' => ['in_progress', 'blocked'], 'changes' => [ChangeStatus::DEPLOYING, ChangeStatus::RECONCILE, ChangeStatus::FAILED], 'alerts' => []],
		'done' => ['recommendations' => ['done'], 'actions' => ['done'], 'changes' => [ChangeStatus::APPLIED, ChangeStatus::PARTIAL], 'alerts' => []],
		'rejected' => ['recommendations' => ['rejected'], 'actions' => ['rejected'], 'changes' => [ChangeStatus::CANCELLED], 'alerts' => []],
		'monitoring' => ['recommendations' => [], 'actions' => [], 'changes' => [], 'alerts' => ['new', 'acknowledged']],
	];

	public const KINDS = ['recommendation' => 'Rekomendacja', 'action' => 'Zadanie', 'change' => 'Zmiana na koncie', 'alert' => 'Alert'];

	/** Ton etykiety statusu (ok / warn / danger / info / neutral) – zmiany biorą ton z ChangeStatus::TONES. */
	public const TONES = [
		'recommendation' => ['new' => 'info', 'to_review' => 'warn', 'accepted' => 'ok', 'done' => 'ok', 'rejected' => 'neutral'],
		'action' => ['todo' => 'info', 'in_progress' => 'info', 'blocked' => 'warn', 'done' => 'ok', 'rejected' => 'neutral'],
		'alert' => ['new' => 'warn', 'acknowledged' => 'info', 'muted' => 'neutral', 'resolved' => 'ok'],
	];

	public static function tab(?string $tab): string
	{
		return array_key_exists((string) $tab, self::TABS) ? (string) $tab : 'review';
	}

	/**
	 * Zakładka, w której jest dany status (null – status spoza kolejki, np. wyciszony alert).
	 */
	public static function tabOf(string $kind, string $status): ?string
	{
		$key = ['recommendation' => 'recommendations', 'action' => 'actions', 'change' => 'changes', 'alert' => 'alerts'][$kind] ?? null;

		foreach (self::MAP as $tab => $map) {
			if ($key !== null && in_array($status, $map[$key], true)) {
				return $tab;
			}
		}

		return null;
	}
}
