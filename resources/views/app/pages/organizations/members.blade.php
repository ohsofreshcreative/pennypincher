@extends('app.layouts.app')

@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;
  use App\PennyPincher\Domain\Access\Role;

  $section = 'projects';
@endphp

@section('content')
  <a href="{{ AppUrl::to('projects') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-mint hover:underline">
    <x-pp.icon name="chevron-left" :size="16" />Projekty
  </a>

  @if ($errors)
    <x-pp.alert type="error">{{ implode(' ', $errors) }}</x-pp.alert>
  @endif

  <div class="grid gap-6 xl:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
    <section class="pp-card min-w-0" aria-labelledby="pp-members">
      <div class="pp-card-header">
        <h2 id="pp-members" class="pp-card-title">Członkowie organizacji</h2>
        <p class="text-[13px] text-ink-mute">Twoja rola: {{ $role->label() }}</p>
      </div>
      <div class="pp-table-wrap">
        <table class="pp-table">
          <thead><tr><th>Osoba</th><th>Rola</th><th>Dodano</th>@if ($canManage)<th><span class="sr-only">Akcje</span></th>@endif</tr></thead>
          <tbody>
            @foreach ($members as $member)
              @php $memberRole = Role::tryFrom($member['role']); @endphp
              <tr>
                <td class="max-w-[260px]">
                  <span class="block truncate font-medium">{{ $member['display_name'] ?: $member['user_login'] ?: 'Usunięte konto #' . $member['user_id'] }}</span>
                  <span class="block truncate text-[12.5px] text-ink-mute">{{ $member['user_email'] }}</span>
                </td>
                <td><span class="pp-badge pp-badge-neutral">{{ $memberRole?->label() }}</span></td>
                <td class="pp-num whitespace-nowrap text-ink-soft">{{ Format::datetime($member['created_at']) }}</td>
                @if ($canManage)
                  <td class="text-right">
                    @if ($memberRole !== Role::Owner || $role === Role::Owner)
                      <form method="post" action="{{ AppUrl::to('organizations/' . $organization['id'] . '/members/' . $member['user_id'] . '/remove') }}" data-confirm="Usunąć tę osobę z organizacji? Straci dostęp do wszystkich jej projektów.">
                        <x-pp.nonce />
                        <x-pp.button variant="danger" size="sm">Usuń</x-pp.button>
                      </form>
                    @endif
                  </td>
                @endif
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>

    @if ($canManage)
      <section class="pp-card" aria-labelledby="pp-add-member">
        <div class="pp-card-header">
          <h2 id="pp-add-member" class="pp-card-title">Dodaj osobę lub zmień rolę</h2>
        </div>
        <form method="post" action="{{ AppUrl::to('organizations/' . $organization['id'] . '/members') }}" class="space-y-5 p-5">
          <x-pp.nonce />
          <x-pp.field name="identifier" label="E-mail lub login" :value="$old['identifier'] ?? ''" :errors="$errors" required
            help="Konto musi istnieć w WordPressie. Podanie osoby, która już jest członkiem, zmieni jej rolę." />
          <x-pp.field name="role" label="Rola" :options="$roles" :value="$old['role'] ?? 'member'" :errors="$errors" required />
          <dl class="space-y-1.5 text-[13px] text-ink-soft">
            <div><dt class="inline font-semibold text-ink">Członek</dt> <dd class="inline">przegląda dane i importuje raporty.</dd></div>
            <div><dt class="inline font-semibold text-ink">Administrator</dt> <dd class="inline">dodatkowo tworzy projekty, zmienia ustawienia i zarządza członkami.</dd></div>
            <div><dt class="inline font-semibold text-ink">Właściciel</dt> <dd class="inline">pełny dostęp, w tym nadawanie roli właściciela.</dd></div>
          </dl>
          <x-pp.button>Zapisz</x-pp.button>
        </form>
      </section>
    @endif
  </div>
@endsection
