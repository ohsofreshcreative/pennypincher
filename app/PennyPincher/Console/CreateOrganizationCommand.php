<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Domain\Access\Role;
use App\PennyPincher\Repositories\OrganizationRepository;
use App\PennyPincher\Services\OrganizationService;
use Illuminate\Console\Command;

class CreateOrganizationCommand extends Command
{
	protected $signature = 'pp:organization:create {name : Nazwa organizacji} {--owner= : E-mail lub login właściciela (konto WordPress)}';

	protected $description = 'Penny Pincher: tworzy organizację i przypisuje właściciela';

	public function handle(OrganizationService $service): int
	{
		$identifier = (string) $this->option('owner');
		$user = is_email($identifier) ? get_user_by('email', $identifier) : get_user_by('login', $identifier);

		if (! $user) {
			$this->error('Podaj istniejącego użytkownika: --owner=email@domena.pl');

			return self::FAILURE;
		}

		$id = $service->create((string) $this->argument('name'), $user->ID);
		$this->info("Utworzono organizację #{$id}, właściciel: {$user->user_email}");

		return self::SUCCESS;
	}
}
