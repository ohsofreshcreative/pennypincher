<?php

namespace App\PennyPincher\Services\Monitoring;

use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Repositories\AlertRepository;
use App\PennyPincher\Repositories\OrganizationRepository;
use App\PennyPincher\Support\AppUrl;
use Throwable;

/**
 * Powiadomienia e-mail o alertach – wyłącznie po świadomym włączeniu (opt-in) przez użytkownika dla projektu.
 *
 * - Ustawienia per użytkownik i projekt (user meta `pp_alert_email`): włączone, minimalna ważność.
 * - Ograniczenie częstotliwości: najwyżej jeden e-mail na 24 h na użytkownika i projekt (zbiorczy).
 * - Deduplikacja: alert jest wysyłany raz (last_notified_at); ponownie dopiero po ponownym otwarciu lub końcu wyciszenia. Wyciszone, potwierdzone i rozwiązane – nigdy.
 * - Odbiorca musi być członkiem organizacji projektu (sprawdzane przy każdej wysyłce).
 * - Błędy wysyłki są logowane bez adresów e-mail i treści.
 */
class AlertNotifier
{
	public const META = 'pp_alert_email';

	public const INTERVAL = DAY_IN_SECONDS;

	public const SEVERITY_LEVELS = ['critical' => ['critical'], 'warning' => ['critical', 'warning']];

	public function __construct(private AlertRepository $alerts, private OrganizationRepository $organizations)
	{
	}

	/**
	 * @return array{enabled: bool, min_severity: string, last_sent: ?int}
	 */
	public static function preferences(int $userId, int $projectId): array
	{
		$all = get_user_meta($userId, self::META, true);
		$prefs = is_array($all) ? ($all[$projectId] ?? []) : [];

		return [
			'enabled' => (bool) ($prefs['enabled'] ?? false),
			'min_severity' => in_array($prefs['min_severity'] ?? '', ['critical', 'warning'], true) ? $prefs['min_severity'] : 'critical',
			'last_sent' => isset($prefs['last_sent']) ? (int) $prefs['last_sent'] : null,
		];
	}

	public static function savePreferences(int $userId, int $projectId, bool $enabled, string $minSeverity): void
	{
		$all = get_user_meta($userId, self::META, true);
		$all = is_array($all) ? $all : [];
		$all[$projectId] = ['enabled' => $enabled, 'min_severity' => in_array($minSeverity, ['critical', 'warning'], true) ? $minSeverity : 'critical', 'last_sent' => $all[$projectId]['last_sent'] ?? null];
		update_user_meta($userId, self::META, $all);
	}

	/**
	 * Wysyłka zbiorcza (wywoływana po monitoringu w workerze). Zwraca liczbę wysłanych e-maili.
	 */
	public function notify(Project $project): int
	{
		$sent = 0;

		foreach ($this->organizations->members($project->organizationId) as $member) {
			$userId = (int) ($member['user_id'] ?? $member['ID'] ?? 0);

			if ($userId <= 0) {
				continue;
			}

			$prefs = self::preferences($userId, $project->id);

			if (! $prefs['enabled'] || ($prefs['last_sent'] !== null && time() - $prefs['last_sent'] < self::INTERVAL)) {
				continue;
			}

			$alerts = $this->alerts->pendingNotifications($project->id, self::SEVERITY_LEVELS[$prefs['min_severity']]);

			if ($alerts === []) {
				continue;
			}

			$user = get_userdata($userId);

			if (! $user || ! is_email($user->user_email)) {
				continue;
			}

			try {
				$ok = wp_mail($user->user_email, $this->subject($project, $alerts), $this->body($project, $alerts));
			} catch (Throwable $e) {
				$ok = false;
			}

			if (! $ok) {
				error_log('[Penny Pincher] Nie udało się wysłać powiadomienia o alertach (projekt #' . $project->id . ', użytkownik #' . $userId . ').');
				continue;
			}

			$all = get_user_meta($userId, self::META, true);
			$all = is_array($all) ? $all : [];
			$all[$project->id]['last_sent'] = time();
			update_user_meta($userId, self::META, $all);

			foreach ($alerts as $alert) {
				$this->alerts->update((int) $alert['id'], ['last_notified_at' => gmdate('Y-m-d H:i:s')]);
			}

			$sent++;
		}

		return $sent;
	}

	private function subject(Project $project, array $alerts): string
	{
		return sprintf('[Penny Pincher] %s: %d %s', $project->name, count($alerts), count($alerts) === 1 ? 'nowy alert' : 'nowe alerty');
	}

	private function body(Project $project, array $alerts): string
	{
		$lines = ['Nowe alerty monitoringu w projekcie „' . $project->name . '”:', ''];

		foreach ($alerts as $alert) {
			$lines[] = '• [' . (AlertRepository::SEVERITIES[$alert['severity']] ?? $alert['severity']) . '] ' . $alert['title'];
		}

		$lines[] = '';
		$lines[] = 'Szczegóły: ' . AppUrl::project($project->id, 'monitoring');
		$lines[] = '';
		$lines[] = 'Powiadomienia wyłączysz na stronie Monitoring (Ustawienia powiadomień). Penny Pincher nie zmienia niczego na koncie Google Ads.';

		return implode("\n", $lines);
	}
}
