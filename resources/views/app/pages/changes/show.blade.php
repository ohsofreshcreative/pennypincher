@extends('app.layouts.app')

@php
  use App\PennyPincher\Domain\GoogleAds\Changes\ChangeStatus;
  use App\PennyPincher\Domain\GoogleAds\Changes\OperationCatalog;
  use App\PennyPincher\Services\Changes\ChangeCenterService;
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Format;

  $currency = $project->currency;
  $base = AppUrl::project($project->id, 'changes/' . $set['id']);
  $status = $set['status'];
  $editable = in_array($status, ChangeStatus::EDITABLE, true) && $canPrepare;
  $micros = ['amount_micros', 'cpc_bid_micros', 'target_cpa_micros', 'cpc_bid_ceiling_micros'];
  $fmt = function (string $field, mixed $value) use ($micros, $currency): string {
      if ($value === null || $value === '') {
          return '—';
      }
      if (in_array($field, $micros, true)) {
          return Format::money((int) $value, $currency);
      }
      if (is_bool($value)) {
          return $value ? 'Tak' : 'Nie';
      }
      if (is_array($value)) {
          return implode(' | ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $value));
      }
      $labels = OperationCatalog::STATUSES + OperationCatalog::MATCH_TYPES + OperationCatalog::BIDDING + OperationCatalog::COUNTING + OperationCatalog::CONVERSION_CATEGORIES;
      return $labels[(string) $value] ?? (string) $value;
  };
  // Pola, które można poprawić w szkicu (pozostałe wymagają przygotowania operacji od nowa).
  $editableFields = ['name', 'status', 'amount_micros', 'cpc_bid_micros', 'target_cpa_micros', 'cpc_bid_ceiling_micros', 'text', 'match_type', 'final_url', 'path1', 'path2', 'headlines', 'descriptions', 'default_value', 'counting_type', 'click_through_lookback_window_days', 'view_through_lookback_window_days', 'primary_for_goal', 'always_use_default_value', 'biddable', 'target_search_network', 'target_content_network', 'shared_ack', 'confirm_goal_change'];
  $boolFields = ['primary_for_goal', 'always_use_default_value', 'biddable', 'target_search_network', 'target_content_network', 'shared_ack', 'confirm_goal_change'];
  $selectFields = ['status' => OperationCatalog::STATUSES, 'match_type' => OperationCatalog::MATCH_TYPES, 'counting_type' => OperationCatalog::COUNTING];
  $boolLabels = ['shared_ack' => 'Rozumiem, że budżet jest współdzielony i zmiana dotyczy wszystkich kampanii', 'confirm_goal_change' => 'Rozumiem, że zmiana celu wpływa na automatyczne strategie stawek'];

  $hasBidding = array_filter($operations, fn ($op) => OperationCatalog::affectsBidding($op)) !== [];
  $risk = ChangeStatus::maxRisk(array_column($operations, 'risk'));
  $budgetOps = array_values(array_filter($operations, fn ($op) => $op['type'] === 'campaign_budget.update'));
  $failedOps = array_filter($operations, fn ($op) => in_array($op['status'], [ChangeStatus::OP_FAILED, ChangeStatus::OP_SKIPPED, ChangeStatus::OP_CONFLICT], true));
  $staleOps = array_filter($operations, fn ($op) => OperationCatalog::action($op['type']) !== 'create' && ($op['before_source'] !== 'api' || $op['before_read_at'] === null || $op['before_read_at'] < gmdate('Y-m-d H:i:s', time() - ChangeCenterService::STATE_MAX_AGE_HOURS * HOUR_IN_SECONDS)));
  $sourceUrl = match ($set['source_type']) {
      'recommendation' => AppUrl::project($project->id, 'recommendations'),
      'action_item' => AppUrl::project($project->id, 'action-plan'),
      'plan' => $set['source_id'] ? AppUrl::project($project->id, 'planner/' . $set['source_id']) : null,
      'negative_suggestion' => AppUrl::project($project->id, 'keywords', ['tab' => 'negatives']),
      'conversion_health' => AppUrl::project($project->id, 'conversion-management'),
      'budget_leak' => AppUrl::project($project->id, 'budget-leaks'),
      'rollback' => $set['rollback_of'] ? AppUrl::project($project->id, 'changes/' . $set['rollback_of']) : null,
      default => null,
  };
  $eventLabels = [
      'created' => 'Utworzono szkic', 'operation_added' => 'Dodano operację', 'operation_edited' => 'Zmieniono operację', 'operation_removed' => 'Usunięto operację',
      'state_read' => 'Odczytano stan z Google Ads', 'submitted' => 'Wysłano do przeglądu', 'approved' => 'Zatwierdzono', 'validated' => 'Walidacja zakończona',
      'validation_failed' => 'Walidacja nieudana', 'rejected' => 'Odesłano do poprawek', 'deploy_requested' => 'Uruchomiono wdrożenie (kolejka)',
      'deployed' => 'Zakończono wysyłanie do Google Ads', 'deploy_stopped' => 'Wdrożenie zatrzymane', 'reconciled' => 'Uzgodniono stan',
      'cancelled' => 'Anulowano', 'retry_scheduled' => 'Ponowienie później (limit API)', 'rollback_prepared' => 'Przygotowano cofnięcie',
      'stale' => 'Przerwane zadanie workera', 'deployment_prepared' => 'Przygotowano wdrożenie planu',
  ];
  $attemptKinds = ['api_validation' => 'Walidacja API (bez zapisu)', 'mutate' => 'Wysłanie zmian', 'reconcile' => 'Uzgodnienie stanu', 'verify' => 'Weryfikacja', 'read' => 'Odczyt stanu'];
@endphp

@section('content')
  <nav class="text-[13px] text-ink-mute" aria-label="Okruszki"><a class="underline" href="{{ AppUrl::project($project->id, 'changes') }}">Change Center</a> / #{{ $set['id'] }}</nav>

  <section class="pp-card p-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="min-w-0 max-w-3xl">
        <h2 class="text-lg font-semibold break-words text-ink">{{ $set['title'] }}</h2>
        <p class="mt-1 flex flex-wrap items-center gap-2 text-[13px]">
          <span class="pp-badge pp-badge-{{ ChangeStatus::TONES[$status] ?? 'neutral' }}">{{ ChangeStatus::LABELS[$status] ?? $status }}</span>
          <span class="pp-badge pp-badge-{{ $risk === 'high' ? 'danger' : ($risk === 'medium' ? 'warn' : 'neutral') }}">Ryzyko: {{ ChangeStatus::RISKS[$risk] }}</span>
          <span class="text-ink-mute">wersja {{ $set['version'] }}@if ($set['approved_version']) · zatwierdzona wersja {{ $set['approved_version'] }}@endif</span>
        </p>
        <p class="mt-2 text-[13px] leading-6 text-ink-soft">{{ ChangeStatus::EXPLANATIONS[$status] ?? '' }}</p>
        @if ($set['description'])<p class="mt-1 text-[13px] leading-6 whitespace-pre-line text-ink-soft">{{ $set['description'] }}</p>@endif
      </div>
      <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[13px]">
        <dt class="text-ink-mute">Źródło</dt>
        <dd class="min-w-0 break-words">@if ($sourceUrl)<a class="underline" href="{{ $sourceUrl }}">{{ ChangeCenterService::SOURCES[$set['source_type']] ?? $set['source_type'] }}</a>@else{{ ChangeCenterService::SOURCES[$set['source_type']] ?? $set['source_type'] }}@endif @if ($set['source_label'])<span class="text-ink-mute">· {{ $set['source_label'] }}</span>@endif</dd>
        <dt class="text-ink-mute">Konto</dt><dd class="pp-num">{{ $set['customer_id'] ?: '—' }}</dd>
        <dt class="text-ink-mute">Utworzono</dt><dd>{{ Format::datetime($set['created_at']) }}</dd>
        @if ($set['approved_at'])<dt class="text-ink-mute">Zatwierdzono</dt><dd>{{ Format::datetime($set['approved_at']) }}</dd>@endif
        @if ($set['deploy_requested_at'])<dt class="text-ink-mute">Wdrożenie zlecono</dt><dd>{{ Format::datetime($set['deploy_requested_at']) }}</dd>@endif
        @if ($set['deploy_finished_at'])<dt class="text-ink-mute">Zakończono</dt><dd>{{ Format::datetime($set['deploy_finished_at']) }}</dd>@endif
        @if ($set['verified_at'])<dt class="text-ink-mute">Zweryfikowano</dt><dd>{{ Format::datetime($set['verified_at']) }}</dd>@endif
      </dl>
    </div>
  </section>

  @include('app.partials.changes.mode-banner')

  @if ($set['last_error'])
    <x-pp.alert type="error" title="Ostatni błąd">{{ $set['last_error'] }}</x-pp.alert>
  @endif

  @foreach ($budgetOps as $op)
    @php
      $before = isset($op['before_state']['amount_micros']) ? (int) $op['before_state']['amount_micros'] : null;
      $after = isset($op['payload']['amount_micros']) ? (int) $op['payload']['amount_micros'] : null;
      $delta = $before !== null && $after !== null ? $after - $before : null;
      $shared = array_values((array) ($op['before_state']['campaigns'] ?? []));
    @endphp
    <section class="pp-card p-5" aria-label="Wpływ na budżet">
      <h3 class="pp-card-title">Wpływ na budżet</h3>
      <dl class="mt-3 grid gap-3 text-[13px] sm:grid-cols-2 xl:grid-cols-4">
        <div><dt class="text-ink-mute">Obecnie (dziennie)</dt><dd class="pp-num text-base font-semibold">{{ $before !== null ? Format::money($before, $currency) : 'nie odczytano' }}</dd></div>
        <div><dt class="text-ink-mute">Proponowane (dziennie)</dt><dd class="pp-num text-base font-semibold">{{ $after !== null ? Format::money($after, $currency) : '—' }}</dd></div>
        <div><dt class="text-ink-mute">Różnica</dt><dd class="pp-num text-base font-semibold {{ ($delta ?? 0) > 0 ? 'text-warn' : '' }}">@if ($delta !== null){{ $delta > 0 ? '+' : '' }}{{ Format::money($delta, $currency) }}@if ($before) ({{ $delta > 0 ? '+' : '' }}{{ Format::number($delta / $before * 100, 0) }}%)@endif @else — @endif</dd></div>
        <div><dt class="text-ink-mute">Szacunek miesięczny (×30,4)</dt><dd class="pp-num text-base font-semibold">@if ($after !== null){{ Format::money((int) round($after * 30.4), $currency, 0) }}@if ($delta !== null) <span class="block text-[12px] font-normal text-ink-mute">{{ $delta > 0 ? '+' : '' }}{{ Format::money((int) round($delta * 30.4), $currency, 0) }} / mies.</span>@endif @else — @endif</dd></div>
      </dl>
      @if (($delta ?? 0) > 0)
        <x-pp.alert type="warning" class="mt-3">Zwiększenie budżetu zwiększy wydatki. Google Ads może w pojedyncze dni wydać do 2× budżetu dziennego; miesięczny limit to ok. 30,4 × budżet dzienny. Budżet planistyczny w Penny Pincher nie jest gwarantowanym limitem wydatków.</x-pp.alert>
      @endif
      @if (count($shared) > 1 || ($op['before_state']['explicitly_shared'] ?? false))
        <x-pp.alert type="warning" class="mt-3" title="Budżet współdzielony">
          Ten budżet wykorzystuje {{ count($shared) }} {{ Format::plural(count($shared), 'kampania', 'kampanie', 'kampanii') }} – zmiana dotyczy wszystkich:
          {{ implode(', ', array_map(fn ($c) => $c['name'] . ' (' . (OperationCatalog::STATUSES[$c['status']] ?? $c['status']) . ')', $shared)) }}.
        </x-pp.alert>
      @endif
    </section>
  @endforeach

  {{-- Walidacja --}}
  @if (! $local['ok'] || $local['warnings'] !== [] || $staleOps !== [] || ! empty($set['validation']['api']))
    <section class="pp-card p-5 text-[13px] leading-6" aria-label="Walidacja">
      <h3 class="pp-card-title">Walidacja</h3>
      <ul class="mt-2 space-y-1">
        <li><span class="pp-badge pp-badge-{{ $local['ok'] ? 'ok' : 'danger' }}">Lokalna: {{ $local['ok'] ? 'bez błędów' : 'są błędy' }}</span> <span class="text-ink-mute">– sprawdzenie w Penny Pincher (to nie jest potwierdzenie Google Ads).</span></li>
        @if (! empty($set['validation']['api']))
          @php $api = $set['validation']['api']; @endphp
          <li><span class="pp-badge pp-badge-{{ $api['status'] === 'ok' ? 'ok' : ($api['status'] === 'error' ? 'danger' : 'neutral') }}">Google Ads API: {{ $api['status'] === 'ok' ? 'potwierdzone (validateOnly)' : ($api['status'] === 'error' ? 'odrzucone' : 'pominięte') }}</span> <span class="text-ink-mute">{{ $api['message'] }} · {{ Format::datetime($api['at'] ?? null) }}</span></li>
        @endif
      </ul>
      @foreach ($local['set_errors'] as $error)
        <p class="mt-1 text-danger">{{ $error }}</p>
      @endforeach
      @if ($staleOps !== [] && in_array($status, ChangeStatus::EDITABLE, true))
        <p class="mt-2 text-warn">{{ count($staleOps) }} {{ Format::plural(count($staleOps), 'operacja nie ma', 'operacje nie mają', 'operacji nie ma') }} aktualnego stanu z Google Ads (maks. {{ ChangeCenterService::STATE_MAX_AGE_HOURS }} h). Przed zatwierdzeniem kliknij „Odczytaj stan z Google Ads”.</p>
      @endif
    </section>
  @endif

  {{-- Akcje zestawu --}}
  <section class="pp-card p-5" aria-labelledby="set-actions">
    <h3 id="set-actions" class="pp-card-title">Co dalej</h3>
    <div class="mt-3 flex flex-wrap items-start gap-3">
      @if ($editable && $operations !== [])
        <form method="post" action="{{ $base }}/read-state" data-busy>
          <x-pp.nonce />
          <x-pp.button variant="secondary" icon="refresh" data-busy-label="Odczyt…">Odczytaj stan z Google Ads</x-pp.button>
        </form>
      @endif
      @if ($status === ChangeStatus::DRAFT && $canPrepare && $operations !== [])
        <form method="post" action="{{ $base }}/submit">
          <x-pp.nonce />
          <x-pp.button icon="check" :disabled="! $local['ok']">Wyślij do przeglądu</x-pp.button>
        </form>
      @endif
      @if (in_array($status, ChangeStatus::CANCELLABLE, true) && $canPrepare)
        <form method="post" action="{{ $base }}/cancel" data-confirm="Anulować ten zestaw zmian? Nic nie zostanie wysłane do Google Ads.">
          <x-pp.nonce />
          <x-pp.button variant="ghost" icon="close">Anuluj zestaw</x-pp.button>
        </form>
      @endif
    </div>

    @if ($status === ChangeStatus::REVIEW)
      @if ($canApprove)
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
          <form method="post" action="{{ $base }}/approve" class="space-y-3 rounded-[var(--radius-card)] border border-line p-4 text-[13px]" data-busy>
            <x-pp.nonce />
            <p class="font-semibold text-ink">Zatwierdź wersję {{ $set['version'] }}</p>
            <p class="text-ink-soft">Zatwierdzasz propozycję – to <strong>nie</strong> wdraża zmian. Po zatwierdzeniu Penny Pincher sprawdzi operacje lokalnie{{ $apiValidation ? ' i w Google Ads API w trybie walidacji (bez zapisu)' : ' (walidacja w Google Ads API jest wyłączona na serwerze)' }}. Każda późniejsza edycja unieważnia zatwierdzenie.</p>
            @if ($risk === 'high')
              <label class="flex items-start gap-2"><input type="checkbox" name="confirm_high_risk" value="1" class="mt-1" required> <span>Zestaw zawiera zmiany <strong>wysokiego ryzyka</strong> – znam ich skutki dla wydatków i ruchu.</span></label>
            @endif
            @if ($hasBidding)
              <label class="flex items-start gap-2"><input type="checkbox" name="confirm_bidding" value="1" class="mt-1" required> <span>Zestaw zmienia <strong>cele konwersji lub strategię stawek</strong>. Rozumiem, że Smart Bidding może przez kilka tygodni działać mniej stabilnie.</span></label>
            @endif
            <x-pp.button icon="check" data-busy-label="Zatwierdzanie…" :disabled="! $local['ok']">Zatwierdź (bez wdrażania)</x-pp.button>
          </form>
          <form method="post" action="{{ $base }}/reject" class="space-y-3 rounded-[var(--radius-card)] border border-line p-4 text-[13px]">
            <x-pp.nonce />
            <x-pp.field name="note" label="Uwagi do poprawek" type="textarea" rows="3" />
            <x-pp.button variant="secondary">Odeślij do poprawek</x-pp.button>
          </form>
        </div>
      @else
        <p class="mt-3 text-[13px] text-ink-soft">Zestaw czeka na zatwierdzenie przez administratora organizacji.</p>
      @endif
    @endif

    @if ($status === ChangeStatus::READY)
      <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="rounded-[var(--radius-card)] border border-line p-4 text-[13px] leading-6">
          <p class="font-semibold text-ink">Wdrożenie do Google Ads</p>
          @if ($deployBlocker)
            <p class="mt-1 text-ink-soft"><x-pp.icon name="shield" :size="16" class="inline" /> {{ $deployBlocker }}</p>
          @elseif (! $hashOk)
            <p class="mt-1 text-danger">Operacje różnią się od zatwierdzonej wersji – zestaw trzeba zatwierdzić ponownie.</p>
          @else
            <form method="post" action="{{ $base }}/deploy" class="mt-2 space-y-3" data-busy>
              <x-pp.nonce />
              <input type="hidden" name="deploy_key" value="{{ $deployKey }}">
              <p class="text-ink-soft">Konto <strong class="pp-num">{{ $set['customer_id'] }}</strong>, {{ count($operations) }} {{ Format::plural(count($operations), 'operacja', 'operacje', 'operacji') }}. Przed wysłaniem worker ponownie odczyta stan konta i przerwie wdrożenie, jeśli coś zmieniło się poza Penny Pincher.</p>
              @if ($set['risk_level'] === 'high')
                <x-pp.field name="confirmation" label="Wpisz „{{ ChangeCenterService::CONFIRM_WORD }}”, aby potwierdzić zmiany wysokiego ryzyka" required autocomplete="off" />
              @else
                <label class="flex items-start gap-2"><input type="checkbox" name="confirmation" value="tak" class="mt-1" required> <span>Potwierdzam wysłanie tych zmian do Google Ads.</span></label>
              @endif
              <x-pp.button variant="danger" icon="external" data-busy-label="Dodawanie do kolejki…">Wdróż do Google Ads</x-pp.button>
            </form>
          @endif
        </div>
        @if ($canApprove)
          <form method="post" action="{{ $base }}/reject" class="space-y-3 rounded-[var(--radius-card)] border border-line p-4 text-[13px]">
            <x-pp.nonce />
            <x-pp.field name="note" label="Cofnij zatwierdzenie (wróci do szkicu)" type="textarea" rows="2" />
            <x-pp.button variant="secondary">Cofnij zatwierdzenie</x-pp.button>
          </form>
        @endif
      </div>
    @endif

    @if (in_array($status, [ChangeStatus::DEPLOYING, ChangeStatus::VALIDATING], true))
      <x-pp.alert type="info" class="mt-3">{{ $status === ChangeStatus::DEPLOYING ? 'Zestaw jest w kolejce lub w trakcie wysyłania. To jeszcze nie jest potwierdzenie wdrożenia – odśwież stronę za chwilę.' : 'Trwa walidacja.' }}@if ($set['next_attempt_at']) Kolejna próba: {{ Format::datetime($set['next_attempt_at']) }}.@endif</x-pp.alert>
    @endif

    @if ($status === ChangeStatus::RECONCILE)
      <div class="mt-3 space-y-3">
        <x-pp.alert type="error" title="Nie wiadomo, czy zmiany zostały zapisane">Google Ads nie odpowiedział jednoznacznie. Penny Pincher nie wyśle operacji ponownie, dopóki nie odczyta rzeczywistego stanu konta – inaczej mogłyby powstać duplikaty.</x-pp.alert>
        @if ($canApprove)
          <form method="post" action="{{ $base }}/reconcile" data-busy>
            <x-pp.nonce />
            <x-pp.button icon="refresh" data-busy-label="Odczyt…">Uzgodnij stan (odczyt z Google Ads)</x-pp.button>
          </form>
        @endif
      </div>
    @endif

    @if (in_array($status, [ChangeStatus::APPLIED, ChangeStatus::PARTIAL, ChangeStatus::FAILED], true) && $canPrepare)
      <div class="mt-3 flex flex-wrap gap-3">
        @if ($failedOps !== [])
          <form method="post" action="{{ $base }}/retry"><x-pp.nonce /><x-pp.button variant="secondary" icon="refresh">Przygotuj ponowienie nieudanych operacji</x-pp.button></form>
        @endif
        @if ($rollbackable)
          <form method="post" action="{{ $base }}/rollback"><x-pp.nonce /><x-pp.button variant="secondary" icon="undo">Przygotuj cofnięcie</x-pp.button></form>
        @endif
      </div>
      @if ($rollbackable)
        <p class="mt-2 text-[12.5px] text-ink-mute">Cofnięcie to nowy szkic w Change Center (z przeglądem i zatwierdzeniem). Nie każdą zmianę da się cofnąć w pełni – np. usuniętego słowa nie da się przywrócić z historią statystyk, a utworzone zasoby są wstrzymywane lub usuwane.</p>
      @endif
    @endif
  </section>

  {{-- Operacje --}}
  <section class="pp-card min-w-0" aria-label="Operacje">
    <div class="pp-card-header">
      <h3 class="pp-card-title">Operacje ({{ count($operations) }})</h3>
      @if ($editable)
        <a class="pp-btn pp-btn-ghost pp-btn-sm" href="{{ AppUrl::project($project->id, 'management') }}"><x-pp.icon name="plus" :size="16" /> Dodaj w Google Ads Management</a>
      @endif
    </div>
    @if ($operations === [])
      <x-pp.empty-state title="Pusty zestaw" icon="changes">Dodaj operacje w Google Ads Management, Conversion Management albo z rekomendacji.</x-pp.empty-state>
    @else
      <ol class="divide-y divide-line">
        @foreach ($operations as $op)
          @php
            $diff = OperationCatalog::diff($op);
            $errors = $local['errors'][$op['id']] ?? [];
            $warnings = array_values(array_unique(array_merge($op['warnings'], $local['warnings'][$op['id']] ?? [])));
            $fields = array_values(array_filter($editableFields, fn ($f) => array_key_exists($f, $op['payload'])));
          @endphp
          <li id="op-{{ $op['id'] }}" class="px-5 py-4">
            <div class="flex flex-wrap items-start gap-3">
              <span class="pp-num mt-0.5 text-[12px] text-ink-mute">{{ $loop->iteration }}.</span>
              <div class="min-w-0 flex-1">
                <p class="flex flex-wrap items-center gap-2">
                  <span class="font-semibold break-words text-ink">{{ $op['label'] }}</span>
                  <span class="pp-badge pp-badge-{{ ChangeStatus::OP_TONES[$op['status']] ?? 'neutral' }}">{{ ChangeStatus::OP_LABELS[$op['status']] ?? $op['status'] }}</span>
                  @if ($op['risk'] !== 'low')<span class="pp-badge pp-badge-{{ $op['risk'] === 'high' ? 'danger' : 'warn' }}">{{ ChangeStatus::RISKS[$op['risk']] }} ryzyko</span>@endif
                  @if (OperationCatalog::affectsBidding($op))<span class="pp-badge pp-badge-warn">Wpływa na Smart Bidding</span>@endif
                </p>
                <p class="mt-0.5 text-[12.5px] break-all text-ink-mute">
                  {{ OperationCatalog::TYPES[$op['type']]['label'] ?? $op['type'] }}
                  @if ($op['resource_name']) · {{ $op['resource_name'] }}@endif
                  @if ($op['atomic_group']) · grupa atomowa „{{ $op['atomic_group'] }}” (wszystko albo nic)@endif
                </p>
                <p class="mt-0.5 text-[12.5px] text-ink-mute">
                  Stan „przed”: @if (OperationCatalog::action($op['type']) === 'create') nowy zasób @elseif ($op['before_source'] === 'api') odczyt z Google Ads {{ Format::datetime($op['before_read_at']) }} @else z synchronizacji – wymaga odczytu z Google Ads @endif
                </p>

                @if ($diff !== [])
                  <div class="pp-table-wrap mt-2 rounded-[var(--radius-card)] border border-line">
                    <table class="pp-table text-[13px]">
                      <thead><tr><th>Pole</th><th>Przed</th><th>Po</th></tr></thead>
                      <tbody>
                        @foreach ($diff as $row)
                          <tr>
                            <td class="whitespace-nowrap">{{ $row['label'] }}</td>
                            <td class="break-words text-ink-mute">{{ $fmt($row['field'], $row['before']) }}</td>
                            <td class="break-words font-semibold {{ $row['before'] !== null && $row['before'] != $row['after'] ? 'text-ink' : '' }}">{{ $fmt($row['field'], $row['after']) }}</td>
                          </tr>
                        @endforeach
                      </tbody>
                    </table>
                  </div>
                @endif

                @foreach ($errors as $error)<p class="mt-1 text-[13px] text-danger">{{ $error }}</p>@endforeach
                @foreach ($warnings as $warning)<p class="mt-1 text-[13px] text-warn">{{ $warning }}</p>@endforeach
                @if ($op['error_message'])<p class="mt-1 text-[13px] text-danger">Google Ads: {{ $op['error_message'] }}@if ($op['error_code']) <span class="text-ink-mute">({{ $op['error_code'] }})</span>@endif</p>@endif
                @if ($op['result_resource_name'])<p class="mt-1 text-[12.5px] break-all text-ink-mute">Utworzony zasób: {{ $op['result_resource_name'] }}</p>@endif
                @if ($op['applied_at'])<p class="mt-1 text-[12.5px] text-ink-mute">Przyjęte przez API: {{ Format::datetime($op['applied_at']) }}@if ($op['verified_at']) · zweryfikowane odczytem: {{ Format::datetime($op['verified_at']) }}@endif</p>@endif
                @if (! empty($op['verification']['diff']))
                  <p class="mt-1 text-[13px] text-warn">Weryfikacja: stan na koncie różni się od oczekiwanego –
                    {{ implode('; ', array_map(fn ($d) => (OperationCatalog::FIELD_LABELS[$d['field']] ?? $d['field']) . ': oczekiwano ' . $fmt($d['field'], $d['expected']) . ', jest ' . $fmt($d['field'], $d['actual']), $op['verification']['diff'])) }}</p>
                @elseif (! empty($op['verification']['error']))
                  <p class="mt-1 text-[13px] text-warn">Weryfikacja niemożliwa: {{ $op['verification']['error'] }}</p>
                @endif

                @if ($editable && ($fields !== []))
                  <details class="mt-2">
                    <summary class="cursor-pointer text-[13px] font-semibold text-mint">Edytuj operację</summary>
                    <form method="post" action="{{ $base }}/operations/{{ $op['id'] }}" class="mt-2 grid gap-3 sm:grid-cols-2">
                      <x-pp.nonce />
                      @foreach ($fields as $field)
                        @php $value = $op['payload'][$field]; $label = OperationCatalog::FIELD_LABELS[$field] ?? ($boolLabels[$field] ?? str_replace('_', ' ', $field)); @endphp
                        @if (in_array($field, $boolFields, true))
                          <label class="flex items-start gap-2 text-[13px] sm:col-span-2">
                            <input type="hidden" name="payload[{{ $field }}]" value="0">
                            <input type="checkbox" name="payload[{{ $field }}]" value="1" class="mt-1" @checked($value)> <span>{{ $label }}</span>
                          </label>
                        @elseif (isset($selectFields[$field]))
                          <x-pp.field :name="'payload[' . $field . ']'" :label="$label" :options="$selectFields[$field]" :value="$value" required />
                        @elseif (in_array($field, ['headlines', 'descriptions'], true))
                          <x-pp.field :name="'payload[' . $field . ']'" :label="($field === 'headlines' ? 'Nagłówki (maks. 30 znaków, 3–15)' : 'Opisy (maks. 90 znaków, 2–4)') . ' – jeden w wierszu'" type="textarea" rows="5" :value="implode(PHP_EOL, (array) $value)" class="sm:col-span-2" required />
                        @elseif (in_array($field, $micros, true))
                          <x-pp.field :name="'payload[' . $field . ']'" :label="($label === $field ? str_replace('_', ' ', $field) : $label) . ' (' . $currency . ')'" inputmode="decimal" :value="Format::moneyInput($value === null ? null : (int) $value)" />
                        @else
                          <x-pp.field :name="'payload[' . $field . ']'" :label="$label" :value="is_scalar($value) ? (string) $value : ''" />
                        @endif
                      @endforeach
                      <div class="flex flex-wrap gap-2 sm:col-span-2">
                        <x-pp.button variant="secondary" size="sm">Zapisz operację</x-pp.button>
                      </div>
                    </form>
                  </details>
                @endif
              </div>
              @if ($editable)
                <form method="post" action="{{ $base }}/operations/{{ $op['id'] }}/delete" data-confirm="Usunąć tę operację z zestawu?">
                  <x-pp.nonce />
                  <button type="submit" class="pp-btn pp-btn-ghost pp-btn-sm" aria-label="Usuń operację"><x-pp.icon name="close" :size="16" /></button>
                </form>
              @endif
            </div>
          </li>
        @endforeach
      </ol>
    @endif
  </section>

  {{-- Efekty --}}
  @if (in_array($status, ChangeStatus::DEPLOYED, true))
    <section class="pp-card p-5 text-[13px] leading-6" aria-labelledby="effects">
      <h3 id="effects" class="pp-card-title">Efekty zmiany</h3>
      <p class="mt-1 text-ink-soft">{{ $effects['message'] }}</p>
      @if ($effects['campaigns'] !== [])<p class="mt-1 text-ink-mute">Kampanie: {{ implode(', ', $effects['campaigns']) }}</p>@endif
      @if ($effects['available'])
        <div class="pp-table-wrap mt-3 rounded-[var(--radius-card)] border border-line">
          <table class="pp-table">
            <thead><tr><th>Okres</th><th class="pp-col-num">Kliknięcia</th><th class="pp-col-num">Koszt</th><th class="pp-col-num">Konwersje</th><th class="pp-col-num">Koszt / konwersję</th></tr></thead>
            <tbody>
              @foreach (['before' => 'Przed', 'after' => 'Po'] as $key => $label)
                @php $m = $effects[$key]; @endphp
                <tr>
                  <td>{{ $label }} <span class="text-ink-mute">({{ Format::period($m['from'], $m['to']) }})</span></td>
                  <td class="pp-col-num">{{ Format::number($m['clicks']) }}</td>
                  <td class="pp-col-num">{{ Format::money((int) $m['cost_micros'], $currency) }}</td>
                  <td class="pp-col-num">{{ Format::conversions($m['conversions']) }}</td>
                  <td class="pp-col-num">{{ (float) $m['conversions'] > 0 ? Format::money((int) round($m['cost_micros'] / $m['conversions']), $currency) : '—' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <ul class="mt-2 list-disc pl-5 text-ink-mute">
          @foreach ($effects['notes'] as $note)<li>{{ $note }}</li>@endforeach
        </ul>
        @if ($effects['other_changes'] !== [])
          <p class="mt-1 text-ink-mute">Inne wdrożenia w tym okresie:
            @foreach ($effects['other_changes'] as $other)<a class="underline" href="{{ AppUrl::project($project->id, 'changes/' . $other['id']) }}">#{{ $other['id'] }}</a>@if (! $loop->last), @endif @endforeach
          </p>
        @endif
      @endif
      <p class="mt-2 text-ink-mute">Penny Pincher nie oznacza automatycznie rekomendacji jako skutecznej – ocenę zostawiamy Tobie.</p>
    </section>
  @endif

  {{-- Historia --}}
  <div class="grid gap-6 xl:grid-cols-2">
    <section class="pp-card min-w-0" aria-labelledby="history">
      <div class="pp-card-header"><h3 id="history" class="pp-card-title">Historia</h3></div>
      @if ($events === [])
        <p class="px-5 py-4 text-[13px] text-ink-mute">Brak zdarzeń.</p>
      @else
        <ol class="divide-y divide-line text-[13px]">
          @foreach ($events as $event)
            <li class="px-5 py-3">
              <p><span class="font-semibold text-ink">{{ $eventLabels[$event['event']] ?? $event['event'] }}</span>
                @if ($event['to_status'])<span class="text-ink-mute">→ {{ ChangeStatus::LABELS[$event['to_status']] ?? $event['to_status'] }}</span>@endif</p>
              <p class="text-ink-mute">{{ Format::datetime($event['created_at']) }} · {{ $event['user_name'] ?: 'system (worker)' }}</p>
              @if ($event['note'])<p class="mt-0.5 break-words text-ink-soft">{{ $event['note'] }}</p>@endif
            </li>
          @endforeach
        </ol>
      @endif
    </section>
    <section class="pp-card min-w-0" aria-labelledby="attempts">
      <div class="pp-card-header"><h3 id="attempts" class="pp-card-title">Kontakt z Google Ads API</h3></div>
      @if ($attempts === [])
        <p class="px-5 py-4 text-[13px] text-ink-mute">Ten zestaw nie kontaktował się jeszcze z Google Ads API (poza odczytami stanu).</p>
      @else
        <ol class="divide-y divide-line text-[13px]">
          @foreach ($attempts as $attempt)
            <li class="px-5 py-3">
              <p><span class="font-semibold text-ink">{{ $attemptKinds[$attempt['kind']] ?? $attempt['kind'] }}</span>
                <span class="pp-badge pp-badge-{{ $attempt['outcome'] === 'ok' ? 'ok' : ($attempt['outcome'] === 'error' ? 'danger' : 'warn') }}">{{ $attempt['outcome'] }}</span></p>
              <p class="break-all text-ink-mute">{{ Format::datetime($attempt['created_at']) }}@if ($attempt['http_status']) · HTTP {{ $attempt['http_status'] }}@endif @if ($attempt['request_id']) · request-id {{ $attempt['request_id'] }}@endif @if ($attempt['error_code']) · {{ $attempt['error_code'] }}@endif</p>
              @if ($attempt['message'])<p class="mt-0.5 break-words text-ink-soft">{{ $attempt['message'] }}</p>@endif
            </li>
          @endforeach
        </ol>
      @endif
    </section>
  </div>
@endsection
