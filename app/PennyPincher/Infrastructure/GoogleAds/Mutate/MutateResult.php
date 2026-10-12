<?php

namespace App\PennyPincher\Infrastructure\GoogleAds\Mutate;

/**
 * Wynik żądania googleAds:mutate: resource names przyjętych operacji i błędy przypisane do operacji (wg indeksu).
 * `validateOnly` = żądanie tylko sprawdzało operacje (nic nie zapisano).
 */
final class MutateResult
{
	/** @var array<int, string> */
	public array $resourceNames = [];

	/** @var array<int, array{code: string, message: string}> */
	public array $errors = [];

	public ?string $requestError = null;

	public function __construct(public readonly bool $validateOnly, public readonly bool $httpOk, public readonly ?string $requestId)
	{
	}

	public function ok(int $index): bool
	{
		return ! isset($this->errors[$index]) && ($this->validateOnly || isset($this->resourceNames[$index]));
	}

	public function allOk(int $count): bool
	{
		for ($i = 0; $i < $count; $i++) {
			if (! $this->ok($i)) {
				return false;
			}
		}

		return true;
	}
}
