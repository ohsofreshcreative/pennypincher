<?php

namespace App\PennyPincher\Console;

use App\PennyPincher\Infrastructure\Security\SecretBox;
use Illuminate\Console\Command;

/**
 * Generuje klucz szyfrowania tokenów. Klucz wypisujemy tylko na terminal – nie zapisujemy go nigdzie.
 */
class GenerateKeyCommand extends Command
{
	protected $signature = 'pp:key:generate';

	protected $description = 'Penny Pincher: generuje klucz PP_ENCRYPTION_KEY (do wp-config.php, poza repozytorium)';

	public function handle(): int
	{
		$this->line("define('PP_ENCRYPTION_KEY', '" . SecretBox::generateKey() . "');");
		$this->comment('Wklej powyższą linię do wp-config.php na serwerze. Nie commituj jej i nie wysyłaj nikomu.');
		$this->comment('Przy zmianie klucza przenieś poprzedni do PP_ENCRYPTION_KEY_PREVIOUS (patrz docs/SECURITY.md).');

		return self::SUCCESS;
	}
}
