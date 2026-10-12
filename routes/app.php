<?php

/**
 * Routing aplikacji Penny Pincher (/app/).
 *
 * Trasy obsługuje Acorn – nie są to strony WordPressa. Każda trasa wymagająca projektu ma jego id w URL
 * (/app/projects/{project}/…), a dostęp jest sprawdzany w kontrolerze przez AccessService.
 */

use App\PennyPincher\Http\Controllers\AuditsController;
use App\PennyPincher\Http\Controllers\AuthController;
use App\PennyPincher\Http\Controllers\CampaignsController;
use App\PennyPincher\Http\Controllers\DashboardController;
use App\PennyPincher\Http\Controllers\GoogleAdsController;
use App\PennyPincher\Http\Controllers\HomeController;
use App\PennyPincher\Http\Controllers\ImportsController;
use App\PennyPincher\Http\Controllers\OrganizationsController;
use App\PennyPincher\Http\Controllers\ProjectsController;
use App\PennyPincher\Http\Controllers\SearchTermsController;
use App\PennyPincher\Http\Controllers\SettingsController;
use App\PennyPincher\Http\Middleware\Authenticate;
use App\PennyPincher\Http\Middleware\PrepareAppRequest;
use App\PennyPincher\Http\Middleware\VerifyNonce;
use Illuminate\Support\Facades\Route;

Route::prefix('app')->middleware([PrepareAppRequest::class, VerifyNonce::class])->group(function () {
	Route::get('login', [AuthController::class, 'show'])->name('pp.login');
	Route::post('login', [AuthController::class, 'login']);

	Route::middleware(Authenticate::class)->group(function () {
		Route::post('logout', [AuthController::class, 'logout'])->name('pp.logout');

		Route::get('/', [HomeController::class, 'index'])->name('pp.home');
		Route::get('switch', [HomeController::class, 'switchProject'])->name('pp.switch');

		Route::get('projects', [ProjectsController::class, 'index'])->name('pp.projects');
		Route::get('projects/new', [ProjectsController::class, 'create'])->name('pp.projects.create');
		Route::post('projects', [ProjectsController::class, 'store'])->name('pp.projects.store');
		Route::post('projects/demo', [ProjectsController::class, 'demo'])->name('pp.projects.demo');

		Route::post('organizations', [OrganizationsController::class, 'store'])->name('pp.organizations.store');
		Route::get('organizations/{organization}/members', [OrganizationsController::class, 'members'])->whereNumber('organization');
		Route::post('organizations/{organization}/members', [OrganizationsController::class, 'addMember'])->whereNumber('organization');
		Route::post('organizations/{organization}/members/{user}/remove', [OrganizationsController::class, 'removeMember'])->whereNumber(['organization', 'user']);

		// Powrót z autoryzacji Google – projekt i użytkownik wynikają z jednorazowego parametru state.
		Route::get('google-ads/callback', [GoogleAdsController::class, 'callback'])->name('pp.google-ads.callback');

		Route::prefix('projects/{project}')->whereNumber('project')->group(function () {
			Route::get('/', [DashboardController::class, 'show'])->name('pp.dashboard');
			Route::get('campaigns', [CampaignsController::class, 'index'])->name('pp.campaigns');
			Route::get('campaigns/{campaign}', [CampaignsController::class, 'show'])->whereNumber('campaign');
			Route::get('search-terms', [SearchTermsController::class, 'index'])->name('pp.search-terms');
			Route::get('imports', [ImportsController::class, 'index'])->name('pp.imports');
			Route::post('imports', [ImportsController::class, 'store']);
			Route::get('imports/{import}', [ImportsController::class, 'show'])->whereNumber('import');
			Route::post('imports/{import}', [ImportsController::class, 'confirm'])->whereNumber('import');
			Route::post('imports/{import}/cancel', [ImportsController::class, 'cancel'])->whereNumber('import');
			Route::get('google-ads', [GoogleAdsController::class, 'show'])->name('pp.google-ads');
			Route::post('google-ads/connect', [GoogleAdsController::class, 'connect']);
			Route::post('google-ads/account', [GoogleAdsController::class, 'selectAccount']);
			Route::post('google-ads/sync', [GoogleAdsController::class, 'syncNow']);
			Route::post('google-ads/sync/{run}/retry', [GoogleAdsController::class, 'retry'])->whereNumber('run');
			Route::post('google-ads/disconnect', [GoogleAdsController::class, 'disconnect']);
			Route::post('google-ads/diagnose', [GoogleAdsController::class, 'diagnose']);
			Route::get('audits', [AuditsController::class, 'index'])->name('pp.audits');
			Route::post('audits', [AuditsController::class, 'store']);
			Route::get('audits/{run}', [AuditsController::class, 'show'])->whereNumber('run');
			Route::post('audits/findings/{finding}/status', [AuditsController::class, 'updateStatus'])->whereNumber('finding');
			Route::get('settings', [SettingsController::class, 'edit'])->name('pp.settings');
			Route::post('settings', [SettingsController::class, 'update']);
		});

		// Skróty bez identyfikatora projektu – przekierowują do bieżącego projektu.
		Route::get('{section}', [HomeController::class, 'shortcut'])->whereIn('section', ['campaigns', 'search-terms', 'imports', 'settings', 'google-ads', 'audits']);

		Route::any('{any}', [HomeController::class, 'notFound'])->where('any', '.*');
	});
});
