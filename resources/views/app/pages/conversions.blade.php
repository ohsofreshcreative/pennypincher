@extends('app.layouts.app')

@section('content')
  @include('app.partials.conversions.summary')

  @include('app.partials.conversions.classification')
@endsection
