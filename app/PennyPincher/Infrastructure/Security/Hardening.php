<?php

namespace App\PennyPincher\Infrastructure\Security;

use WP_Error;

/**
 * Utwardzenie WordPressa pod aplikację SaaS – działa w każdym środowisku, bez wtyczek.
 *
 * - brak publicznej rejestracji (niezależnie od ustawienia w panelu),
 * - wyłączony XML-RPC (częsty wektor ataków na hasła),
 * - brak wyliczania użytkowników (REST /wp/v2/users i ?author=N dla niezalogowanych),
 * - brak numeru wersji WordPressa w nagłówkach strony.
 *
 * Ustawienia serwera (HTTPS, wp-config, blokada PHP w uploads) opisuje docs/DEPLOYMENT.md.
 */
final class Hardening
{
	public static function register(): void
	{
		add_filter('pre_option_users_can_register', fn () => '0');
		add_filter('pre_site_option_registration', fn () => 'none');

		add_filter('xmlrpc_enabled', '__return_false');
		// Same metody system.* XML-RPC działają mimo xmlrpc_enabled=false – odpowiadamy 403 na cały endpoint.
		add_action('init', [self::class, 'blockXmlRpc'], 0);
		add_filter('xmlrpc_methods', fn () => []);
		add_filter('wp_headers', function (array $headers) {
			unset($headers['X-Pingback']);

			return $headers;
		});

		remove_action('wp_head', 'wp_generator');
		remove_action('wp_head', 'rsd_link');
		add_filter('the_generator', '__return_empty_string');

		add_filter('rest_authentication_errors', [self::class, 'restrictUserEndpoints'], 99);
		add_action('template_redirect', [self::class, 'blockAuthorEnumeration'], 1);
		add_filter('oembed_response_data', function (array $data) {
			unset($data['author_name'], $data['author_url']);

			return $data;
		});
	}

	public static function blockXmlRpc(): void
	{
		if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
			status_header(403);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'XML-RPC jest wyłączony.';
			exit;
		}
	}

	/**
	 * Lista użytkowników w REST API tylko dla zalogowanych.
	 */
	public static function restrictUserEndpoints(mixed $result): mixed
	{
		if ($result !== null || is_user_logged_in()) {
			return $result;
		}

		$route = (string) ($GLOBALS['wp']->query_vars['rest_route'] ?? '');

		if ($route === '' && isset($_SERVER['REQUEST_URI'])) {
			$route = (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
		}

		if (self::isUsersRoute($route)) {
			return new WP_Error('rest_forbidden', 'Brak dostępu.', ['status' => 401]);
		}

		return $result;
	}

	public static function isUsersRoute(string $route): bool
	{
		return (bool) preg_match('#/wp/v2/users(?:/|$|\?)#i', $route . '/');
	}

	/**
	 * /?author=1 przekierowuje na /author/login/ i ujawnia login – blokujemy to dla niezalogowanych.
	 */
	public static function blockAuthorEnumeration(): void
	{
		if (is_user_logged_in() || is_admin()) {
			return;
		}

		if (isset($_GET['author']) || is_author()) {
			wp_safe_redirect(home_url('/'), 301);
			exit;
		}
	}
}
