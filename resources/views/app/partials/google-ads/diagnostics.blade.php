{{-- Wynik diagnostyki połączenia (GoogleAdsController@diagnose): tylko odczyt, bez tokenów i sekretów. --}}
@php use App\PennyPincher\Support\Format; @endphp
  <div id="pp-diagnostics" class="border-t border-line p-5">
    <h3 class="text-sm font-semibold text-ink">Diagnostyka połączenia <span class="pp-num font-normal text-ink-mute">{{ Format::datetime($diagnostics['at']) }}</span></h3>
    <p class="mt-1 text-caption text-ink-mute">Tylko odczyt. Bez tokenów i sekretów.</p>
    <div class="pp-table-wrap mt-3">
      <table class="pp-table text-meta">
        <thead><tr><th>Zapytanie</th><th>HTTP</th><th>Kod Google</th><th>Wynik</th><th class="pp-col-num">Konta</th></tr></thead>
        <tbody>
          @foreach ($diagnostics['rows'] as $row)
            <tr>
              <td class="pp-num break-all">{{ $row['endpoint'] }}</td>
              <td class="pp-num">{{ $row['status'] ?? '—' }}</td>
              <td class="pp-num {{ $row['code'] ? 'text-danger' : '' }}">{{ $row['code'] ?? '—' }}</td>
              <td class="min-w-[220px]">{{ $row['message'] ?? '—' }}</td>
              <td class="pp-col-num">{{ $row['count'] ?? '—' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
