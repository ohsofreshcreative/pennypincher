{{-- Zakładki kampanii w kontekście (pp2_campaigns): Wyniki | Wyszukiwane hasła | Optymalizacja | Planowanie + przycisk „Edytuj kampanię”. --}}
@props(['project', 'campaignId', 'campaignName', 'active'])

@php
  use App\PennyPincher\Support\AppUrl;

  $tabs = [
    'results' => ['Wyniki', AppUrl::project($project->id, 'campaigns/' . $campaignId)],
    'search-terms' => ['Wyszukiwane hasła', AppUrl::project($project->id, 'search-terms', ['campaign' => $campaignId])],
    'optimization' => ['Optymalizacja', AppUrl::project($project->id, 'campaigns/' . $campaignId . '/optimization')],
    'planning' => ['Planowanie', AppUrl::project($project->id, 'campaigns/' . $campaignId . '/planning')],
  ];
@endphp

<div {{ $attributes->merge(['class' => 'space-y-3']) }}>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <a href="{{ AppUrl::project($project->id, 'campaigns') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-mint hover:underline">
      <x-pp.icon name="chevron-left" :size="16" />Wszystkie kampanie
    </a>
    {{-- Edycja kampanii w Google Ads (status, budżet, ustawienia → szkic w Change Center) dostępna z każdej zakładki. --}}
    <x-pp.button :href="AppUrl::project($project->id, 'management/campaigns/' . $campaignId)" icon="sliders" size="sm">Edytuj kampanię</x-pp.button>
  </div>
  <nav class="flex gap-1 overflow-x-auto border-b border-line" aria-label="Kampania: {{ $campaignName }}">
    @foreach ($tabs as $key => [$label, $url])
      <a href="{{ $url }}" @class(['-mb-px shrink-0 border-b-2 px-4 py-2.5 text-sm font-medium', 'border-mint text-ink' => $active === $key, 'border-transparent text-ink-mute hover:text-ink' => $active !== $key]) @if ($active === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
  </nav>
</div>
