<?php

namespace App\PennyPincher\Services\GoogleAds;

use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Infrastructure\GoogleAds\GoogleAdsApiException;
use App\PennyPincher\Infrastructure\GoogleAds\Sync\ApiSyncer;
use App\PennyPincher\Repositories\GoogleAdsConnectionRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Repositories\SyncRunRepository;
use App\PennyPincher\Services\AuditService;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Synchronizacja danych z Google Ads API.
 *
 * - przebieg (`sync_runs`) to lista kroków: struktura konta, a potem metryki w oknach miesięcznych,
 * - worker wykonuje kroki po kolei, zapisując postęp po każdym – przerwany przebieg jest wznawiany od kroku,
 *   na którym stanął (idempotentne kroki: zastąpienie okna danych API),
 * - błędy chwilowe i limity API: ponowienie z rosnącym opóźnieniem (do 6 prób), błędy autoryzacji kończą przebieg
 *   i oznaczają połączenie do ponownego połączenia,
 * - jedno połączenie = co najwyżej jeden przebieg naraz (kolejka + blokada GET_LOCK),
 * - harmonogram: pierwsza synchronizacja to 12 miesięcy wstecz, potem raz dziennie ostatnie 30 dni
 *   (Google Ads dopisuje konwersje z opóźnieniem). Dane są pobierane do wczoraj – bieżący dzień jest niepełny.
 */
class SyncService
{
	public const BACKFILL_MONTHS = 12;

	public const INCREMENTAL_DAYS = 30;

	public const MAX_ATTEMPTS = 6;

	public const DAILY_INTERVAL_HOURS = 20;

	public function __construct(
		private Db $db,
		private SyncRunRepository $runs,
		private GoogleAdsConnectionRepository $connections,
		private ConnectionService $connectionService,
		private ApiSyncer $syncer,
		private ProjectRepository $projects,
	) {
	}

	/**
	 * Dodaje przebieg do kolejki (albo zwraca istniejący otwarty przebieg tego połączenia).
	 */
	public function enqueue(array $connection, string $type, ?int $userId = null): array
	{
		$open = $this->runs->openForConnection((int) $connection['id']);

		if ($open !== null) {
			return $open;
		}

		if ($type !== 'backfill' && $this->runs->lastCompleted((int) $connection['id'], 'backfill') === null) {
			$type = 'backfill';
		}

		[$from, $to] = $this->window($connection, $type);
		$steps = $this->plan($from, $to);

		$runId = $this->runs->create([
			'project_id' => (int) $connection['project_id'],
			'connection_id' => (int) $connection['id'],
			'type' => $type,
			'status' => SyncRunRepository::QUEUED,
			'date_from' => $from,
			'date_to' => $to,
			'steps' => $steps,
			'steps_total' => count($steps),
			'requested_by' => $userId,
		]);

		return $this->runs->find($runId);
	}

	/**
	 * Kroki przebiegu: struktura, a następnie metryki w oknach kalendarzowych miesięcy (od najnowszego).
	 *
	 * @return array<int, array{type: string, from?: string, to?: string}>
	 */
	public function plan(string $from, string $to): array
	{
		$steps = array_map(fn ($type) => ['type' => $type], ApiSyncer::STRUCTURE_STEPS);

		foreach (array_reverse(self::monthWindows($from, $to)) as [$windowFrom, $windowTo]) {
			foreach (array_keys(ApiSyncer::METRIC_STEPS) as $type) {
				$steps[] = ['type' => $type, 'from' => $windowFrom, 'to' => $windowTo];
			}
		}

		return $steps;
	}

	/**
	 * Planowanie dla crona: pierwsza synchronizacja dla nowych połączeń i codzienna przyrostowa.
	 *
	 * @return int liczba dodanych przebiegów
	 */
	public function schedule(): int
	{
		$queued = 0;

		foreach ($this->connections->activeConnections() as $connection) {
			if ($this->runs->openForConnection((int) $connection['id']) !== null) {
				continue;
			}

			$lastBackfill = $this->runs->lastCompleted((int) $connection['id'], 'backfill');
			$last = $this->runs->lastCompleted((int) $connection['id']);

			if ($lastBackfill === null) {
				$this->enqueue($connection, 'backfill');
				$queued++;
			} elseif ($last === null || strtotime($last['finished_at'] . ' UTC') < time() - self::DAILY_INTERVAL_HOURS * HOUR_IN_SECONDS) {
				$this->enqueue($connection, 'incremental');
				$queued++;
			}
		}

		return $queued;
	}

