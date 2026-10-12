<?php

namespace App\PennyPincher\Providers;

use App\PennyPincher\Console\CreateOrganizationCommand;
use App\PennyPincher\Console\ImportFileCommand;
use App\PennyPincher\Console\MigrateCommand;
use App\PennyPincher\Console\PruneStagedImportsCommand;
use App\PennyPincher\Domain\Access\AccessDenied;
use App\PennyPincher\Domain\Import\ReportRegistry;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Http\Responses;
use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\OrganizationRepository;
use App\PennyPincher\Services\ImportMaintenance;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;

/**
 * Rejestracja modułu aplikacji Penny Pincher (/app/).
 */
class PennyPincherServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		// Jeden obiekt Db na żądanie – licznik zagnieżdżonych transakcji musi być wspólny.
		$this->app->singleton(Db::class, fn () => new Db);
		$this->app->singleton(ReportRegistry::class);
	}

	public function boot(): void
	{
		$this->registerExceptionRendering();

		add_action('deleted_user', function (int $userId) {
			$this->app->make(OrganizationRepository::class)->removeUserEverywhere($userId);
		});

		// Sprzątanie niedokończonych importów. WP-Cron to tylko wygoda – na produkcji
		// to samo zadanie uruchamia `wp acorn pp:imports:prune` z systemowego crona.
		add_action('pennypincher_prune_staged_imports', function () {
			$this->app->make(ImportMaintenance::class)->pruneStaged();
		});

		add_action('init', function () {
			if (! wp_next_scheduled('pennypincher_prune_staged_imports')) {
				wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'pennypincher_prune_staged_imports');
			}
		});

		if ($this->app->runningInConsole()) {
			$this->commands([
				MigrateCommand::class,
				CreateOrganizationCommand::class,
				ImportFileCommand::class,
				PruneStagedImportsCommand::class,
			]);
		}
	}

	private function registerExceptionRendering(): void
	{
		$handler = $this->app->make(ExceptionHandler::class);

		if (! method_exists($handler, 'renderable')) {
			return;
		}

		if (method_exists($handler, 'dontReport')) {
			$handler->dontReport([NotFound::class, AccessDenied::class]);
		}

		$handler->renderable(fn (NotFound $e) => Responses::error(404, 'Nie znaleziono', $e->getMessage()));
		$handler->renderable(fn (AccessDenied $e) => Responses::error(403, 'Brak uprawnień', $e->getMessage()));
	}
}
