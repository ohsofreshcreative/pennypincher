<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Import\DateParser;
use App\PennyPincher\Domain\Import\InvalidValue;
use Tests\TestCase;

class DateParserTest extends TestCase
{
	public function testFormats(): void
	{
		$this->assertSame('2026-09-01', DateParser::parse('2026-09-01'));
		$this->assertSame('2026-09-01', DateParser::parse('01.09.2026'));
		$this->assertSame('2026-09-01', DateParser::parse('1 września 2026'));
		$this->assertSame('2026-09-01', DateParser::parse('1 wrz 2026'));
		$this->assertSame('2026-09-01', DateParser::parse('Sep 1, 2026'));
		$this->assertSame('2026-09-01', DateParser::parse('September 1, 2026'));
		$this->assertSame('2026-09-01', DateParser::parse('wt., 1 września 2026'));
		$this->assertSame('2026-09-01', DateParser::parse('46266'));
	}

	public function testSlashFormatDependsOnLanguage(): void
	{
		$this->assertSame('2026-09-01', DateParser::parse('09/01/2026', 'en'));
		$this->assertSame('2026-01-09', DateParser::parse('09/01/2026', 'pl'));
		$this->assertSame('2026-09-25', DateParser::parse('25/09/2026', 'en'));
	}

	public function testRange(): void
	{
		$this->assertSame(['2026-09-01', '2026-09-30'], DateParser::parseRange('1 września 2026 - 30 września 2026', 'pl'));
		$this->assertSame(['2026-09-01', '2026-09-30'], DateParser::parseRange('September 1, 2026 – September 30, 2026', 'en'));
		$this->assertNull(DateParser::parseRange('Raport kampanii'));
		$this->assertNull(DateParser::parseRange('All time'));
	}

	public function testInvalid(): void
	{
		$this->assertThrows(InvalidValue::class, fn () => DateParser::parse('31.02.2026'));
		$this->assertThrows(InvalidValue::class, fn () => DateParser::parse('jutro'));
		$this->assertNull(DateParser::parse('--'));
	}
}
