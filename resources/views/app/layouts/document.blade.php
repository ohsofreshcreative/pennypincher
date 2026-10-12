<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ ($pageTitle ?? null) ? $pageTitle . ' – ' : '' }}Penny Pincher</title>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='16' cy='16' r='15' fill='%23a5531f'/%3E%3Ccircle cx='16' cy='16' r='10.5' fill='none' stroke='%23f6e9df' stroke-width='2'/%3E%3C/svg%3E">
  @vite(['resources/css/pp-app.css', 'resources/js/pp-app.js'])
</head>
<body>
  <a href="#pp-main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[60] focus:rounded focus:bg-surface focus:px-3 focus:py-2">Przejdź do treści</a>
  <div id="pp-main" class="contents">
    @yield('body')
  </div>
</body>
</html>
