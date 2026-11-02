@extends('app.layouts.app')

@section('content')
  @include('app.partials.conversions.cm-intro')

  @include('app.partials.conversions.health')

  @include('app.partials.conversions.actions')

  <div class="grid gap-6 xl:grid-cols-2">
    @include('app.partials.conversions.goals')

    @include('app.partials.conversions.new-action')
  </div>
@endsection
