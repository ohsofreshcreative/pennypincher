<?php

namespace App\PennyPincher\Support;

use App\PennyPincher\Domain\Metrics\DateRange;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Repositories\MetricsRepository;
use Illuminate\Http\Request;

/**
 * Zakres dat z parametrów URL (?range=…&from=…&to=…). Ostatni wybór jest zapamiętywany per użytkownik i projekt.
 */
class DateRangeRequest
{
	private const META = 'pp_date_range';

	public function __construct(private MetricsRepository $metrics)
	{
	}

	public function resolve(Request $request, Project $project): DateRange
	{
		$userId = get_current_user_id();
		$saved = get_user_meta($userId, self::META, true);
		$saved = is_array($saved) ? ($saved[$project->id] ?? []) : [];

		$preset = (string) ($request->query('range') ?? $saved['range'] ?? 'all');
		$from = (string) ($request->query('from') ?? $saved['from'] ?? '');
		$to = (string) ($request->query('to') ?? $saved['to'] ?? '');

		if (! array_key_exists($preset, DateRange::PRESETS)) {
			$preset = 'all';
		}

		$range = match (true) {
			$preset === 'custom' && DateRange::isValidDate($from) && DateRange::isValidDate($to) => new DateRange($from, $to, 'custom'),
			$preset === 'custom', $preset === 'all' => $this->allData($project),
			default => DateRange::fromPreset($preset, $project->timezone()),
		};

		if ($request->query('range') !== null) {
			$all = get_user_meta($userId, self::META, true);
			$all = is_array($all) ? $all : [];
			$all[$project->id] = ['range' => $range->preset, 'from' => $range->fromString(), 'to' => $range->toString()];
			update_user_meta($userId, self::META, $all);
		}

		return $range;
	}

	private function allData(Project $project): DateRange
	{
		// „Wszystkie dane” = od najwcześniejszej do najpóźniejszej daty we wszystkich zaimportowanych raportach.
		$extent = null;

		foreach (['campaign_metrics', 'search_term_metrics', 'keyword_metrics', 'conversion_metrics'] as $table) {
			$tableExtent = $this->metrics->dataExtent($project->id, $table);

			if ($tableExtent !== null) {
				$extent = $extent === null ? $tableExtent : [min($extent[0], $tableExtent[0]), max($extent[1], $tableExtent[1])];
			}
		}

		if ($extent === null) {
			$fallback = DateRange::fromPreset('last_30', $project->timezone());

			return new DateRange($fallback->from, $fallback->to, 'all');
		}

		return new DateRange($extent[0], $extent[1], 'all');
	}
}
