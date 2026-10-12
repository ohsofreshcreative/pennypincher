<?php

namespace Tests\Integration;

use App\PennyPincher\Domain\Access\AccessDenied;
use App\PennyPincher\Domain\Access\Ability;
use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Support\NotFound;
use App\PennyPincher\Repositories\ImportRepository;
use App\PennyPincher\Repositories\MetricsRepository;
use App\PennyPincher\Repositories\ProjectRepository;
use App\PennyPincher\Services\AccessService;
use App\PennyPincher\Services\OrganizationService;
use Tests\IntegrationTestCase;

class IsolationTest extends IntegrationTestCase
{
	public function testUserCannotReachProjectOfAnotherOrganization(): void
	{
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$a = $this->organizationWithProject($alice, 'Alicja');
		$b = $this->organizationWithProject($bob, 'Bartek');

		wp_set_current_user($alice);
		$access = $this->make(AccessService::class);

		$this->assertSame($a['project']->id, $access->project($a['project']->id)->id);
		$this->assertThrows(NotFound::class, fn () => $access->project($b['project']->id));
		$this->assertThrows(NotFound::class, fn () => $access->organization($b['organization'], Ability::ViewProject));
		$this->assertNull($this->make(ProjectRepository::class)->findForUser($alice, $b['project']->id));

		$ids = array_map(fn ($p) => $p->id, $access->projects());
		$this->assertSame([$a['project']->id], $ids);
	}

	public function testWordPressAdministratorHasNoImplicitAccessToData(): void
	{
		$owner = $this->user('owner');
		$admin = $this->user('siteadmin', 'administrator');
		$org = $this->organizationWithProject($owner, 'Klient');

		wp_set_current_user($admin);
		$access = $this->make(AccessService::class);

		$this->assertTrue($access->canUseApp($admin), 'Administrator może zakładać organizacje.');
		$this->assertThrows(NotFound::class, fn () => $access->project($org['project']->id));
	}

	public function testUserWithoutMembershipCannotUseApp(): void
	{
		$nobody = $this->user('nobody');

		$this->assertFalse($this->make(AccessService::class)->canUseApp($nobody));
		$this->assertFalse($this->make(AccessService::class)->canUseApp(0));
	}

	public function testRolesLimitOperations(): void
	{
		$owner = $this->user('owner');
		$member = $this->user('member');
		$admin = $this->user('admin');
		$org = $this->organizationWithProject($owner, 'Firma');
		$this->addMember($org['organization'], $member, Role::Member);
		$this->addMember($org['organization'], $admin, Role::Admin);

		wp_set_current_user($member);
		$access = $this->make(AccessService::class);
		$access->project($org['project']->id, Ability::ImportData);
		$this->assertThrows(AccessDenied::class, fn () => $access->project($org['project']->id, Ability::UpdateProject));
		$this->assertThrows(AccessDenied::class, fn () => $access->organization($org['organization'], Ability::ManageMembers));

		wp_set_current_user($admin);
		$access->project($org['project']->id, Ability::UpdateProject);

		$service = $this->make(OrganizationService::class);
		$this->assertThrows(AccessDenied::class, fn () => $service->addMember($org['organization'], Role::Admin, get_userdata($member)->user_email, 'owner'));
		$this->assertThrows(AccessDenied::class, fn () => $service->removeMember($org['organization'], Role::Admin, $owner));
		$e = $this->assertThrows(\App\PennyPincher\Domain\Support\ValidationFailed::class, fn () => $service->removeMember($org['organization'], Role::Owner, $owner));
		$this->assertContains('co najmniej jednego właściciela', implode(' ', $e->errors));
	}

	public function testMetricQueriesAndImportsAreScopedToProject(): void
	{
		$alice = $this->user('alice');
		$bob = $this->user('bob');
		$a = $this->organizationWithProject($alice, 'Alicja');
		$b = $this->organizationWithProject($bob, 'Bartek');

		[$importA] = $this->import($a['project'], $alice, 'campaigns-en-period.csv');
		[$importB] = $this->import($b['project'], $bob, 'search-terms-pl.csv');

		$metrics = $this->make(MetricsRepository::class);
		$range = new DateRange('2026-09-01', '2026-09-30');

		$campaignsA = $metrics->campaigns($a['project']->id, $metrics->resolve('campaign_metrics', $a['project']->id, $range), [], 'spend', 'desc', 1, 50);
		$campaignsB = $metrics->campaigns($b['project']->id, $metrics->resolve('campaign_metrics', $b['project']->id, $range), [], 'spend', 'desc', 1, 50);

		$this->assertSame(3, $campaignsA->total);
		$this->assertSame(2, $campaignsB->total, 'Kampanie z raportu haseł projektu B.');
		$this->assertSame([], array_intersect(array_column($campaignsA->items, 'id'), array_column($campaignsB->items, 'id')));

		// Filtr kampanii z innego projektu nie ujawnia danych.
		$foreignCampaign = (int) $campaignsA->items[0]['id'];
		$terms = $metrics->searchTerms($b['project']->id, $metrics->resolve('search_term_metrics', $b['project']->id, $range), ['campaign_id' => $foreignCampaign], 'spend', 'desc', 1, 50);
		$this->assertSame(0, $terms->total);
		$this->assertNull($metrics->findCampaign($b['project']->id, $foreignCampaign));

		$imports = $this->make(ImportRepository::class);
		$this->assertNull($imports->findInProject($b['project']->id, $importA));
		$this->assertNull($imports->findInProject($a['project']->id, $importB));
	}

	public function testDeletingUserRemovesMemberships(): void
	{
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$owner = $this->user('owner');
		$member = $this->user('member');
		$org = $this->organizationWithProject($owner, 'Firma');
		$this->addMember($org['organization'], $member, Role::Member);

		wp_delete_user($member);

		$this->assertFalse($this->make(\App\PennyPincher\Repositories\OrganizationRepository::class)->hasAnyMembership($member));
	}
}
