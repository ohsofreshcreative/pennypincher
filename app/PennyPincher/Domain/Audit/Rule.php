<?php

namespace App\PennyPincher\Domain\Audit;

/**
 * Reguła audytu: deterministyczna funkcja kontekstu → lista problemów.
 * Nowe reguły (Prompt 3+) dodaje się jako klasy w Rules/ i rejestruje w RuleRegistry.
 */
interface Rule
{
	public function id(): string;

	public function name(): string;

	public function description(): string;

	public function category(): string;

	/**
	 * @return array<int, Finding>
	 */
	public function evaluate(AuditContext $context): array;
}
