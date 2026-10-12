@extends('layouts.app')

@section('content')
  @include('partials.page-header')

  <section data-gsap-anim="section" class="contact bg-secondary relative py-50">
		<div class="c-main relative z-10 text-center text-white">
		<div class="w-full md:w-1/2 m-auto">
			<h1 data-gsap-element="404" class=" !text-9xl text-s-lighter">404</h1>
			<h2 data-gsap-element="title" class="text-white"> Podana strona nie istnieje</h2>
			<p data-gsap-element="txt"> Niestety strona, którą chcesz odwiedzić nie istnieje. Prawdopodobnie została przeniesiona lub źle wpisałeś adres. Przejdź do strony głównej.</p>
			<a data-gsap-element="btn" class="main-btn m-btn" href="/">Strona główna</a>
		</div>
		</div>

	
	<img class="absolute top-0 left-0" src="/wp-content/uploads/2025/08/hero-shape.svg" alt="" />
</section>
@endsection
