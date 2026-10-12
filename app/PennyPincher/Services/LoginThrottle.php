<?php

namespace App\PennyPincher\Services;

use WP_Error;
use WP_User;

/**
 * Ograniczenie prób logowania (okno przesuwne, bez blokady na stałe).
 *
 * - para IP + login: 5 nieudanych prób w 15 minut,
 * - samo IP: 20 nieudanych prób w 15 minut (zgadywanie wielu kont z jednego adresu).
 * Po przekroczeniu limitu logowanie jest odrzucane do czasu, aż najstarsza próba wypadnie z okna
 * (maksymalnie 15 minut). Udane logowanie czyści licznik pary IP + login.
 *
 * Działa na filtrze `authenticate`, więc obejmuje /app/login/, wp-login.php i XML-RPC.
 * Limity: filtr `pennypincher/login_throttle/limits`, adres IP: `pennypincher/login_throttle/ip`
 * (np. gdy serwer stoi za zaufanym proxy).
 */
class LoginThrottle
{
	public const ERROR_CODE = 'pp_too_many_attempts';

	public function register(): void
	{
		add_filter('authenticate', [$this, 'guard'], 99, 2);
		add_action('wp_login_failed', [$this, 'recordFailure'], 10, 2);
		add_action('wp_login', [$this, 'recordSuccess'], 10, 2);
	}

	/**
	 * @return array{window: int, per_login: int, per_ip: int}
	 */
	public function limits(): array
	{
		$limits = apply_filters('pennypincher/login_throttle/limits', ['window' => 15 * MINUTE_IN_SECONDS, 'per_login' => 5, 'per_ip' => 20]);

		return [
			'window' => max(60, (int) ($limits['window'] ?? 900)),
			'per_login' => max(1, (int) ($limits['per_login'] ?? 5)),
			'per_ip' => max(1, (int) ($limits['per_ip'] ?? 20)),
		];
	}

	public function guard(mixed $user, mixed $username): mixed
	{
		$login = is_string($username) ? $username : '';

		if ($login === '' && ! $user instanceof WP_User) {
			return $user;
		}

		$retryAfter = $this->retryAfter($login);

		if ($retryAfter > 0) {
			return new WP_Error(self::ERROR_CODE, sprintf(
				'Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za %d min.',
				(int) ceil($retryAfter / MINUTE_IN_SECONDS)
			));
		}

		return $user;
	}

	public function recordFailure(string $username, mixed $error = null): void
	{
		if ($error instanceof WP_Error && $error->get_error_code() === self::ERROR_CODE) {
			return;
		}

		$now = time();

		foreach ([$this->loginKey($username), $this->ipKey()] as $key) {
			$attempts = $this->attempts($key, $now);
			$attempts[] = $now;
			set_transient($key, $attempts, $this->limits()['window']);
		}
	}

	public function recordSuccess(string $username, mixed $user = null): void
	{
		delete_transient($this->loginKey($username));
	}

	/**
	 * Liczba sekund do odblokowania (0 = można się logować).
	 */
	public function retryAfter(string $username): int
	{
		$now = time();
		$limits = $this->limits();
		$wait = 0;

		foreach ([[$this->loginKey($username), $limits['per_login']], [$this->ipKey(), $limits['per_ip']]] as [$key, $max]) {
			$attempts = $this->attempts($key, $now);

			if (count($attempts) >= $max) {
				// Odblokowanie, gdy liczba prób w oknie spadnie poniżej limitu.
				$blocking = $attempts[count($attempts) - $max];
				$wait = max($wait, $blocking + $limits['window'] - $now);
			}
		}

		return max(0, $wait);
	}

	public function clientIp(): string
	{
		$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
		$ip = (string) apply_filters('pennypincher/login_throttle/ip', $ip);

		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
	}

	/**
	 * @return array<int, int> znaczniki czasu prób w bieżącym oknie
	 */
	private function attempts(string $key, int $now): array
	{
		$attempts = get_transient($key);
		$window = $this->limits()['window'];

		if (! is_array($attempts)) {
			return [];
		}

		$attempts = array_values(array_filter(array_map('intval', $attempts), fn ($time) => $time > $now - $window));
		sort($attempts);

		return $attempts;
	}

	private function loginKey(string $username): string
	{
		return 'pp_login_' . md5('login|' . $this->clientIp() . '|' . mb_strtolower(trim($username)));
	}

	private function ipKey(): string
	{
		return 'pp_login_' . md5('ip|' . $this->clientIp());
	}
}
