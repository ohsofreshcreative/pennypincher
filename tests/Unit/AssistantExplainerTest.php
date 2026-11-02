<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Assistant\Explainer;
use Tests\TestCase;

/**
 * Asystent: wyjaśnienie nie wprowadza nowych liczb (AI/asystent nie jest źródłem liczb) i wskazuje bezpieczny
 * następny krok – zapis na koncie zawsze z wymaganą zgodą, bez zmiany statusów.
 */
class AssistantExplainerTest extends TestCase
{
	private function rec(array $over = []): array
	{
		return $over + [
			'id' => 7, 'source' => 'budget_leaks', 'sources' => ['budget_leaks', 'audit'], 'type' => 'leak_bl301', 'status' => 'new', 'is_obsolete' => false,
			'title' => 'Kampania bez konwersji', 'description' => 'Kampania wydała 412,50 zł bez konwersji przy 87 kliknięciach.',
			'action' => 'Wstrzymaj kampanię albo popraw pomiar.', 'confidence' => 'high', 'impact_label' => 'Szacunek: ok. 380 zł / mies.',
			'evidence' => [['Koszt', '412,50 zł'], ['Kliknięcia', '87'], ['Konwersje', '0']], 'date_from' => '2026-05-01', 'date_to' => '2026-05-31',
		];
	}

	/** @return array<int, string> */
	private static function numbers(mixed $value): array
	{
		$text = is_array($value) ? implode(' ', array_map(fn ($v) => implode(' ', self::numbers($v)), $value)) : (string) $value;
		preg_match_all('/\d+(?:[.,]\d+)?/', $text, $m);

		return $m[0];
	}

	public function testExplanationUsesOnlyNumbersFromStoredResults(): void
	{
		$rec = $this->rec();
		$out = Explainer::recommendation($rec, ['id' => 15, 'status' => 'ready_to_deploy'], true);
		$allowed = array_merge(self::numbers([$rec['description'], $rec['action'], $rec['impact_label'], $rec['evidence']]), self::numbers(\App\PennyPincher\Support\Format::period($rec['date_from'], $rec['date_to'])), ['15']);

		foreach (self::numbers($out) as $number) {
			$this->assertTrue(in_array($number, $allowed, true), "Liczba „{$number}” nie pochodzi z wyniku reguły.");
		}

		$this->assertSame($rec['evidence'], $out['evidence'], 'Dowody przytoczone bez zmian.');
		$this->assertSame('Budget Leaks + Audyt', $out['basis']['Źródło']);
		$this->assertContains('2026', $out['basis']['Okres danych']);
		$this->assertSame($rec['impact_label'], $out['estimate']);
		$this->assertContains('szacunek', implode(' ', $out['caveats']));
	}

	public function testNextStepFollowsStatusAndRequiresConsentForAccountChanges(): void
	{
		$step = fn (array $rec, ?array $change = null, bool $deployable = true) => Explainer::next($rec, $change, $deployable)[0];

		$this->assertSame(['recommendation', false], [$step($this->rec())['key'], $step($this->rec())['consent']], 'Nowa: decyzja użytkownika.');
		$this->assertSame('recommendation', $step($this->rec(['status' => 'to_review']))['key']);
		$this->assertSame(['prepare', true], array_values(array_intersect_key($step($this->rec(['status' => 'accepted'])), ['key' => 0, 'consent' => 0])));
		$this->assertSame(['action', false], array_values(array_intersect_key($step($this->rec(['status' => 'accepted']), null, false), ['key' => 0, 'consent' => 0])), 'Poza API: wykonanie ręczne.');
		$change = $step($this->rec(['status' => 'accepted']), ['id' => 9, 'status' => 'draft']);
		$this->assertSame(['change', true], [$change['key'], $change['consent']]);
		$this->assertContains('#9', $change['label']);
		$this->assertSame('monitoring', $step($this->rec(['status' => 'done']))['key']);
		$this->assertSame('recommendation', $step($this->rec(['status' => 'accepted', 'is_obsolete' => true]))['key'], 'Nieaktualna: najpierw sprawdzenie, nie zmiana.');
		$this->assertSame('recommendation', $step($this->rec(['status' => 'rejected']))['key']);
	}

	public function testLowConfidenceAndMissingEvidenceAreFlagged(): void
	{
		$out = Explainer::recommendation($this->rec(['confidence' => 'low', 'evidence' => [], 'impact_label' => null]), null, true);

		$this->assertContains('Niska pewność', implode(' ', $out['caveats']));
		$this->assertContains('nie zapisała szczegółowych dowodów', implode(' ', $out['caveats']));
		$this->assertSame(null, $out['estimate']);
		$this->assertContains('mała próba', $out['basis']['Pewność']);
	}
}
