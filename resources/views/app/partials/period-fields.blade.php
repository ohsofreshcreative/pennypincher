{{-- Ukryte pola okresu analizy dla formularzy POST (uruchom audyt/analizę). --}}
@foreach (\App\PennyPincher\Support\AnalysisPeriodRequest::query($period) as $name => $value)
  <input type="hidden" name="{{ $name }}" value="{{ $value }}">
@endforeach
