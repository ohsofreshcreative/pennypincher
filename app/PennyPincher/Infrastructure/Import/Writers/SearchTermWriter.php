<?php

namespace App\PennyPincher\Infrastructure\Import\Writers;

final class SearchTermWriter extends ReportWriter
{
	public function write(ImportContext $context, array $rows): WriteResult
	{
		$items = [];
		$entities = [];

		foreach ($rows as $row) {
			$campaignId = $this->campaignId($context, $row['campaign'], $row['campaign_id'] ?? null);
			$adGroupId = $this->adGroupId($context, $campaignId, $row['ad_group'] ?? null, $row['ad_group_id'] ?? null);
			$term = $row['search_term'];
			$keyword = $row['keyword'] ?? null;

			$naturalKey = self::key('term', implode('|', [
				$campaignId,
				$adGroupId ?? 0,
				mb_strtolower($term),
				$row['match_type'] ?? '',
				mb_strtolower((string) $keyword),
			]));

			$searchTermId = $this->upsertEntity('search_terms', $context, $naturalKey, [
				'campaign_id' => $campaignId,
				'ad_group_id' => $adGroupId,
				'term' => $term,
				'match_type' => $row['match_type'] ?? null,
				'keyword_text' => $keyword,
				'added_excluded' => $row['added_excluded'] ?? null,
			], ['added_excluded']);

			$entities[$searchTermId] = true;

			[$from, $to] = $this->periodFor($context, $row);
			$items[] = ['entity_id' => $searchTermId, 'date_from' => $from, 'date_to' => $to, 'metrics' => $this->performanceMetrics($row)];
		}

		$written = $this->writeMetrics('search_term_metrics', 'search_term_id', $context, $items);

		return new WriteResult($written, count($entities), count($items) - $written);
	}
}
