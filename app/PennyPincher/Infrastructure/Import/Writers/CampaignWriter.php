<?php

namespace App\PennyPincher\Infrastructure\Import\Writers;

final class CampaignWriter extends ReportWriter
{
	public function write(ImportContext $context, array $rows): WriteResult
	{
		$items = [];
		$entities = [];

		foreach ($rows as $row) {
			$campaignId = $this->campaignId($context, $row['campaign'], $row['campaign_id'] ?? null);
			$entities[$campaignId] = true;

			$this->updateCampaignAttributes($campaignId, $row);

			[$from, $to] = $this->periodFor($context, $row);
			$items[] = ['entity_id' => $campaignId, 'date_from' => $from, 'date_to' => $to, 'metrics' => $this->performanceMetrics($row)];
		}

		$written = $this->writeMetrics('campaign_metrics', 'campaign_id', $context, $items);

		return new WriteResult($written, count($entities), count($items) - $written);
	}

	/**
	 * Atrybuty kampanii nadpisujemy tylko wartościami obecnymi w eksporcie.
	 */
	private function updateCampaignAttributes(int $campaignId, array $row): void
	{
		$values = array_filter([
			'status' => $row['status']['status'] ?? null,
			'status_raw' => $row['status']['raw'] ?? null,
			'channel_type' => $row['campaign_type'] ?? null,
			'budget_micros' => $row['budget'] ?? null,
			'budget_period' => $row['budget_period'] ?? null,
			'bidding_strategy' => $row['bidding_strategy'] ?? null,
		], fn ($value) => $value !== null);

		if ($values !== []) {
			$this->db->update('campaigns', $values + ['updated_at' => $this->db->now()], ['id' => $campaignId]);
		}
	}
}
