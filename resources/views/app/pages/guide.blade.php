{{-- Zarządzanie › Instrukcja krok po kroku: od konfiguracji do uruchomienia i dopracowywania kampanii. --}}
@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;

  $p = $project->id;
  // [tekst kroku, ścieżka względem projektu albo null]
  $stages = [
    [
      'title' => 'Krok 0. Zanim zaczniesz',
      'intro' => 'Potrzebujesz konta Google Ads z dostępem administratora (albo dostępem przez konto menedżera) i strony, na którą kampania ma kierować.',
      'steps' => [
        ['Upewnij się, że pracujesz w dobrym projekcie – nazwa projektu jest w lewym górnym rogu. Nowy projekt utworzysz w Zarządzanie → Projekty.', null],
        ['Jeden projekt = jeden klient / jedno konto Google Ads. Nie mieszaj kont w jednym projekcie.', null],
      ],
    ],
    [
      'title' => 'Krok 1. Połącz Google Ads',
      'intro' => 'Bez danych z konta aplikacja nic nie przeanalizuje – wszystkie liczby będą „—”.',
      'steps' => [
        ['Wejdź w Zarządzanie → Integracja Google Ads.', 'google-ads'],
        ['Kliknij „Połącz z Google” i zaloguj się kontem, które ma dostęp do konta reklamowego. Zaakceptuj uprawnienia.', 'google-ads'],
        ['Wybierz z listy właściwe konto reklamowe (ID klienta, np. 123-456-7890) i zapisz.', 'google-ads'],
        ['Kliknij „Synchronizuj” / pobierz historię. Pierwsza synchronizacja może potrwać kilka minut – status widać na tej stronie i w lewym menu.', 'google-ads'],
        ['Jeśli coś nie działa, użyj „Diagnostyka” – pokaże, czego brakuje (token, dostęp, konto).', 'google-ads'],
        ['Alternatywa bez API: Zarządzanie → Importy – wgraj raporty CSV wyeksportowane z Google Ads.', 'imports'],
      ],
    ],
    [
      'title' => 'Krok 2. Opisz biznes (kontekst projektu)',
      'intro' => 'Z kontekstu powstają propozycje słów kluczowych, wykluczeń i treści reklam. Im dokładniej, tym lepsze propozycje.',
      'steps' => [
        ['Wejdź w Zarządzanie → Ustawienia projektu.', 'settings'],
        ['Wpisz usługi/produkty, które chcesz reklamować (każdą osobno).', 'settings'],
        ['Wpisz lokalizacje (miasta, region), w których działasz.', 'settings'],
        ['Wpisz wyróżniki (np. „dostawa 24h”, „20 lat doświadczenia”) – tylko prawdziwe, trafią do reklam.', 'settings'],
        ['Wpisz, czego NIE oferujesz (np. „praca”, „darmowe”, „używane”) – to podstawa wykluczeń.', 'settings'],
        ['Ustaw cel (leady/sprzedaż), docelowy koszt leada i miesięczny budżet. Zapisz.', 'settings'],
      ],
    ],
    [
      'title' => 'Krok 3. Ustaw pomiar konwersji',
      'intro' => 'Bez poprawnych konwersji nie wiadomo, co działa – nie skaluj kampanii, która ich nie mierzy.',
      'steps' => [
        ['Wejdź w Konwersje.', 'conversions'],
        ['Przy każdej akcji konwersji wybierz klasę: lead/sprzedaż (główna), pomocnicza albo ignoruj. Zapisz.', 'conversions'],
        ['Wejdź w Zarządzanie konwersjami i sprawdź „zdrowie” – czy akcje rejestrują konwersje w ostatnich dniach.', 'conversion-management'],
        ['Jeśli nie masz żadnej konwersji, utwórz ją (np. wysłanie formularza, kliknięcie telefonu) i zainstaluj tag na stronie.', 'conversion-management'],
      ],
    ],
    [
      'title' => 'Krok 4. Sprawdź stan konta (audyt)',
      'steps' => [
        ['Wejdź w Optymalizacja → Audyt i uruchom audyt.', 'audits'],
        ['Przejrzyj problemy od najważniejszych (krytyczne → ostrzeżenia). Każdy problem ma opis i co poprawić.', 'audits'],
        ['Popraw błędy konfiguracji: lokalizacje, harmonogram, sieci (wyłącz sieć reklamową w kampanii w wyszukiwarce), brak wykluczeń, rozszerzenia.', 'audits'],
      ],
    ],
    [
      'title' => 'Krok 5. Zaplanuj nową kampanię',
      'intro' => 'Plan powstaje tylko w aplikacji – Penny Pincher nie tworzy kampanii w Google Ads.',
      'steps' => [
        ['Wejdź w Kampanie → Planowanie i utwórz nowy plan (nazwa, cel, budżet, lokalizacja).', 'planner'],
        ['Dodaj grupy reklam – jedna grupa = jedna usługa / jeden temat.', 'planner'],
        ['Dodaj słowa kluczowe do grup. Używaj dopasowania ścisłego i do wyrażenia. Pomysły znajdziesz w Optymalizacja → Keyword Opportunities.', 'keywords'],
        ['Dodaj wykluczenia (słowa, na które reklama ma się NIE wyświetlać). Aplikacja ostrzeże, jeśli wykluczenie blokuje Twoje słowo.', 'planner'],
        ['Przygotuj reklamy: nagłówki i opisy z kontekstu. Sprawdź ostrzeżenia o długości i powtórzeniach.', 'planner'],
        ['Rozdziel budżet między grupy. Prognozy są zakresami, nie gwarancją.', 'planner'],
        ['Oznacz plan jako gotowy i wyeksportuj go do CSV.', 'planner'],
      ],
    ],
    [
      'title' => 'Krok 6. Utwórz i uruchom kampanię w Google Ads',
      'steps' => [
        ['W Google Ads utwórz kampanię na podstawie planu (ręcznie albo przez import CSV w Google Ads Editor – sprawdź kolumny przed importem).', null],
        ['Ustaw lokalizacje, harmonogram, budżet dzienny i strategię stawek tak jak w planie.', null],
        ['Włącz kampanię (status z „Wstrzymana” na „Włączona”). To robisz w Google Ads – Penny Pincher celowo nie włącza kampanii i nie podnosi wydatków.', null],
        ['Wróć tutaj i uruchom synchronizację, żeby nowa kampania pojawiła się na liście.', 'google-ads'],
      ],
    ],
    [
      'title' => 'Krok 7. Pierwsze 2 tygodnie',
      'steps' => [
        ['Codziennie zerknij w Centrum dowodzenia: czy kampania wydaje, ma kliknięcia i konwersje.', ''],
        ['Co 2–3 dni przejrzyj Kampanie → Wyszukiwane hasła i wyklucz frazy bez związku z ofertą.', 'search-terms'],
        ['Nie zmieniaj dużo naraz – kampania potrzebuje 7–14 dni danych. Przy małej próbie aplikacja świadomie nie wydaje werdyktów.', null],
      ],
    ],
    [
      'title' => 'Krok 8. Dopracowywanie (co tydzień)',
      'steps' => [
        ['Centrum dowodzenia – sprawdź KPI (koszt, konwersje, koszt konwersji) i TOP 3 działania.', ''],
        ['Wyszukiwane hasła – wyklucz złe frazy, dobre dodaj jako słowa kluczowe.', 'search-terms'],
        ['Budget Leaks – zobacz, gdzie budżet ucieka bez efektu (oszczędności to szacunek).', 'budget-leaks'],
        ['Rekomendacje – przy każdej zdecyduj: przyjmij, odrzuć albo odłóż. Twoich decyzji aplikacja nie nadpisuje.', 'recommendations'],
        ['Plan działania – przyjęte rekomendacje zamień w zadania i odhaczaj „Wykonane”.', 'action-plan'],
        ['Zmiany na koncie – przygotuj szkic zmiany (np. wykluczenie, wstrzymanie słowa), sprawdź podgląd, zatwierdź i wyślij. Każdą zmianę można cofnąć.', 'changes'],
        ['Monitoring – po zmianach obserwuj alerty i oceń efekt po kilku dniach.', 'monitoring'],
      ],
    ],
  ];
