{{-- Nawigacja 2.0 (flaga pp2_navigation): sześć sekcji głównych, podwidoki aktywnej sekcji rozwinięte. Adresy modułów bez zmian. --}}
@php
  use App\PennyPincher\Support\AppUrl;
  use App\PennyPincher\Support\Navigation;

  $activeGroup = Navigation::groupOf($section);
  $itemUrl = fn (array $item) => ! empty($item['global']) ? AppUrl::to($item['path']) : AppUrl::project($project->id, $item['path']);
  $alerts = ($alertCount ?? 0) > 0 ? ($alertCount > 99 ? '99+' : (string) $alertCount) : null;
@endphp

<nav class="flex-1 overflow-y-auto px-3 pb-6" aria-label="Sekcje aplikacji">
  @if ($project)
    <ul class="space-y-0.5">
      @foreach (Navigation::groups() as $group => $definition)
        @php
          $items = $definition['items'];
          $isActive = $activeGroup === $group;
          $single = count($items) === 1;
        @endphp
        <li>
          <a href="{{ $itemUrl(reset($items)) }}" @class(['pp-nav-link', 'text-white' => $isActive])
            @if ($single && $isActive) aria-current="page" @endif>
            <x-pp.icon :name="$definition['icon']" />
            {{ $definition['label'] }}
            @if ($group === 'action-plan' && $alerts && ! $isActive)
              <span class="pp-num ml-auto rounded-full bg-copper px-1.5 text-2xs leading-5 font-semibold text-white" title="Nowe alerty">{{ $alerts }}<span class="sr-only"> nowych alertów</span></span>
            @endif
          </a>

          @if ($isActive && ! $single)
            <ul class="mt-1 mb-1 space-y-0.5" aria-label="{{ $definition['label'] }}">
              @foreach ($items as $key => $item)
                <li>
                  <a href="{{ $itemUrl($item) }}" class="pp-nav-link pl-9 text-sm font-normal" @if ($section === $key) aria-current="page" @endif>
                    {{ $item['label'] }}
                    @if ($key === 'monitoring' && $alerts)
                      <span class="pp-num ml-auto rounded-full bg-copper px-1.5 text-2xs leading-5 font-semibold text-white" title="Nowe alerty">{{ $alerts }}<span class="sr-only"> nowych alertów</span></span>
                    @endif
                  </a>
                </li>
              @endforeach
            </ul>
          @endif
        </li>
      @endforeach
    </ul>
  @else
    <ul class="space-y-0.5">
      <li>
        <a href="{{ AppUrl::to('projects') }}" class="pp-nav-link" @if ($section === 'projects') aria-current="page" @endif>
          <x-pp.icon name="projects" />
          Projekty
        </a>
      </li>
    </ul>
  @endif
</nav>
