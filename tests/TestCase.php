<?php

namespace Tests;

use RuntimeException;

class AssertionFailed extends RuntimeException
{
}

class SkipTest extends RuntimeException
{
}

abstract class TestCase
{
	public function setUp(): void
	{
	}

	public function tearDown(): void
	{
	}

	protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
	{
		if ($expected !== $actual) {
			throw new AssertionFailed(trim($message . ' Oczekiwano ' . var_export($expected, true) . ', otrzymano ' . var_export($actual, true) . '.'));
		}
	}

	protected function assertTrue(bool $condition, string $message = 'Warunek nie jest spełniony.'): void
	{
		if (! $condition) {
			throw new AssertionFailed($message);
		}
	}

	protected function assertFalse(bool $condition, string $message = 'Warunek powinien być fałszywy.'): void
	{
		$this->assertTrue(! $condition, $message);
	}

	protected function assertNull(mixed $value, string $message = ''): void
	{
		$this->assertSame(null, $value, $message);
	}

	protected function assertCount(int $count, array $items, string $message = ''): void
	{
		$this->assertSame($count, count($items), $message);
	}

	protected function assertContains(string $needle, string $haystack, string $message = ''): void
	{
		if (! str_contains($haystack, $needle)) {
			throw new AssertionFailed(trim($message . " Tekst nie zawiera „{$needle}”: " . mb_substr($haystack, 0, 300)));
		}
	}

	protected function assertEqualsWithDelta(float $expected, float $actual, float $delta, string $message = ''): void
	{
		if (abs($expected - $actual) > $delta) {
			throw new AssertionFailed(trim("{$message} Oczekiwano {$expected} ±{$delta}, otrzymano {$actual}."));
		}
	}

	/**
	 * @param class-string<\Throwable> $class
	 */
	protected function assertThrows(string $class, callable $callback, ?string $messageContains = null): \Throwable
	{
		try {
			$callback();
		} catch (\Throwable $e) {
			if (! $e instanceof $class) {
				throw new AssertionFailed("Oczekiwano wyjątku {$class}, otrzymano " . get_class($e) . ': ' . $e->getMessage());
			}

			if ($messageContains !== null) {
				$this->assertContains($messageContains, $e->getMessage());
			}

			return $e;
		}

		throw new AssertionFailed("Oczekiwano wyjątku {$class}.");
	}

	protected function fixture(string $name): string
	{
		return __DIR__ . '/fixtures/' . $name;
	}
}
