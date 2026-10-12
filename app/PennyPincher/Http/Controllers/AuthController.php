<?php

namespace App\PennyPincher\Http\Controllers;

use App\PennyPincher\Http\Responses;
use App\PennyPincher\Support\AppUrl;
use Illuminate\Http\Request;

/**
 * Własny ekran logowania aplikacji. Uwierzytelnianie i sesja to standardowy mechanizm WordPressa
 * (wp_signon + ciasteczko auth), więc działają też wtyczki bezpieczeństwa podpięte pod logowanie.
 */
class AuthController
{
	public function show(Request $request)
	{
		if (is_user_logged_in()) {
			return redirect()->to(AppUrl::safeRedirect($request->query('redirect_to')));
		}

		return $this->form($request);
	}

	public function login(Request $request)
	{
		$login = trim((string) $request->input('log', ''));
		$password = (string) $request->input('pwd', '');

		if ($login === '' || $password === '') {
			return $this->form($request, 'Podaj login lub e-mail i hasło.', 422);
		}

		$user = wp_signon([
			'user_login' => $login,
			'user_password' => $password,
			'remember' => (bool) $request->input('remember'),
		], is_ssl());

		if (is_wp_error($user)) {
			return $this->form($request, 'Nieprawidłowy login lub hasło.', 422);
		}

		wp_set_current_user($user->ID);

		return redirect()->to(AppUrl::safeRedirect($request->input('redirect_to')));
	}

	public function logout()
	{
		wp_logout();

		return redirect()->to(AppUrl::login());
	}

	private function form(Request $request, ?string $error = null, int $status = 200)
	{
		return response(view('app.pages.login', [
			'error' => $error,
			'login' => (string) $request->input('log', ''),
			'redirectTo' => (string) ($request->input('redirect_to') ?? $request->query('redirect_to', '')),
			'pageTitle' => 'Logowanie',
		]), $status)->withHeaders(Responses::securityHeaders());
	}
}