	/**
	 * Przetwarza kolejkę w limicie czasu (cron / ręczne uruchomienie).
	 *
	 * @return array{processed: int, requeued: int}
	 */
	public function work(int $seconds): array
	{
		$deadline = time() + max(5, $seconds);
		$requeued = 0;

		foreach ($this->runs->stale() as $run) {
			$this->runs->update((int) $run['id'], ['status' => SyncRunRepository::QUEUED, 'next_attempt_at' => null, 'last_error' => 'Przebieg został przerwany – wznowiono od ostatniego zakończonego kroku.']);
			$requeued++;
		}

		$processed = 0;

		while (time() < $deadline && ($due = $this->runs->due(5)) !== []) {
			$progress = false;

			foreach ($due as $run) {
				if (time() >= $deadline) {
					break;
				}

				$before = (int) $run['step_index'];
				$after = $this->process($run, $deadline);
				$processed++;
				$progress = $progress || $after['step_index'] > $before || $after['status'] !== SyncRunRepository::QUEUED;
			}

			if (! $progress) {
				break;
			}
		}

		return ['processed' => $processed, 'requeued' => $requeued];
	}

	/**
	 * Wykonuje kroki jednego przebiegu do zakończenia albo do upływu czasu.
	 */
	public function process(array $run, int $deadline): array
	{
		$runId = (int) $run['id'];
		$lock = $this->db->wpdb()->prefix . 'pp_sync_' . (int) $run['connection_id'];

		if ((int) $this->db->scalar('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
			return $run; // ten sam przebieg (albo inny dla połączenia) jest właśnie wykonywany
		}

		try {
			$run = $this->runs->find($runId);

			if ($run === null || ! in_array($run['status'], [SyncRunRepository::QUEUED, SyncRunRepository::RUNNING], true)) {
				return $run ?? [];
			}

			$connection = $this->connections->find((int) $run['connection_id']);

			if ($connection === null || $connection['status'] !== GoogleAdsConnectionRepository::STATUS_ACTIVE) {
				return $this->fail($run, 'Połączenie z Google Ads nie jest aktywne. Połącz konto ponownie.');
			}

			$project = $this->projects->findById((int) $connection['project_id']);

			if ($project !== null && $connection['currency'] && $connection['currency'] !== $project->currency) {
				return $this->fail($run, sprintf('Waluta konta Google Ads (%s) jest inna niż waluta projektu (%s). Zmień walutę projektu albo połącz właściwe konto.', $connection['currency'], $project->currency));
			}

			$this->runs->update($runId, [
				'status' => SyncRunRepository::RUNNING,
				'started_at' => $run['started_at'] ?: $this->db->now(),
			]);

			$token = $this->connectionService->accessToken($connection);
			$index = (int) $run['step_index'];
			$records = (int) $run['records_imported'];
			$log = $run['log'];

			while ($index < count($run['steps'])) {
				if (time() >= $deadline) {
					$this->runs->update($runId, ['status' => SyncRunRepository::QUEUED, 'next_attempt_at' => null]);

					return $this->runs->find($runId);
				}

				$step = $run['steps'][$index];
				$count = $this->syncer->run($connection, $token, $runId, $step);
				$index++;
				$records += $count;
				$log[] = ['step' => $step['type'], 'from' => $step['from'] ?? null, 'to' => $step['to'] ?? null, 'records' => $count, 'at' => gmdate('c')];

				$this->runs->update($runId, [
					'step_index' => $index,
					'records_imported' => $records,
					'attempts' => 0,
					'last_error' => null,
					'log' => wp_json_encode(array_slice($log, -60)),
				]);
			}

			$this->runs->update($runId, ['status' => SyncRunRepository::COMPLETED, 'finished_at' => $this->db->now(), 'next_attempt_at' => null]);
			$this->connections->update((int) $connection['id'], ['last_error' => null]);
			$this->afterCompletion((int) $connection['project_id']);

			return $this->runs->find($runId);
		} catch (GoogleAdsApiException $e) {
			return $this->handleApiError($run, $e);
		} catch (Throwable $e) {
			error_log('[Penny Pincher] Synchronizacja #' . $runId . ': ' . $e->getMessage());

			return $this->fail($run, 'Nieoczekiwany błąd synchronizacji. Szczegóły są w logu serwera.');
		} finally {
			$this->db->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
		}
	}

	/**
	 * Ponowienie przebiegu, który zakończył się błędem – od kroku, na którym stanął.
	 */
	public function retry(array $run): void
	{
		if ($run['status'] === SyncRunRepository::FAILED) {
			$this->runs->update((int) $run['id'], ['status' => SyncRunRepository::QUEUED, 'attempts' => 0, 'next_attempt_at' => null, 'finished_at' => null]);
		}
	}

	public static function backoffSeconds(int $attempt, ?int $retryAfter = null): int
	{
		if ($retryAfter !== null && $retryAfter > 0) {
			return min(6 * HOUR_IN_SECONDS, $retryAfter);
		}

		return min(HOUR_IN_SECONDS, 60 * (2 ** max(0, $attempt - 1)));
	}

	/**
	 * @return array<int, array{0: string, 1: string}>
	 */
	public static function monthWindows(string $from, string $to): array
	{
		$windows = [];
		$cursor = new DateTimeImmutable($from);
		$end = new DateTimeImmutable($to);

		while ($cursor <= $end) {
			$monthEnd = $cursor->modify('last day of this month');
			$windowEnd = $monthEnd < $end ? $monthEnd : $end;
			$windows[] = [$cursor->format('Y-m-d'), $windowEnd->format('Y-m-d')];
			$cursor = $windowEnd->modify('+1 day');
		}

		return $windows;
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function window(array $connection, string $type): array
	{
		try {
			$timezone = new DateTimeZone($connection['time_zone'] ?: 'Europe/Warsaw');
		} catch (\Exception) {
			$timezone = new DateTimeZone('Europe/Warsaw');
		}

		$yesterday = (new DateTimeImmutable('yesterday', $timezone))->setTime(0, 0);
		$from = $type === 'backfill'
			? $yesterday->modify('-' . self::BACKFILL_MONTHS . ' months')->modify('+1 day')
			: $yesterday->modify('-' . (self::INCREMENTAL_DAYS - 1) . ' days');

		return [$from->format('Y-m-d'), $yesterday->format('Y-m-d')];
	}

	private function handleApiError(array $run, GoogleAdsApiException $e): array
	{
		$attempts = (int) $run['attempts'] + 1;

		if ($e->isRetryable() && $attempts < self::MAX_ATTEMPTS) {
			$this->runs->update((int) $run['id'], [
				'status' => SyncRunRepository::QUEUED,
				'attempts' => $attempts,
				'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + self::backoffSeconds($attempts, $e->retryAfterSeconds)),
				'last_error' => $e->getMessage(),
			]);

			return $this->runs->find((int) $run['id']);
		}

		if ($e->kind === GoogleAdsApiException::AUTH) {
			$this->connections->update((int) $run['connection_id'], [
				'status' => GoogleAdsConnectionRepository::STATUS_REAUTH,
				'last_error' => 'Autoryzacja Google wygasła lub została cofnięta. Połącz konto ponownie.',
			]);
		} elseif ($e->kind === GoogleAdsApiException::PERMISSION) {
			$this->connections->update((int) $run['connection_id'], ['last_error' => $e->getMessage()]);
		}

		return $this->fail($run, $e->getMessage(), $attempts);
	}

	private function fail(array $run, string $message, ?int $attempts = null): array
	{
		$this->runs->update((int) $run['id'], array_filter([
			'status' => SyncRunRepository::FAILED,
			'last_error' => mb_substr($message, 0, 2000),
			'finished_at' => $this->db->now(),
			'attempts' => $attempts,
		], fn ($value) => $value !== null));

		return $this->runs->find((int) $run['id']);
	}

	private function afterCompletion(int $projectId): void
	{
		try {
			$project = $this->projects->findById($projectId);

			if ($project !== null) {
				app(AuditService::class)->run($project, 'sync');
			}
		} catch (Throwable $e) {
			error_log('[Penny Pincher] Audyt po synchronizacji nie powiódł się: ' . $e->getMessage());
		}
	}
}
