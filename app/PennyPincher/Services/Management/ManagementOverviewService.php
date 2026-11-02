<?php

namespace App\PennyPincher\Services\Management;

use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Policy;
use App\PennyPincher\Domain\Projects\BusinessContext;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\ImportRepository;
use App\PennyPincher\Repositories\OrganizationRepository;
use App\PennyPincher\Repositories\SyncRunRepository;
use App\PennyPincher\Services\GoogleAds\WriteGuard;
use App\PennyPincher\Support\AppUrl;

/**
 * Przegląd Zarządzania (pp2_management): kroki konfiguracji integracji i ustawienia rozdzielone na projekt,
 * organizację i serwer. Wyłącznie odczyt stanu z bazy i konfiguracji – bez wywołań Google Ads API, bez odczytu
 * tokenów (kolumny publiczne połączenia) i bez wartości sekretów (tylko nazwy brakujących ustawień serwera).
 */
class ManagementOverviewService
{
	public function __construct(
		private Db $db,
		private GoogleAdsConnectionRepository $connections,
		private SyncRunRepository $runs,
		private ImportRepository $imports,
		private OrganizationRepository $organizations,
		private GoogleAdsConfig $config,
		private WriteGuard $guard,
	) {
	}

	public function overview(Project $project): array
	{
		$connection = $this->connections->currentForProject($project->id);
		$lastSync = $connection !== null ? $this->runs->lastCompleted((int) $connection['id']) : null;
		$openRun = $connection !== null ? $this->runs->openForConnection((int) $connection['id']) : null;
		$missing = $this->config->missing();
		$imports = $this->db->first(
			"SELECT COUNT(*) AS total, SUM(status = 'completed') AS completed, SUM(status = 'failed') AS failed, MAX(CASE WHEN status = 'completed' THEN created_at END) AS last_completed
			FROM " . $this->db->table('imports') . ' WHERE project_id = ?',
			[$project->id]
		) ?? [];
		$filledContext = array_filter(array_keys(BusinessContext::FIELDS), fn ($f) => ! in_array($project->context()->get($f), [null, '', []], true));
		$members = $this->organizations->members($project->organizationId);
		$status = $connection['status'] ?? null;
		$p = fn (string $path, array $q = []) => AppUrl::project($project->id, $path, $q);

		$steps = [
			[
				'key' => 'server', 'label' => 'Konfiguracja serwera (OAuth i szyfrowanie)', 'done' => $missing === [],
				'detail' => $missing === [] ? 'Klient OAuth i klucz szyfrowania są ustawione.' : 'Brakuje ustawień w wp-config.php serwera: ' . implode(', ', $missing) . '. Ustawia je administrator serwera – nie w aplikacji.',
				'url' => $p('google-ads'),
			],
			[
				'key' => 'connect', 'label' => 'Połączenie z kontem Google', 'done' => $status !== null && $status !== GoogleAdsConnectionRepository::STATUS_REAUTH,
				'detail' => match ($status) {
					null => 'Projekt nie ma połączenia z Google Ads. Bez niego działają importy CSV.',
					GoogleAdsConnectionRepository::STATUS_REAUTH => 'Google odrzucił autoryzację – połącz konto ponownie (dotychczasowe dane i historia zostają).',
					GoogleAdsConnectionRepository::STATUS_ERROR => 'Połączenie ma błąd: ' . ($connection['last_error'] ?: 'sprawdź diagnostykę.'),
					default => 'Połączono' . ($connection['google_email'] ? ' (' . $connection['google_email'] . ')' : '') . '.',
				},
				'url' => $p('google-ads'),
			],
			[
				'key' => 'account', 'label' => 'Wybór konta reklamowego', 'done' => $status === GoogleAdsConnectionRepository::STATUS_ACTIVE && ! empty($connection['customer_id']),
				'detail' => ! empty($connection['customer_id']) ? 'Konto ' . self::customer((string) $connection['customer_id']) . ($connection['customer_name'] ? ' – ' . $connection['customer_name'] : '') . ' (' . ($connection['currency'] ?? '—') . ', ' . ($connection['time_zone'] ?? '—') . ').' : 'Wybierz konto po połączeniu z Google.',
				'url' => $p('google-ads'),
			],
			[
				'key' => 'sync', 'label' => 'Pierwsza synchronizacja danych', 'done' => $lastSync !== null,
				'detail' => $lastSync !== null ? 'Ostatnia zakończona: ' . $lastSync['finished_at'] . ($openRun !== null ? '; trwa kolejna.' : '.') : ($openRun !== null ? 'Synchronizacja w toku.' : 'Jeszcze nie zsynchronizowano danych z API.'),
				'url' => $p('google-ads'),
			],
			[
				'key' => 'imports', 'label' => 'Dane z importów (opcjonalnie)', 'done' => (int) ($imports['completed'] ?? 0) > 0, 'optional' => true,
				'detail' => (int) ($imports['total'] ?? 0) === 0 ? 'Brak importów CSV.' : sprintf('Zaimportowane: %d z %d%s.', (int) $imports['completed'], (int) $imports['total'], (int) $imports['failed'] > 0 ? ', z błędem: ' . (int) $imports['failed'] : ''),
				'url' => $p('imports'),
			],
			[
				'key' => 'settings', 'label' => 'Budżet i kontekst biznesowy projektu', 'done' => $project->monthlyBudgetMicros !== null && $filledContext !== [],
				'detail' => ($project->monthlyBudgetMicros !== null ? 'Budżet miesięczny ustawiony.' : 'Budżet miesięczny nie jest ustawiony (nie zakładamy wartości domyślnej).')
					. ' Kontekst biznesowy: ' . count($filledContext) . ' z ' . count(BusinessContext::FIELDS) . ' pól.',
				'url' => $p('settings'),
			],
		];

		return [
			'steps' => $steps,
			'connection' => $connection,
			'lastSync' => $lastSync,
			'filledContext' => count($filledContext),
			'imports' => $imports,
			'server' => [
				'missing' => $missing,
				'encryption' => ! array_filter($missing, fn ($m) => str_starts_with($m, 'PP_ENCRYPTION_KEY')),
				'api_version' => $this->config->apiVersion(),
				'write_mode' => $this->guard->serverMode(),
				'project_mode' => $this->guard->mode($connection),
			],
			'organization' => [
				'id' => $project->organizationId,
				'name' => $project->organizationName,
				'members' => count($members),
				'executors' => count(array_filter($members, fn ($m) => (int) $m['can_execute_changes'] === 1 && in_array($m['role'], ['owner', 'admin'], true))),
				'can_manage' => Policy::allows($project->role, Ability::ManageMembers),
			],
			'canManage' => Policy::allows($project->role, Ability::UpdateProject),
		];
	}

	private static function customer(string $id): string
	{
		return strlen($id) === 10 ? substr($id, 0, 3) . '-' . substr($id, 3, 3) . '-' . substr($id, 6) : $id;
	}
}