@endphp

@section('content')
  <div class="space-y-4">
    <section class="pp-card">
      <h2 class="pp-card-title">Jak pracować z Penny Pincher</h2>
      <p class="mt-1 text-sm text-ink-soft">Idź po kolei: dane → kontekst → konwersje → audyt → plan → uruchomienie w Google Ads → cotygodniowe dopracowywanie. Przy krokach jest link „Otwórz” do właściwego ekranu. Na każdej stronie ikona dokumentu w prawym dolnym rogu pokazuje instrukcję tej strony.</p>
    </section>

    @foreach ($stages as $stage)
      <section class="pp-card">
        <h2 class="pp-card-title">{{ $stage['title'] }}</h2>
        @if (! empty($stage['intro']))
          <p class="mt-1 text-sm text-ink-soft">{{ $stage['intro'] }}</p>
        @endif
        <ol class="mt-2 list-decimal space-y-1.5 pl-5 text-sm text-ink-soft">
          @foreach ($stage['steps'] as [$text, $path])
            <li>
              {{ $text }}
              @if ($path !== null)
                <a class="text-mint-dark underline" href="{{ AppUrl::project($p, $path) }}">Otwórz</a>
              @endif
            </li>
          @endforeach
        </ol>
      </section>
    @endforeach
  </div>
@endsection
