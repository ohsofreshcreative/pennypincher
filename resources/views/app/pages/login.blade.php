@extends('app.layouts.document')

@section('body')
  <main class="grid min-h-screen place-items-center px-4 py-10">
    <div class="w-full max-w-[400px]">
      <div class="mb-8 flex items-center gap-3">
        @include('app.partials.coin')
        <span class="pp-display text-xl text-ink">Penny Pincher</span>
      </div>

      <div class="pp-card p-6 sm:p-8">
        <h1 class="text-xl font-semibold text-ink">Zaloguj się</h1>
        <p class="mt-1 text-sm text-ink-soft">Użyj konta WordPress przypisanego do Twojej organizacji.</p>

        @if ($error)
          <x-pp.alert type="error" class="mt-5">{{ $error }}</x-pp.alert>
        @endif

        <form method="post" action="{{ \App\PennyPincher\Support\AppUrl::to('login') }}" class="mt-6 space-y-4">
          <x-pp.nonce />
          <input type="hidden" name="redirect_to" value="{{ $redirectTo }}">

          <div>
            <label for="pp-log" class="pp-label">Login lub e-mail</label>
            <input id="pp-log" name="log" type="text" value="{{ $login }}" class="pp-input" autocomplete="username" required autofocus>
          </div>

          <div>
            <label for="pp-pwd" class="pp-label">Hasło</label>
            <input id="pp-pwd" name="pwd" type="password" class="pp-input" autocomplete="current-password" required>
          </div>

          <label class="flex items-center gap-2 text-sm text-ink-soft">
            <input type="checkbox" name="remember" value="1" class="size-4 rounded border-line-strong accent-mint">
            Zapamiętaj mnie na tym urządzeniu
          </label>

          <x-pp.button class="w-full">Zaloguj się</x-pp.button>
        </form>

        <p class="mt-5 text-sm">
          <a href="{{ wp_lostpassword_url(\App\PennyPincher\Support\AppUrl::to()) }}" class="text-mint underline-offset-2 hover:underline">Nie pamiętasz hasła?</a>
        </p>
      </div>
    </div>
  </main>
@endsection
