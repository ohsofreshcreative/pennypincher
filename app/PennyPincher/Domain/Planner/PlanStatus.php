<?php

namespace App\PennyPincher\Domain\Planner;

final class PlanStatus
{
	public const DRAFT = 'draft';

	public const REVIEW = 'review';

	public const APPROVED = 'approved';

	public const ARCHIVED = 'archived';

	public const LABELS = [
		self::DRAFT => 'Szkic',
		self::REVIEW => 'Do przeglądu',
		self::APPROVED => 'Zatwierdzony',
		self::ARCHIVED => 'Zarchiwizowany',
	];

	/** Statusy, w których plan można edytować (zatwierdzony i zarchiwizowany są zablokowane). */
	public const EDITABLE = [self::DRAFT, self::REVIEW];

	public const LEVELS = ['plan' => 'Cały plan', 'campaign' => 'Kampania', 'ad_group' => 'Grupa reklam'];
}
