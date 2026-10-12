<?php

namespace App\PennyPincher\Domain\Keywords;

/**
 * Statusy decyzji o frazie (niezależne od przebiegów analiz, synchronizacji i pobrań DataForSEO).
 */
final class KeywordStatus
{
	public const NEW = 'new';

	public const TO_REVIEW = 'to_review';

	public const ACCEPTED = 'accepted';

	public const REJECTED = 'rejected';

	public const IN_PLAN = 'in_plan';

	public const USED = 'used';

	public const LABELS = [
		self::NEW => 'Nowa',
		self::TO_REVIEW => 'Do sprawdzenia',
		self::ACCEPTED => 'Zaakceptowana',
		self::REJECTED => 'Odrzucona',
		self::IN_PLAN => 'W planie kampanii',
		self::USED => 'Wykorzystana',
	];

	/** Statusy, które użytkownik może ustawić ręcznie (W planie / Wykorzystana wynikają z działań). */
	public const MANUAL = [self::NEW, self::TO_REVIEW, self::ACCEPTED, self::REJECTED];
}
