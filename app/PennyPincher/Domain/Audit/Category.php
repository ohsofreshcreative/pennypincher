<?php

namespace App\PennyPincher\Domain\Audit;

final class Category
{
	public const CONVERSION_TRACKING = 'conversion_tracking';

	public const CAMPAIGN_SETTINGS = 'campaign_settings';

	public const BUDGET_PACING = 'budget_pacing';

	public const DATA_QUALITY = 'data_quality';

	public const PERFORMANCE = 'performance';

	public const LABELS = [
		self::CONVERSION_TRACKING => 'Pomiar konwersji',
		self::CAMPAIGN_SETTINGS => 'Ustawienia kampanii',
		self::BUDGET_PACING => 'Tempo wydawania budżetu',
		self::DATA_QUALITY => 'Jakość danych',
		self::PERFORMANCE => 'Skuteczność',
	];
}
