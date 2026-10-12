@extends('app.layouts.document')

@section('body')
  <main class="grid min-h-screen place-items-center px-4 py-10">
    <div class="w-full max-w-lg">
      <div class="mb-8 flex items-center gap-3">
        @include('app.partials.coin')
        <span class="pp-display text-xl text-ink">Penny Pincher</span>
      </div>
      <div class="pp-card p-6 sm:p-8">
        <p class="pp-num text-sm font-semibold text-copper">Błąd {{ $status }}</p>
        <h1 class="mt-1 text-xl font-semibold text-ink">{{ $title }}</h1>
        <p class="mt-2 text-ink-soft">{{ $message }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
          @if (is_user_logged_in())
            @if ($status !== 403 || ! str_contains($title, 'aplikacji'))
              <x-pp.button :href="\App\PennyPincher\Support\AppUrl::to('projects')" variant="secondary">Wróć do projektów</x-pp.button>
            @endif
            <form method="post" action="{{ \App\PennyPincher\Support\AppUrl::to('logout') }}">
              <x-pp.nonce />
              <x-pp.button variant="ghost" icon="logout">Wyloguj</x-pp.button>
            </form>
          @else
            <x-pp.button :href="\App\PennyPincher\Support\AppUrl::login()" variant="secondary">Zaloguj się</x-pp.button>
          @endif
        </div>
      </div>
    </div>
  </main>
@endsection
