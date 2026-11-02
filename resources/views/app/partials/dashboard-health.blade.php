@php
  use App\PennyPincher\Domain\Audit\Category;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $connection = $health['connection'];
  $status = $connection['status'] ?? null;
  $pacing = $health['pacing'];
  $audit = $health['audit'];
  $fmtPct = fn (?float $v) => $v === null ? '—' : number_format($v, 0, ',', ' ') . ' %';
@endphp

<section aria-label="Stan konta" class="grid gap-4 md:grid-cols-3">
  {{-- Google Ads --}}
  <a href="{{ AppUrl::project($project->id, 'google-ads') }}" class="pp-card block p-5 hover:border-line-strong">
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-meta font-medium text-ink-mute">Google Ads API</h2>
      @if ($connection)
        <x-pp.status-badge :status="$status" />
      @else
        <x-pp.status-badge status="other" label="Nie połączono" />
      @endif
    </div>
    @if ($connection && $connection['customer_id'])
      <p class="mt-2 truncate font-semibold text-ink">{{ $connection['customer_name'] ?: $connection['customer_id'] }}</p>
      <p class="pp-num mt-0.5 text-meta text-ink-mute">
        Ostatnia synchronizacja: {{ $health['lastSync'] ? Format::datetime($health['lastSync']['finished_at']) : 'jeszcze nie' }}
        @if (in_array($health['lastRun']['status'] ?? null, ['queued', 'running'], true)) · trwa pobieranie danych @endif
        @if (($health['lastRun']['status'] ?? null) === 'failed') · <span class="text-danger">ostatnia nie powiodła się</span> @endif
      </p>
    @else
      <p class="mt-2 text-sm text-ink-soft">{{ $connection ? 'Wybierz konto reklamowe, aby rozpocząć synchronizację.' : 'Połącz konto, aby automatycznie pobierać dane i ustawienia kampanii.' }}</p>
    @endif
  </a>

  {{-- Audyt --}}
  <a href="{{ AppUrl::project($project->id, 'audits') }}" class="pp-card block p-5 hover:border-line-strong">
    <h2 class="text-meta font-medium text-ink-mute">Audyt konta</h2>
    @if ($audit)
      <div class="mt-2">
        @include('app.partials.audit.score', ['run' => $audit, 'size' => 'sm'])
      </div>
      <p class="mt-2 text-meta text-ink-mute">
        <span @class(['font-semibold', 'text-danger' => $health['auditCritical'] > 0, 'text-ink' => $health['auditCritical'] === 0])>{{ $health['auditCritical'] }}</span> krytycznych problemów · {{ Format::datetime($audit['created_at']) }}
      </p>
    @else
      <p class="mt-2 text-sm text-ink-soft">Audyt nie był jeszcze wykonywany. Otwórz, aby sprawdzić konfigurację, pomiar konwersji i ryzyka.</p>
    @endif
  </a>

  {{-- Tempo budżetu --}}
  @include('app.partials.dashboard-pacing', ['pacing' => $pacing])
</section>

@foreach ($health['dataQuality'] as $finding)
  <x-pp.alert :type="$finding['severity'] === 'critical' ? 'error' : 'warning'" :title="$finding['title']">
    {{ $finding['recommendation'] }}
    <a href="{{ AppUrl::project($project->id, 'audits/' . $finding['audit_run_id']) }}#finding-{{ $finding['id'] }}" class="font-semibold underline">Szczegóły</a>
  </x-pp.alert>
@endforeach

@if ($health['auditTop'] !== [])
  <section class="pp-card min-w-0" aria-labelledby="pp-audit-top">
    <div class="pp-card-header">
      <h2 id="pp-audit-top" class="pp-card-title">Najważniejsze wyniki audytu</h2>
      <a href="{{ AppUrl::project($project->id, 'audits') }}" class="text-sm font-semibold text-mint hover:underline">Pełny audyt</a>
    </div>
    <ul class="divide-y divide-line">
      @foreach ($health['auditTop'] as $finding)
        <li>
          <a href="{{ AppUrl::project($project->id, 'audits/' . $finding['audit_run_id']) }}#finding-{{ $finding['id'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 hover:bg-ink/[0.03]">
            <x-pp.status-badge :status="$finding['severity']" />
            <span class="min-w-0 flex-1 text-sm font-medium text-ink">{{ $finding['title'] }}</span>
            <span class="text-caption text-ink-mute">{{ Category::LABELS[$finding['category']] ?? '' }}</span>
          </a>
        </li>
      @endforeach
    </ul>
  </section>
@endif
