<?php

/**
 * Routing aplikacji Penny Pincher (/app/).
 *
 * Trasy obsługuje Acorn – nie są to strony WordPressa. Każda trasa wymagająca projektu ma jego id w URL
 * (/app/projects/{project}/…), a dostęp jest sprawdzany w kontrolerze przez AccessService.
 */

use App\PennyPincher\Http\Controllers\ActionPlanController;
use App\PennyPincher\Http\Controllers\AnalysisController;
use App\PennyPincher\Http\Controllers\AuditsController;
use App\PennyPincher\Http\Controllers\AuthController;
use App\PennyPincher\Http\Controllers\BudgetLeaksController;
use App\PennyPincher\Http\Controllers\CampaignPlannerController;
use App\PennyPincher\Http\Controllers\CampaignsController;
use App\PennyPincher\Http\Controllers\ChangeCenterController;
use App\PennyPincher\Http\Controllers\ConversionManagementController;
use App\PennyPincher\Http\Controllers\ConversionsController;
use App\PennyPincher\Http\Controllers\DashboardController;
use App\PennyPincher\Http\Controllers\GoogleAdsController;
use App\PennyPincher\Http\Controllers\GoogleAdsManagementController;
use App\PennyPincher\Http\Controllers\HomeController;
use App\PennyPincher\Http\Controllers\ImportsController;
use App\PennyPincher\Http\Controllers\KeywordOpportunitiesController;
use App\PennyPincher\Http\Controllers\MonitoringController;
use App\PennyPincher\Http\Controllers\OrganizationsController;
use App\PennyPincher\Http\Controllers\ProjectsController;
use App\PennyPincher\Http\Controllers\RecommendationsController;
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
			Route::post('google-ads/backfill', [GoogleAdsController::class, 'backfill']);
			Route::post('analysis', [AnalysisController::class, 'store']);
			Route::get('budget-leaks', [BudgetLeaksController::class, 'index'])->name('pp.budget-leaks');
			Route::post('budget-leaks/{leak}/status', [BudgetLeaksController::class, 'updateStatus'])->whereNumber('leak');
			Route::get('recommendations', [RecommendationsController::class, 'index'])->name('pp.recommendations');
			Route::post('recommendations/{recommendation}/status', [RecommendationsController::class, 'updateStatus'])->whereNumber('recommendation');
			Route::post('recommendations/{recommendation}/note', [RecommendationsController::class, 'updateNote'])->whereNumber('recommendation');
			Route::post('recommendations/{recommendation}/obsolete', [RecommendationsController::class, 'obsolete'])->whereNumber('recommendation');
			Route::get('keywords', [KeywordOpportunitiesController::class, 'index'])->name('pp.keywords');
			Route::post('keywords/dataforseo/test', [KeywordOpportunitiesController::class, 'testDataForSeo']);
			Route::post('keywords/dataforseo', [KeywordOpportunitiesController::class, 'requestDataForSeo']);
			Route::post('keywords/dataforseo/limit', [KeywordOpportunitiesController::class, 'updateLimit']);
			Route::post('keywords/dataforseo/seeds', [KeywordOpportunitiesController::class, 'updateSeeds']);
			Route::post('keywords/status', [KeywordOpportunitiesController::class, 'updateStatus']);
			Route::post('keywords/clusters/status', [KeywordOpportunitiesController::class, 'updateClusterStatus']);
			Route::get('action-plan', [ActionPlanController::class, 'index'])->name('pp.action-plan');
			Route::post('action-plan/generate', [ActionPlanController::class, 'generate']);
			Route::post('action-plan/{item}/status', [ActionPlanController::class, 'updateStatus'])->whereNumber('item');
			Route::post('action-plan/{item}/note', [ActionPlanController::class, 'updateNote'])->whereNumber('item');
			Route::get('monitoring', [MonitoringController::class, 'index'])->name('pp.monitoring');
			Route::post('monitoring/check', [MonitoringController::class, 'check']);
			Route::post('monitoring/preferences', [MonitoringController::class, 'preferences']);
			Route::post('monitoring/{alert}/status', [MonitoringController::class, 'updateStatus'])->whereNumber('alert');
			Route::get('planner', [CampaignPlannerController::class, 'index'])->name('pp.planner');
			Route::post('planner', [CampaignPlannerController::class, 'store']);
			Route::post('planner/from-keywords', [CampaignPlannerController::class, 'fromKeywords']);
			Route::prefix('planner/{plan}')->whereNumber('plan')->group(function () {
				Route::get('/', [CampaignPlannerController::class, 'show']);
				Route::post('/', [CampaignPlannerController::class, 'update']);
				Route::post('status', [CampaignPlannerController::class, 'status']);
				Route::post('copy', [CampaignPlannerController::class, 'copy']);
				Route::get('export/{format}', [CampaignPlannerController::class, 'export'])->whereIn('format', ['csv', 'html']);
				Route::post('campaigns', [CampaignPlannerController::class, 'addCampaign']);
				Route::post('campaigns/{campaign}', [CampaignPlannerController::class, 'updateCampaign'])->whereNumber('campaign');
				Route::post('campaigns/{campaign}/delete', [CampaignPlannerController::class, 'deleteCampaign'])->whereNumber('campaign');
				Route::post('campaigns/{campaign}/budget', [CampaignPlannerController::class, 'campaignBudget'])->whereNumber('campaign');
				Route::post('groups', [CampaignPlannerController::class, 'addGroup']);
				Route::post('groups/{group}', [CampaignPlannerController::class, 'updateGroup'])->whereNumber('group');
				Route::post('groups/{group}/delete', [CampaignPlannerController::class, 'deleteGroup'])->whereNumber('group');
				Route::post('groups/{group}/keywords', [CampaignPlannerController::class, 'addKeywords'])->whereNumber('group');
				Route::post('groups/{group}/ad', [CampaignPlannerController::class, 'saveAd'])->whereNumber('group');
				Route::post('groups/{group}/ad/suggest', [CampaignPlannerController::class, 'suggestAd'])->whereNumber('group');
				Route::post('keywords/{keyword}', [CampaignPlannerController::class, 'updateKeyword'])->whereNumber('keyword');
				Route::post('negatives', [CampaignPlannerController::class, 'addNegatives']);
				Route::post('negatives/{negative}/delete', [CampaignPlannerController::class, 'deleteNegative'])->whereNumber('negative');
				Route::post('budget', [CampaignPlannerController::class, 'updateBudget']);
				Route::post('budget/apply', [CampaignPlannerController::class, 'applyBudget']);
			});
			// Change Center: zestawy zmian Google Ads (szkic → przegląd → zatwierdzenie → wdrożenie → weryfikacja).
			Route::get('changes', [ChangeCenterController::class, 'index'])->name('pp.changes');
			Route::post('changes', [ChangeCenterController::class, 'store']);
			Route::prefix('changes/{set}')->whereNumber('set')->group(function () {
				Route::get('/', [ChangeCenterController::class, 'show']);
				Route::post('operations/{operation}', [ChangeCenterController::class, 'updateOperation'])->whereNumber('operation');
				Route::post('operations/{operation}/delete', [ChangeCenterController::class, 'deleteOperation'])->whereNumber('operation');
				Route::post('read-state', [ChangeCenterController::class, 'readState']);
				Route::post('submit', [ChangeCenterController::class, 'submit']);
				Route::post('approve', [ChangeCenterController::class, 'approve']);
				Route::post('reject', [ChangeCenterController::class, 'reject']);
				Route::post('deploy', [ChangeCenterController::class, 'deploy']);
				Route::post('cancel', [ChangeCenterController::class, 'cancel']);
				Route::post('reconcile', [ChangeCenterController::class, 'reconcile']);
				Route::post('rollback', [ChangeCenterController::class, 'rollback']);
				Route::post('retry', [ChangeCenterController::class, 'retry']);
			});
			Route::post('recommendations/{recommendation}/prepare-change', [ChangeCenterController::class, 'fromRecommendation'])->whereNumber('recommendation');
			Route::post('budget-leaks/{leak}/prepare-change', [ChangeCenterController::class, 'fromLeak'])->whereNumber('leak');
			Route::post('action-plan/{item}/prepare-change', [ChangeCenterController::class, 'fromActionItem'])->whereNumber('item');
			Route::post('keywords/negatives/prepare-change', [ChangeCenterController::class, 'fromNegatives']);
			Route::post('planner/{plan}/deploy', [ChangeCenterController::class, 'fromPlan'])->whereNumber('plan');
			// Google Ads Management (odczyt stanu z API + przygotowanie zmian; bez bezpośrednich mutacji).
			Route::get('management', [GoogleAdsManagementController::class, 'index'])->name('pp.management');
			Route::get('management/campaigns/{campaign}', [GoogleAdsManagementController::class, 'campaign'])->whereNumber('campaign');
			Route::get('management/ad-groups/{group}', [GoogleAdsManagementController::class, 'adGroup'])->whereNumber('group');
			Route::post('management/read', [GoogleAdsManagementController::class, 'read']);
			Route::post('management/campaigns/new', [GoogleAdsManagementController::class, 'newCampaign']);
			Route::post('management/campaigns/{campaign}/change', [GoogleAdsManagementController::class, 'campaignChange'])->whereNumber('campaign');
			Route::post('management/ad-groups/{group}/change', [GoogleAdsManagementController::class, 'adGroupChange'])->whereNumber('group');
			Route::post('management/write-access', [GoogleAdsManagementController::class, 'writeAccess']);
			Route::post('management/executor', [GoogleAdsManagementController::class, 'executor']);
			// Conversion Management.
			Route::get('conversion-management', [ConversionManagementController::class, 'index'])->name('pp.conversion-management');
			Route::post('conversion-management/read', [ConversionManagementController::class, 'read']);
			Route::post('conversion-management/create', [ConversionManagementController::class, 'create']);
			Route::post('conversion-management/{action}/update', [ConversionManagementController::class, 'update'])->whereNumber('action');
			Route::post('conversion-management/goal', [ConversionManagementController::class, 'goal']);
			Route::post('conversion-management/health', [ConversionManagementController::class, 'health']);
			Route::get('conversions', [ConversionsController::class, 'index'])->name('pp.conversions');
			Route::post('conversions', [ConversionsController::class, 'update']);
			Route::post('settings/context', [SettingsController::class, 'updateContext']);
			Route::get('settings', [SettingsController::class, 'edit'])->name('pp.settings');
			Route::post('settings', [SettingsController::class, 'update']);
		});

		// Skróty bez identyfikatora projektu – przekierowują do bieżącego projektu.
		Route::get('{section}', [HomeController::class, 'shortcut'])->whereIn('section', ['campaigns', 'search-terms', 'imports', 'settings', 'google-ads', 'audits', 'budget-leaks', 'recommendations', 'keywords', 'conversions', 'planner', 'action-plan', 'monitoring', 'changes', 'management', 'conversion-management']);

		Route::any('{any}', [HomeController::class, 'notFound'])->where('any', '.*');
	});
});
