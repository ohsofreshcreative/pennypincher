<?php

namespace App\PennyPincher\Infrastructure\Import\Writers;

final class ConversionActionWriter extends ReportWriter
{
	public function write(ImportContext $context, array $rows): WriteResult
	{
		$aggregated = [];
		$entities = [];

		foreach ($rows as $row) {
			$externalId = $row['conversion_action_id'] ?? null;
			$naturalKey = $externalId !== null ? self::key('id', $externalId) : self::key('name', mb_strtolower($row['conversion_action']));

			$actionId = $this->upsertEntity('conversion_actions', $context, $naturalKey, [
				'external_id' => $externalId,
				'name' => $row['conversion_action'],
				'category' => $row['category'] ?? null,
				'source' => $row['source'] ?? null,
				'status' => $row['status']['status'] ?? null,
				'status_raw' => $row['status']['raw'] ?? null,
				'include_in_conversions' => isset($row['include_in_conversions']) ? (int) $row['include_in_conversions'] : null,
			], ['external_id', 'category', 'source', 'status', 'status_raw', 'include_in_conversions']);

			$entities[$actionId] = true;

			$campaignScope = ! empty($row['campaign'])
				? $this->campaignId($context, $row['campaign'], $row['campaign_id'] ?? null)
				: 0;

			[$from, $to] = $this->periodFor($context, $row);
			$key = "{$actionId}|{$campaignScope}|{$from}|{$to}";
			$metrics = [
				'conversions' => $row['conversions'] ?? null,
				'all_conversions' => $row['all_conversions'] ?? null,
				'conversions_value_micros' => $row['conversions_value'] ?? null,
				'all_conversions_value_micros' => $row['all_conversions_value'] ?? null,
			];

			if (isset($aggregated[$key])) {
				foreach ($metrics as $metric => $value) {
					$aggregated[$key]['metrics'][$metric] = self::addNullable($aggregated[$key]['metrics'][$metric], $value);
				}
			} else {
				$aggregated[$key] = compact('actionId', 'campaignScope', 'from', 'to', 'metrics');
			}
		}

		$now = $this->db->now();
		$records = [];

		foreach ($aggregated as $item) {
			$records[] = [
				'project_id' => $context->projectId,
				'conversion_action_id' => $item['actionId'],
				'campaign_scope' => $item['campaignScope'],
				'granularity' => $context->daily ? 'day' : 'period',
				'date_from' => $item['from'],
				'date_to' => $item['to'],
				...$item['metrics'],
				'currency' => $context->currency,
				'import_id' => $context->importId,
				'created_at' => $now,
				'updated_at' => $now,
			];
		}

		$this->db->insertMany('conversion_metrics', $records, 400, 'ON DUPLICATE KEY UPDATE '
			. 'conversions = VALUES(conversions), all_conversions = VALUES(all_conversions), '
			. 'conversions_value_micros = VALUES(conversions_value_micros), all_conversions_value_micros = VALUES(all_conversions_value_micros), '
			. 'currency = VALUES(currency), import_id = VALUES(import_id), updated_at = VALUES(updated_at)');

		return new WriteResult(count($records), count($entities), count($rows) - count($records));
	}
}
