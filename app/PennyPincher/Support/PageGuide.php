<?php

namespace App\PennyPincher\Support;

/**
 * Instrukcje stron aplikacji (ikona dokumentu w prawym dolnym rogu). Klucz = `section` przekazywany przez kontroler
 * do layoutu; zakładki kampanii rozróżnia adres. Treść opisuje wyłącznie to, co moduł faktycznie robi.
 *
 * Wpis: title, intro (do czego służy), steps (krok po kroku), notes (dobrze wiedzieć).
 */
final class PageGuide
{
	public const GUIDES = [
		'overview' => [
			'title' => 'Centrum dowodzenia',
			'intro' => 'Najważniejsze liczby projektu w jednym miejscu: ile wydaliście, ile konwersji to dało, gdzie tracicie budżet i co warto zrobić najpierw.',
			'steps' => [
				'Ustaw zakres dat w górnym pasku – wszystkie liczby na stronie dotyczą tego okresu.',
				'Sprawdź KPI: wydatki, konwersje, koszt konwersji, CTR, CPC i wykorzystanie budżetu.',
				'Jeśli nie ma jeszcze analizy, kliknij „Uruchom analizę zakresu”.',
				'Przejrzyj TOP rekomendacje i wycieki budżetu – kliknięcie prowadzi do szczegółów i dowodów.',
				'Sprawdź status danych (import lub Google Ads) i ich aktualność.',
			],
			'notes' => [
				'„—” oznacza brak danych, a nie zero.',
				'Dane z raportu okresowego nie są rozbijane na dni – wykres dzienny pojawia się tylko przy danych dziennych.',
			],
		],
		'campaigns' => [
			'title' => 'Lista kampanii',
			'intro' => 'Wyniki wszystkich kampanii projektu w wybranym okresie, z sortowaniem i filtrami.',
			'steps' => [
				'Ustaw zakres dat w górnym pasku.',
				'Sortuj po kolumnie (np. koszt, konwersje), klikając jej nagłówek.',
				'Zawęź listę filtrami (nazwa, status, typ).',
				'Kliknij kampanię, aby zobaczyć jej szczegóły.',
			],
			'notes' => [
				'Kampanie pochodzą z importu albo synchronizacji z Google Ads – źródło widać przy danych.',
			],
		],
		'campaign' => [
			'title' => 'Kampania – Wyniki',
			'intro' => 'Szczegółowe wyniki jednej kampanii: metryki, grupy reklam i słowa kluczowe w wybranym okresie.',
			'steps' => [
				'Sprawdź metryki kampanii w wybranym okresie.',
				'Przejdź do zakładki „Wyszukiwane hasła”, aby zobaczyć, na co wyświetlały się reklamy.',
				'W zakładce „Optymalizacja” zobaczysz problemy i rekomendacje dotyczące tylko tej kampanii.',
				'W zakładce „Planowanie” są plany powiązane z kampanią.',
			],
			'notes' => [
				'Zakładki zachowują kampanię i okres – nie trzeba wybierać ich ponownie.',
			],
		],
		'campaign-optimization' => [
			'title' => 'Kampania – Optymalizacja',
			'intro' => 'Wyniki audytu, wycieki budżetu i rekomendacje zawężone do tej kampanii.',
			'steps' => [
				'Jeśli lista jest pusta, uruchom analizę okresu w sekcji Optymalizacja.',
				'Przejrzyj problemy i ich dowody.',
				'Otwórz rekomendację, aby ją zaakceptować albo odrzucić.',
			],
			'notes' => [
				'Oszczędności są szacunkiem, a nie gwarancją.',
			],
		],
		'campaign-planning' => [
			'title' => 'Kampania – Planowanie',
			'intro' => 'Plany kampanii z Campaign Plannera powiązane z tą kampanią.',
			'steps' => [
				'Otwórz istniejący plan albo utwórz nowy w Planowaniu.',
				'Uzupełnij słowa kluczowe, budżet i reklamy w planie.',
			],
			'notes' => [
				'Plany istnieją tylko w aplikacji – nic nie jest tworzone w Google Ads.',
			],
		],
		'search-terms' => [
			'title' => 'Wyszukiwane hasła',
			'intro' => 'Zapytania, na które wyświetlały się reklamy, z kosztem i konwersjami.',
			'steps' => [
				'Ustaw zakres dat i ewentualnie kampanię.',
				'Posortuj po koszcie, aby znaleźć drogie hasła bez konwersji.',
				'Niechciane hasła możesz dodać jako wykluczenia w Słowach kluczowych – zawsze ręcznie.',
			],
			'notes' => [
				'Brak konwersji przy małej liczbie kliknięć nie musi oznaczać straty.',
			],
		],
		'planner' => [
			'title' => 'Planowanie (Campaign Planner)',
			'intro' => 'Przygotowanie planu nowej kampanii: struktura, słowa kluczowe, wykluczenia, reklamy i budżet.',
			'steps' => [
				'Kliknij „Nowy plan” i podaj nazwę oraz cel.',
				'Dodaj grupy reklam i słowa kluczowe – dopasowanie wybierasz sam.',
				'Dodaj wykluczenia (aplikacja sprawdzi konflikty).',
				'Uzupełnij reklamy na podstawie kontekstu biznesowego.',
				'Ustal budżet i sprawdź prognozę (zakresy).',
				'Wyeksportuj plan do CSV albo przygotuj szkic zmian.',
			],
			'notes' => [
				'Plan nie tworzy niczego w Google Ads.',
				'Prognozy są zakresami; koszt leada nie jest prognozowany bez wiarygodnego pomiaru.',
			],
		],
		'optimization' => [
			'title' => 'Przegląd analiz',
			'intro' => 'Jeden punkt wejścia do audytu, wycieków budżetu, słów kluczowych i rekomendacji.',
			'steps' => [
				'Ustaw zakres dat – najlepiej okres, za który masz dane.',
				'Kliknij „Uruchom analizę okresu”.',
				'Przejrzyj podsumowanie każdego modułu i przejdź do szczegółów.',
				'Decyzje podejmij w Rekomendacjach.',
			],
			'notes' => [
				'Analiza czyta tylko dane z bazy – niczego nie zmienia na koncie Google Ads.',
			],
		],
		'audits' => [
			'title' => 'Audyt',
			'intro' => 'Sprawdzenie konta regułami: jakość danych, ustawienia kampanii, pomiar konwersji, budżet i wyniki.',
			'steps' => [
				'Ustaw okres i kliknij „Uruchom audyt dla tego okresu”.',
				'Przejrzyj wynik i listę problemów od najważniejszych.',
				'Rozwiń problem, aby zobaczyć dowody i zalecane działanie.',
				'Wcześniejsze audyty znajdziesz w historii.',
			],
			'notes' => [
				'Przy niepewnych danych reguła milczy albo zgłasza problem jakości danych.',
			],
		],
		'budget-leaks' => [
			'title' => 'Wycieki budżetu',
			'intro' => 'Miejsca, w których budżet jest wydawany bez efektu: hasła, słowa kluczowe, kampanie i ustawienia.',
			'steps' => [
				'Uruchom analizę dla wybranego okresu (albo „Ponowna analiza”).',
				'Sprawdź podsumowanie okresu i szacowane oszczędności.',
				'Przejrzyj wycieki – każdy ma pewność i dowody.',
				'Działania podejmij w Rekomendacjach albo Słowach kluczowych.',
			],
			'notes' => [
				'Oszczędności to SZACUNEK; nakładające się problemy nie są sumowane.',
				'Mała próba bez konwersji ≠ strata.',
			],
		],
		'keywords' => [
			'title' => 'Słowa kluczowe',
			'intro' => 'Nowe frazy do rozważenia i propozycje wykluczeń na podstawie wyszukiwanych haseł.',
			'steps' => [
				'Przejrzyj propozycje fraz z danych konta.',
				'Oznacz frazy decyzją (np. do użycia, odrzucona).',
				'Zaznacz niechciane hasła i kliknij „Przygotuj zaznaczone wykluczenia”.',
				'Opcjonalnie pobierz pomysły z DataForSEO – po teście połączenia i w ustalonym limicie.',
			],
			'notes' => [
				'Pobranie z DataForSEO jest płatne i wymaga Twojej zgody.',
				'Wykluczenia nigdy nie są dodawane automatycznie.',
			],
		],
		'recommendations' => [
			'title' => 'Rekomendacje',
			'intro' => 'Wszystkie zalecenia z audytu, wycieków i analiz – z priorytetem, pewnością, dowodami i statusem.',
			'steps' => [
				'Przejrzyj rekomendacje od najwyższego priorytetu.',
				'Otwórz rekomendację, aby zobaczyć dowody i proponowane działanie.',
				'Zmień status: zaakceptuj, odrzuć albo oznacz jako wykonaną; możesz dodać notatkę.',
				'Zaakceptowane trafiają do Planu działania; tam, gdzie to możliwe, przygotujesz szkic zmiany.',
			],
			'notes' => [
				'Status ustawiasz wyłącznie Ty – analiza go nie nadpisuje.',
			],
		],
		'assistant' => [
			'title' => 'Asystent',
			'intro' => 'Krótkie podsumowanie „co warto zrobić dzisiaj” i wyjaśnienia rekomendacji prostym językiem.',
			'steps' => [
				'Przejrzyj TOP 3 rekomendacje, zmiany czekające na zgodę i alerty.',
				'Kliknij rekomendację, aby zobaczyć, co wykryto i na jakiej podstawie.',
				'Przejdź do wskazanego następnego kroku.',
			],
			'notes' => [
				'Asystent działa na regułach (bez AI), tylko do odczytu i nie liczy nowych wartości.',
			],
		],
		'action-inbox' => [
			'title' => 'Kolejka działań',
			'intro' => 'Wszystkie działania w jednym miejscu: do oceny, zaakceptowane, w trakcie, wykonane i odrzucone.',
			'steps' => [
				'Wybierz zakładkę statusu.',
				'Otwórz działanie, aby zobaczyć jego ścieżkę: rekomendacja → zadanie → zmiana → monitoring.',
				'Wykonaj działanie ręcznie albo przygotuj szkic zmiany w Change Center.',
				'Po wykonaniu oznacz je jako wykonane.',
			],
			'notes' => [
				'„Wykonane” ustawiasz wyłącznie Ty.',
			],
		],
		'action-plan' => [
			'title' => 'Zadania (Plan działań)',
			'intro' => 'Lista zadań do wykonania, zbudowana z zaakceptowanych rekomendacji.',
			'steps' => [
				'Przejrzyj zadania w kolejności priorytetu.',
				'Wykonaj zadanie w Google Ads albo przez Change Center.',
				'Oznacz zadanie jako wykonane i dodaj notatkę.',
				'Efekty sprawdzisz w Monitoringu.',
			],
			'notes' => [
				'Plan nie zmienia niczego na koncie sam z siebie.',
			],
		],
		'monitoring' => [
			'title' => 'Monitoring',
			'intro' => 'Alerty o nagłych zmianach (koszt, CTR, CPA, konwersje) i efekty wdrożonych zmian.',
			'steps' => [
				'Przejrzyj nowe alerty w Centrum powiadomień.',
				'Oznacz alert jako przyjęty albo rozwiązany.',
				'Sprawdź „Efekty wdrożonych zmian”.',
				'Opcjonalnie włącz powiadomienia e-mail.',
			],
			'notes' => [
				'Monitoring działa automatycznie po każdej synchronizacji; „Sprawdź teraz” uruchamia go ręcznie.',
			],
		],
		'changes' => [
			'title' => 'Zmiany na koncie (Change Center)',
			'intro' => 'Jedyna droga zmian w Google Ads: szkic → przegląd → zatwierdzenie → wdrożenie, z pełną historią.',
			'steps' => [
				'Utwórz zestaw zmian albo otwórz szkic przygotowany z rekomendacji.',
				'Sprawdź operacje i różnice przed/po.',
				'Wyślij do przeglądu i zatwierdź (zatwierdzenie nie wdraża).',
				'Kliknij „Wdróż do Google Ads”, gdy jesteś gotowy.',
				'Sprawdź wynik każdej operacji; w razie potrzeby przygotuj cofnięcie.',
			],
			'notes' => [
				'Zmiany, które mogą zwiększyć wydatki aktywnej kampanii, są blokowane.',
				'Wdrożenie działa tylko przy włączonym trybie zapisu dla tego konta.',
			],
		],
		'conversion-center' => [
			'title' => 'Centrum konwersji',
			'intro' => 'Wyniki konwersji, zdarzenia i cele, diagnostyka pomiaru oraz historia zmian – w zakładkach.',
			'steps' => [
				'Zakładka Wyniki: konwersje Google Ads oraz cele biznesowe i leady osobno.',
				'Zakładka Zdarzenia/cele: klasyfikacja działań konwersji.',
				'Zakładka Diagnostyka: problemy z pomiarem.',
				'Zakładka Historia: zmiany ustawień konwersji.',
			],
			'notes' => [
				'Konwersje Google i biznesowe są liczone osobno – nie są ze sobą mieszane.',
			],
		],
		'conversions' => [
			'title' => 'Klasyfikacja konwersji',
			'intro' => 'Oznaczenie, które działania konwersji to prawdziwe cele biznesowe (np. lead), a które to mikrokonwersje.',
			'steps' => [
				'Przejrzyj listę działań konwersji.',
				'Dla każdego wybierz klasę (np. lead, mikrokonwersja).',
				'Zapisz – raporty i audyt użyją nowej klasyfikacji.',
			],
			'notes' => [
				'Klasyfikacja nie zmienia niczego w Google Ads.',
			],
		],
		'conversion-management' => [
			'title' => 'Konfiguracja konwersji w Google Ads',
			'intro' => 'Ustawienia działań konwersji na koncie: cele, liczenie i diagnostyka.',
			'steps' => [
				'Sprawdź stan działań konwersji i diagnostykę.',
				'Przygotuj szkic zmiany ustawień (np. sposób liczenia).',
				'Zatwierdź i wdróż ją przez Change Center.',
			],
			'notes' => [
				'Wymaga połączenia z Google Ads; zmiany idą wyłącznie przez Change Center.',
			],
		],
		'administration' => [
			'title' => 'Przegląd i konfiguracja',
			'intro' => 'Lista kroków konfiguracji projektu i skróty do ustawień, integracji i importów.',
			'steps' => [
				'Przejdź kroki po kolei: serwer, połączenie, konto, synchronizacja, importy, ustawienia.',
				'Kliknij krok, aby przejść do odpowiedniej strony.',
			],
			'notes' => [
				'Ta strona tylko pokazuje stan – niczego nie zapisuje.',
			],
		],
		'settings' => [
			'title' => 'Ustawienia projektu',
			'intro' => 'Dane projektu (nazwa, waluta, strefa czasowa, budżet miesięczny) i kontekst biznesowy.',
			'steps' => [
				'Uzupełnij dane projektu i budżet miesięczny, kliknij „Zapisz ustawienia”.',
				'Uzupełnij kontekst biznesowy (oferta, cel, język) i kliknij „Zapisz kontekst”.',
			],
			'notes' => [
				'Kontekst biznesowy jest używany do planów i szablonów reklam.',
				'Zmiana ustawień wymaga roli właściciela lub administratora.',
			],
		],
		'google-ads' => [
			'title' => 'Integracja Google Ads',
			'intro' => 'Połączenie projektu z kontem Google Ads, synchronizacja danych i diagnostyka.',
			'steps' => [
				'Kliknij połącz i zaloguj się kontem Google z dostępem do konta reklamowego.',
				'Wybierz konto i kliknij „Wybierz i pobierz dane”.',
				'Śledź postęp w historii synchronizacji.',
				'Przy problemach uruchom diagnostykę połączenia.',
			],
			'notes' => [
				'Kolejne synchronizacje uruchamiają się automatycznie.',
				'Połączenie daje odczyt; zapis jest osobno włączany i idzie przez Change Center.',
			],
		],
		'management' => [
			'title' => 'Zarządzanie kontem',
			'intro' => 'Edycja istniejących kampanii i grup reklam oraz przygotowanie nowej kampanii w sieci wyszukiwania.',
			'steps' => [
				'Wybierz kampanię albo grupę reklam.',
				'Przygotuj szkic zmiany (np. budżet, stawki, status).',
				'Zmiana trafia jako szkic do Change Center – tam ją zatwierdzasz i wdrażasz.',
			],
			'notes' => [
				'Nic nie jest zapisywane na koncie bez zatwierdzenia w Change Center.',
			],
		],
		'imports' => [
			'title' => 'Importy',
			'intro' => 'Wgrywanie raportów z Google Ads (CSV/XLSX), gdy nie ma połączenia z API albo jako uzupełnienie.',
			'steps' => [
				'Wybierz plik raportu i kliknij „Prześlij i pokaż podgląd”.',
				'Sprawdź typ raportu, mapowanie kolumn i zakres dat.',
				'Kliknij „Zatwierdź import” (albo „Anuluj import”).',
				'Wynik i błędy zobaczysz w historii importów.',
			],
			'notes' => [
				'Duplikaty i nakładające się okresy są wykrywane przed zapisem.',
			],
		],
		'projects' => [
			'title' => 'Projekty',
			'intro' => 'Lista projektów i organizacji, do których masz dostęp.',
			'steps' => [
				'Kliknij projekt, aby go otworzyć.',
				'Utwórz nowy projekt albo projekt demo.',
				'Członków organizacji zarządzasz z poziomu organizacji.',
			],
			'notes' => [
				'Projekt demo ma fikcyjne dane i nie jest połączony z kontem.',
			],
		],
	];

	public static function for(?string $section, string $path = ''): ?array
	{
		if ($section === 'campaigns' && preg_match('~campaigns/\d+(?:/(optimization|planning))?/?$~', $path, $m)) {
			$section = isset($m[1]) ? 'campaign-' . $m[1] : 'campaign';
		}

		return $section !== null ? self::GUIDES[$section] ?? null : null;
	}
}
