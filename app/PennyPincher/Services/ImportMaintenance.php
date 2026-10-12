<?php

namespace App\PennyPincher\Services;

use App\PennyPincher\Infrastructure\Database\Db;
use App\PennyPincher\Repositories\ImportRepository;

/**
 * Usuwa surowe wiersze importów, które nie zostały zatwierdzone (porzucone podglądy).
 */
class ImportMaintenance
{
	public function __construct(private Db $db)
	{
	}

	public function pruneStaged(int $olderThanDays = 3): int
	{
		$cutoff = gmdate('Y-m-d H:i:s', time() - $olderThanDays * DAY_IN_SECONDS);
		$imports = $this->db->table('imports');

		$ids = $this->db->column(
			"SELECT id FROM {$imports} WHERE status = ? AND updated_at < ?",
			[ImportRepository::STATUS_UPLOADED, $cutoff]
		);

		foreach ($ids as $id) {
			$this->db->transaction(function () use ($id, $imports) {
				$this->db->statement('DELETE FROM ' . $this->db->table('import_rows') . ' WHERE import_id = ?', [(int) $id]);
				$this->db->statement("UPDATE {$imports} SET status = ?, updated_at = ? WHERE id = ?", [ImportRepository::STATUS_CANCELLED, $this->db->now(), (int) $id]);
			});
		}

		return count($ids);
	}
}
