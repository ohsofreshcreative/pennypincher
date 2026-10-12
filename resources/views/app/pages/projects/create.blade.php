@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;

  $section = 'projects';
@endphp

@section('content')
  @if ($organizations === [])
    <section class="pp-card">
      <x-pp.empty-state title="Najpierw potrzebna jest organizacja" icon="users">
        Projekty należą do organizacji. Nie masz organizacji, w której możesz tworzyć projekty.
        @if ($canCreateOrganization)
          Utwórz ją na stronie projektów.
        @else
          Poproś właściciela organizacji o rolę administratora.
        @endif
        <x-slot:actions>
          <x-pp.button :href="AppUrl::to('projects')" variant="secondary">Wróć do projektów</x-pp.button>
        </x-slot:actions>
      </x-pp.empty-state>
    </section>
  @else
    @if ($errors)
      <x-pp.alert type="error" title="Nie utworzono projektu">Popraw zaznaczone pola.</x-pp.alert>
    @endif

    <form method="post" action="{{ AppUrl::to('projects') }}" class="pp-card max-w-4xl" novalidate>
      <x-pp.nonce />
      <div class="pp-card-header">
        <h2 class="pp-card-title">Nowy projekt Google Ads</h2>
      </div>
      <div class="space-y-5 p-5 sm:p-6">
        <x-pp.field name="organization_id" label="Organizacja" :options="collect($organizations)->pluck('name', 'id')->all()" :value="$old['organization_id'] ?? ($organizations[0]['id'] ?? '')" :errors="$errors" required class="sm:w-80" />
        @include('app.partials.project-fields', ['model' => null])
      </div>
      <div class="flex flex-wrap gap-3 border-t border-line px-5 py-4 sm:px-6">
        <x-pp.button icon="plus">Utwórz projekt</x-pp.button>
        <x-pp.button :href="AppUrl::to('projects')" variant="ghost">Anuluj</x-pp.button>
      </div>
    </form>
  @endif
@endsection
