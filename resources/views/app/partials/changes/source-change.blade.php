{{-- Powiązanie elementu analizy ze zmianą Google Ads.
     Zmienne: $project, $changes (['sets' => [source_id => set], 'canPrepare' => bool]), $sourceId, $action (URL POST), $blocker (powód braku operacji lub null). --}}
@php
  use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
  use App\PennyPincher\Support\AppUrl;
  $linked = $changes['sets'][(int) $sourceId] ?? null;
@endphp
<div class="flex flex-wrap items-center gap-2 text-[12.5px]">
  @if ($linked)
    <a href="{{ AppUrl::project($project->id, 'changes/' . $linked['id']) }}" class="pp-btn pp-btn-secondary pp-btn-sm"><x-pp.icon name="changes" :size="16" /> Zmiana #{{ $linked['id'] }}</a>
    <span class="pp-badge pp-badge-{{ ChangeStatus::TONES[$linked['status']] ?? 'neutral' }}">{{ ChangeStatus::LABELS[$linked['status']] ?? $linked['status'] }}</span>
    @if ($linked['verified_at'])<span class="text-ink-mute">zweryfikowana w Google Ads</span>@elseif ($linked['deploy_finished_at'])<span class="text-ink-mute">przyjęta przez API</span>@endif
  @elseif ($blocker)
    <span class="text-ink-mute"><x-pp.icon name="lock" :size="14" class="inline" /> {{ $blocker }}</span>
  @elseif ($changes['canPrepare'])
    <form method="post" action="{{ $action }}" class="inline" data-busy>
      <x-pp.nonce />
      <x-pp.button variant="secondary" size="sm" icon="changes" data-busy-label="Przygotowywanie…" title="Tworzy szkic w Change Center – nic nie zostanie wysłane do Google Ads bez przeglądu, zatwierdzenia i świadomego wdrożenia.">Przygotuj zmianę</x-pp.button>
    </form>
    <span class="text-ink-mute">szkic w Change Center, bez wysyłania do Google Ads</span>
  @endif
</div>
