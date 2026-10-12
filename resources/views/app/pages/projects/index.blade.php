@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
  use App\PennyPincher\Domain\Access\Role;

  $section = 'projects';
@endphp

@section('content')
  <div class="flex flex-wrap items-center gap-3">
    @if ($canCreateOrganization)
      <x-pp.button variant="secondary" icon="plus" type="button" x-data x-on:click="$dispatch('open-modal', 'pp-new-organization')">Nowa organizacja</x-pp.button>
      <form method="post" action="{{ AppUrl::to('projects/demo') }}" data-confirm="Utworzyć projekt demonstracyjny z fikcyjnymi danymi? Powstanie w osobnej organizacji „Demo”." data-busy>
        <x-pp.nonce />
        <x-pp.button variant="ghost" data-busy-label="Tworzenie projektu demo…">Utwórz projekt demo</x-pp.button>
      </form>
    @endif
  </div>

  @forelse ($groups as $group)
    @php
      $organization = $group['organization'];
      $role = $group['role'];
    @endphp
    <section class="pp-card min-w-0" aria-labelledby="org-{{ $organization['id'] }}">
      <div class="pp-card-header">
        <div class="flex min-w-0 items-center gap-3">
          <h2 id="org-{{ $organization['id'] }}" class="pp-card-title truncate">{{ $organization['name'] }}</h2>
          <span class="pp-badge pp-badge-neutral">{{ $role?->label() }}</span>
        </div>
        <div class="flex flex-wrap gap-2">
          <x-pp.button variant="ghost" size="sm" icon="users" :href="AppUrl::to('organizations/' . $organization['id'] . '/members')">Członkowie</x-pp.button>
          @if ($role && $role->atLeast(Role::Admin))
            <x-pp.button variant="secondary" size="sm" icon="plus" :href="AppUrl::to('projects/new', ['organization' => $organization['id']])">Nowy projekt</x-pp.button>
          @endif
        </div>
      </div>

      @if ($group['projects'] === [])
        <p class="px-5 py-6 text-sm text-ink-soft">Ta organizacja nie ma jeszcze projektów.</p>
      @else
        <ul class="divide-y divide-line">
          @foreach ($group['projects'] as $item)
            <li class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 px-5 py-4">
              <div class="min-w-0">
                <a href="{{ AppUrl::project($item->id) }}" class="flex items-center gap-2 font-semibold text-ink hover:text-mint">
                  <span class="truncate">{{ $item->name }}</span>
                  @if ($item->isDemo)<span class="pp-badge pp-badge-demo">DEMO</span>@endif
                </a>
                <p class="mt-0.5 text-[13px] text-ink-mute">
                  Google Ads, {{ $item->currency }}{{ $item->landingPageUrl ? ', ' . preg_replace('#^https?://#', '', rtrim($item->landingPageUrl, '/')) : '' }}
                </p>
              </div>
              <div class="flex items-center gap-4">
                <span class="pp-num text-sm text-ink-soft">Budżet: {{ Format::money($item->monthlyBudgetMicros, $item->currency, 0) }}</span>
                <x-pp.status-badge :status="$item->status" :label="$item->statusLabel()" />
              </div>
            </li>
          @endforeach
        </ul>
      @endif
    </section>
  @empty
    <section class="pp-card">
      <x-pp.empty-state title="Zacznij od organizacji" icon="users">
        Organizacja grupuje projekty i osoby, które mają do nich dostęp. Każdy projekt to jedno konto reklamowe Google Ads z własnym budżetem i ustawieniami.
        <x-slot:actions>
          @if ($canCreateOrganization)
            <x-pp.button icon="plus" type="button" x-data x-on:click="$dispatch('open-modal', 'pp-new-organization')">Utwórz organizację</x-pp.button>
          @endif
        </x-slot:actions>
      </x-pp.empty-state>
    </section>
  @endforelse

  @if ($canCreateOrganization)
    <x-pp.modal id="pp-new-organization" title="Nowa organizacja">
      <form method="post" action="{{ AppUrl::to('organizations') }}" class="space-y-5">
        <x-pp.nonce />
        <x-pp.field name="organization_name" label="Nazwa organizacji" required maxlength="191" help="Np. nazwa firmy lub klienta agencji. Zostaniesz jej właścicielem." />
        <div class="flex justify-end gap-2">
          <x-pp.button variant="ghost" type="button" x-on:click="open = false">Anuluj</x-pp.button>
          <x-pp.button>Utwórz organizację</x-pp.button>
        </div>
      </form>
    </x-pp.modal>
  @endif
@endsection
