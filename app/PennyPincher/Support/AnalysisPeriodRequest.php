<?php

namespace App\PennyPincher\Support;

use App\PennyPincher\Domain\Analysis\AnalysisPeriod;
use App\PennyPincher\Domain\Projects\Project;
use Illuminate\Http\Request;

/**
 * Okres analizy z parametrów URL (?period=…&from=…&to=…), zapamiętywany per użytkownik i projekt.
 */
class AnalysisPeriodRequest
{
	private const META = 'pp_analysis_period';

	public function resolve(Request $request, Project $project): AnalysisPeriod
	{
		$userId = get_current_user_id();
		$saved = get_user_meta($userId, self::META, true);
		$saved = is_array($saved) ? ($saved[$project->id] ?? []) : [];

		$preset = (string) ($request->input('period') ?? $saved['period'] ?? AnalysisPeriod::DEFAULT);
		$from = (string) ($request->input('from') ?? $saved['from'] ?? '');
		$to = (string) ($request->input('to') ?? $saved['to'] ?? '');

		$period = $preset === 'custom'
			? (AnalysisPeriod::custom($from, $to, $project->timezone()) ?? AnalysisPeriod::fromPreset(AnalysisPeriod::DEFAULT, $project->timezone()))
			: AnalysisPeriod::fromPreset($preset, $project->timezone());

		if ($request->input('period') !== null && $userId > 0) {
			$all = get_user_meta($userId, self::META, true);
			$all = is_array($all) ? $all : [];
			$all[$project->id] = ['period' => $period->preset, 'from' => $period->range->fromString(), 'to' => $period->range->toString()];
			update_user_meta($userId, self::META, $all);
		}

		return $period;
	}

	/**
	 * Parametry URL okresu (do linków i formularzy).
	 *
	 * @return array<string, string>
	 */
	public static function query(AnalysisPeriod $period): array
	{
		return $period->preset === 'custom'
			? ['period' => 'custom', 'from' => $period->range->fromString(), 'to' => $period->range->toString()]
			: ['period' => $period->preset];
	}
}
