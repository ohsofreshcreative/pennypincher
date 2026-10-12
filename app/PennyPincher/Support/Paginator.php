<?php

namespace App\PennyPincher\Support;

final class Paginator
{
	public readonly int $page;

	public readonly int $perPage;

	public readonly int $lastPage;

	/** @var array<int, array<string, mixed>> */
	public array $items = [];

	public function __construct(public readonly int $total, int $page, int $perPage)
	{
		$this->perPage = max(1, min(200, $perPage));
		$this->lastPage = max(1, (int) ceil($total / $this->perPage));
		$this->page = max(1, min($page, $this->lastPage));
	}

	public function offset(): int
	{
		return ($this->page - 1) * $this->perPage;
	}

	public function withItems(array $items): self
	{
		$this->items = $items;

		return $this;
	}

	public function firstItem(): int
	{
		return $this->total === 0 ? 0 : $this->offset() + 1;
	}

	public function lastItem(): int
	{
		return min($this->total, $this->offset() + $this->perPage);
	}

	/**
	 * Numery stron do wyświetlenia (z null jako „…”).
	 *
	 * @return array<int, int|null>
	 */
	public function window(int $around = 1): array
	{
		$pages = [];

		for ($i = 1; $i <= $this->lastPage; $i++) {
			if ($i === 1 || $i === $this->lastPage || abs($i - $this->page) <= $around) {
				$pages[] = $i;
			} elseif (end($pages) !== null) {
				$pages[] = null;
			}
		}

		return $pages;
	}
}
