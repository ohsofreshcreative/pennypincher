<?php

namespace App\PennyPincher\Infrastructure\Database;

use Closure;
use Throwable;
use wpdb;

/**
 * Cienka warstwa nad $wpdb dla tabel aplikacji (prefiks `{$wpdb->prefix}pp_`).
 *
 * Używamy $wpdb zamiast Illuminate\Database, bo w LocalWP połączenie PDO często nie widzi
 * socketu MySQL (Local ustawia tylko `mysqli.default_socket`), a $wpdb działa zawsze tam, gdzie WordPress.
 *
 * Zapytania piszemy z placeholderami `?`. Wartości są escapowane przez mysqli_real_escape_string
 * (ten sam mechanizm co wpdb::prepare), ale – w odróżnieniu od wpdb::prepare – poprawnie obsługujemy NULL.
 */
class Db
{
	/**
	 * Poziom zagnieżdżenia transakcji jest wspólny dla wszystkich instancji, bo wszystkie korzystają
	 * z tego samego połączenia $wpdb. Drugi START TRANSACTION zatwierdziłby po cichu poprzednią transakcję.
	 */
	private static int $transactionLevel = 0;

	public function __construct(private ?wpdb $wpdb = null)
	{
	}

	public function wpdb(): wpdb
	{
		return $this->wpdb ??= $GLOBALS['wpdb'];
	}

	public function table(string $name): string
	{
		return $this->wpdb()->prefix . 'pp_' . $name;
	}

	/**
	 * Wstawia parametry w miejsca `?`.
	 */
	public function bind(string $sql, array $params = []): string
	{
		if ($params === []) {
			return $sql;
		}

		$params = array_values($params);
		$index = 0;

		$bound = preg_replace_callback('/\?/', function () use (&$index, $params) {
			if (! array_key_exists($index, $params)) {
				throw new DatabaseException('Za mało parametrów dla zapytania SQL.');
			}

			return $this->quote($params[$index++]);
		}, $sql);

		if ($index !== count($params)) {
			throw new DatabaseException('Za dużo parametrów dla zapytania SQL.');
		}

		return $bound;
	}

	public function quote(mixed $value): string
	{
		if ($value === null) {
			return 'NULL';
		}

		if (is_bool($value)) {
			return $value ? '1' : '0';
		}

		if (is_int($value)) {
			return (string) $value;
		}

		if (is_float($value)) {
			if (! is_finite($value)) {
				throw new DatabaseException('Nieprawidłowa wartość liczbowa.');
			}

			return "'" . rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') . "'";
		}

		if ($value instanceof \DateTimeInterface) {
			$value = $value->format('Y-m-d H:i:s');
		}

		return "'" . $this->wpdb()->_real_escape((string) $value) . "'";
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function select(string $sql, array $params = []): array
	{
		$rows = $this->run(fn (wpdb $db, string $query) => $db->get_results($query, ARRAY_A), $sql, $params);

		return is_array($rows) ? $rows : [];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function first(string $sql, array $params = []): ?array
	{
		$row = $this->run(fn (wpdb $db, string $query) => $db->get_row($query, ARRAY_A), $sql, $params);

		return is_array($row) ? $row : null;
	}

	public function scalar(string $sql, array $params = []): mixed
	{
		return $this->run(fn (wpdb $db, string $query) => $db->get_var($query), $sql, $params);
	}

	/**
	 * @return array<int, mixed>
	 */
	public function column(string $sql, array $params = []): array
	{
		return $this->run(fn (wpdb $db, string $query) => $db->get_col($query), $sql, $params) ?: [];
	}

	/**
	 * Zwraca liczbę zmienionych wierszy.
	 */
	public function statement(string $sql, array $params = []): int
	{
		return (int) $this->run(fn (wpdb $db, string $query) => $db->query($query), $sql, $params);
	}

	public function insert(string $table, array $values): int
	{
		$columns = array_keys($values);
		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s)',
			$this->table($table),
			implode(', ', array_map(fn ($c) => "`{$c}`", $columns)),
			implode(', ', array_fill(0, count($columns), '?'))
		);

		$this->statement($sql, array_values($values));

		return (int) $this->wpdb()->insert_id;
	}

	/**
	 * Wstawia wiele wierszy jednym zapytaniem (w paczkach).
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	public function insertMany(string $table, array $rows, int $chunk = 500, string $suffix = ''): void
	{
		if ($rows === []) {
			return;
		}

		$columns = array_keys(reset($rows));
		$columnSql = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));

		foreach (array_chunk($rows, $chunk) as $batch) {
			$values = [];

			foreach ($batch as $row) {
				$values[] = '(' . implode(', ', array_map(fn ($c) => $this->quote($row[$c] ?? null), $columns)) . ')';
			}

			$this->statement(sprintf('INSERT INTO %s (%s) VALUES %s %s', $this->table($table), $columnSql, implode(', ', $values), $suffix));
		}
	}

	public function update(string $table, array $values, array $where): int
	{
		$set = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($values)));
		$conditions = implode(' AND ', array_map(fn ($c) => "`{$c}` = ?", array_keys($where)));

		return $this->statement(
			sprintf('UPDATE %s SET %s WHERE %s', $this->table($table), $set, $conditions),
			[...array_values($values), ...array_values($where)]
		);
	}

	/**
	 * Transakcja z obsługą zagnieżdżeń (SAVEPOINT).
	 *
	 * @template T
	 * @param Closure(): T $callback
	 * @return T
	 */
	public function transaction(Closure $callback): mixed
	{
		$this->begin();

		try {
			$result = $callback();
			$this->commit();

			return $result;
		} catch (Throwable $e) {
			$this->rollback();

			throw $e;
		}
	}

	public function begin(): void
	{
		if (self::$transactionLevel === 0) {
			$this->statement('START TRANSACTION');
		} else {
			$this->statement('SAVEPOINT pp_sp_' . self::$transactionLevel);
		}

		self::$transactionLevel++;
	}

	public function commit(): void
	{
		self::$transactionLevel = max(0, self::$transactionLevel - 1);

		if (self::$transactionLevel === 0) {
			$this->statement('COMMIT');
		} else {
			$this->statement('RELEASE SAVEPOINT pp_sp_' . self::$transactionLevel);
		}
	}

	public function rollback(): void
	{
		self::$transactionLevel = max(0, self::$transactionLevel - 1);

		if (self::$transactionLevel === 0) {
			$this->statement('ROLLBACK');
		} else {
			$this->statement('ROLLBACK TO SAVEPOINT pp_sp_' . self::$transactionLevel);
		}
	}

	public function transactionLevel(): int
	{
		return self::$transactionLevel;
	}

	public function now(): string
	{
		return gmdate('Y-m-d H:i:s');
	}

	private function run(Closure $callback, string $sql, array $params): mixed
	{
		$db = $this->wpdb();
		$query = $this->bind($sql, $params);

		$suppressed = $db->suppress_errors(true);
		$db->last_error = '';

		try {
			$result = $callback($db, $query);
		} finally {
			$db->suppress_errors($suppressed);
		}

		if ($db->last_error !== '') {
			throw new DatabaseException($db->last_error, $query);
		}

		return $result;
	}
}
