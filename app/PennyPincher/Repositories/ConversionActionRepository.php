<?php

namespace App\PennyPincher\Repositories;

use App\PennyPincher\Domain\Conversions\ConversionClass;
use App\PennyPincher\Infrastructure\Database\Db;

/**
 * Działania powodujące konwersję z klasyfikacją biznesową (ustawioną przez użytkownika albo proponowaną).
 */
class ConversionActionRepository
{
	public function __construct(private Db $db)
	{
	}

	/**
	 * @return array<int, array<string, mixed>> z kluczami effective_class, effective_goal, classification_source
	 */
	public function forProject(int $projectId): array
	{
		$rows = $this->db->select(
			'SELECT id, name, external_id, category, category_code, type, status, include_in_conversions, primary_for_goal, counting_type,
				business_class, business_goal, classified_by, classified_at
			FROM ' . $this->db->table('conversion_actions') . ' WHERE project_id = ? ORDER BY status = ?, name',
			[$projectId, 'removed']
		);

		return array_map([self::class, 'withEffectiveClass'], $rows);
	}

	public function find(int $projectId, int $actionId): ?array
	{
		$row = $this->db->first('SELECT * FROM ' . $this->db->table('conversion_actions') . ' WHERE id = ? AND project_id = ?', [$actionId, $projectId]);

		return $row ? self::withEffectiveClass($row) : null;
	}

	public function classify(int $projectId, int $actionId, ConversionClass $class, bool $goal, int $userId): void
	{
		$this->db->update('conversion_actions', [
			'business_class' => $class->value,
			'business_goal' => $goal ? 1 : 0,
			'classified_by' => $userId,
			'classified_at' => $this->db->now(),
		], ['id' => $actionId, 'project_id' => $projectId]);
	}

	/**
	 * Klasa i „cel biznesowy” obowiązujące w analizie: ręczne ustawienie albo propozycja z kategorii Google Ads.
	 */
	public static function withEffectiveClass(array $row): array
	{
		$manual = ConversionClass::tryFrom((string) ($row['business_class'] ?? ''));
		$class = $manual ?? ConversionClass::suggestFromCategory($row['category_code'] ?? null);
		$goal = $row['business_goal'] !== null && $row['business_goal'] !== ''
			? (bool) $row['business_goal']
			: ($class->isLead() || $class === ConversionClass::Sale);

		$row['id'] = (int) $row['id'];
		$row['effective_class'] = $class->value;
		$row['effective_goal'] = $goal;
		$row['classification_source'] = $manual !== null ? 'manual' : 'suggested';

		return $row;
	}
}
