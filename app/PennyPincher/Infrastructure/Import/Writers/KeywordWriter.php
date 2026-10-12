<?php

namespace App\PennyPincher\Infrastructure\Import\Writers;

use App\PennyPincher\Domain\Import\ValueNormalizer;

final class KeywordWriter extends ReportWriter
{
	public function write(ImportContext $context, array $rows): WriteResult
	{
		$items = [];
		$entities = [];

		foreach ($rows as $row) {
			$campaignId = $this->campaignId($context, $row['campaign'], $row['campaign_id'] ?? null);
			$adGroupId = $this->adGroupId($context, $campaignId, $row['ad_group'] ?? null, $row['ad_group_id'] ?? null);

			[$text, $matchFromSyntax] = ValueNormalizer::keywordText($row['keyword']);
			$matchType = $row['match_type'] ?? $matchFromSyntax ?? 'broad';
			$externalId = $row['keyword_id'] ?? null;

			$naturalKey = $externalId !== null && $adGroupId !== null
				? self::key('id', $adGroupId . '|' . $externalId)
				: self::key('keyword', implode('|', [$campaignId, $adGroupId ?? 0, mb_strtolower($text), $matchType]));

			$keywordId = $this->upsertEntity('keywords', $context, $naturalKey, [
				'campaign_id' => $campaignId,
				'ad_group_id' => $adGroupId,
				'external_id' => $externalId,
				'text' => $text,
				'match_type' => $matchType,
				'status' => $row['status']['status'] ?? null,
				'status_raw' => $row['status']['raw'] ?? null,
				'quality_score' => $row['quality_score'] ?? null,
				'max_cpc_micros' => $row['max_cpc'] ?? null,
			], ['external_id', 'status', 'status_raw', 'quality_score', 'max_cpc_micros']);

			$entities[$keywordId] = true;

			[$from, $to] = $this->periodFor($context, $row);
			$items[] = ['entity_id' => $keywordId, 'date_from' => $from, 'date_to' => $to, 'metrics' => $this->performanceMetrics($row)];
		}

		$written = $this->writeMetrics('keyword_metrics', 'keyword_id', $context, $items);

		return new WriteResult($written, count($entities), count($items) - $written);
	}
}
