@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
@endphp

@section('content')
  @if ($errors)
    <x-pp.alert type="error" title="Nie zapisano zmian">Popraw zaznaczone pola.</x-pp.alert>
  @endif

  @unless ($canEdit)
    <x-pp.alert type="info">Ustawienia może zmieniać właściciel lub administrator organizacji. Ty możesz je tylko przeglądać.</x-pp.alert>
  @endunless

  <form method="post" action="{{ AppUrl::project($project->id, 'settings') }}" class="pp-card max-w-4xl" novalidate>
    <x-pp.nonce />
    <div class="pp-card-header">
      <h2 class="pp-card-title">Projekt</h2>
      <p class="text-[13px] text-ink-mute">Organizacja: {{ $project->organizationName }}</p>
    </div>
    <div class="p-5 sm:p-6">
      @include('app.partials.project-fields', ['model' => $project, 'disabled' => ! $canEdit])
    </div>
    @if ($canEdit)
      <div class="flex flex-wrap items-center gap-3 border-t border-line px-5 py-4 sm:px-6">
        <x-pp.button>Zapisz ustawienia</x-pp.button>
        <p class="text-sm text-ink-mute">Zmiana waluty nie przelicza już zaimportowanych danych.</p>
      </div>
    @endif
  </form>

  <form method="post" action="{{ AppUrl::project($project->id, 'settings/context') }}" id="kontekst" class="pp-card max-w-4xl scroll-mt-24" novalidate>
    <x-pp.nonce />
    <div class="pp-card-header">
      <h2 class="pp-card-title">Kontekst biznesowy</h2>
      <p class="text-[13px] text-ink-mute">Używany w Budget Leaks, rekomendacjach i ocenie słów kluczowych</p>
    </div>
    <div class="p-5 sm:p-6">
      @include('app.partials.context-fields', ['disabled' => ! $canEdit, 'errors' => $contextErrors ?? [], 'old' => $contextOld ?? []])
    </div>
    @if ($canEdit)
      <div class="flex flex-wrap items-center gap-3 border-t border-line px-5 py-4 sm:px-6">
        <x-pp.button>Zapisz kontekst</x-pp.button>
        <a href="{{ AppUrl::project($project->id, 'conversions') }}" class="text-sm font-semibold text-mint hover:underline">Klasyfikacja konwersji →</a>
      </div>
    @endif
  </form>
@endsection
