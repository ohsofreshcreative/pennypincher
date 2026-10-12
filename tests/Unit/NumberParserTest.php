<?php

namespace Tests\Unit;

use App\PennyPincher\Domain\Import\InvalidValue;
use App\PennyPincher\Domain\Import\NumberParser;
use Tests\TestCase;

class NumberParserTest extends TestCase
{
	public function testPolishFormats(): void
	{
		$this->assertSame('1234.56', NumberParser::parse('1 234,56', 'comma'));
		$this->assertSame('1234.56', NumberParser::parse("1\u{00A0}234,56 zł", 'comma'));
		$this->assertSame('1234.56', NumberParser::parse("1\u{202F}234,56\u{00A0}PLN", null));
		$this->assertSame('1234567.8', NumberParser::parse('1.234.567,80', 'comma'));
		$this->assertSame('0.5', NumberParser::parse('0,50', 'comma'));
		$this->assertSame('3000', NumberParser::parse('3.000', 'comma'));
	}

	public function testEnglishFormats(): void
	{
		$this->assertSame('1234.56', NumberParser::parse('1,234.56', 'dot'));
		$this->assertSame('12345', NumberParser::parse('12,345', 'dot'));
		$this->assertSame('1234567', NumberParser::parse('1,234,567', null));
		$this->assertSame('2.21', NumberParser::parse('$2.21', 'dot'));
		$this->assertSame('1.234', NumberParser::parse('1.234', 'dot'));
	}

	public function testPercentAndNegative(): void
	{
		$this->assertSame('5.49', NumberParser::parse('5.49%', 'dot'));
		$this->assertSame('-12.5', NumberParser::parse('(12,50)', 'comma'));
		$this->assertSame('-3', NumberParser::parse('−3', null));
		$this->assertSame('0', NumberParser::parse('0,00', 'comma'));
	}

	public function testMissingValuesAreNullNotZero(): void
	{
		foreach (['--', '', ' ', '—', 'n/a', '< 10%'] as $value) {
			$this->assertNull(NumberParser::parse($value, 'comma'), "Wartość „{$value}”");
		}

		$this->assertNull(NumberParser::parse(null));
	}

	public function testInvalidValuesThrow(): void
	{
		$this->assertThrows(InvalidValue::class, fn () => NumberParser::parse('abc'));
		$this->assertThrows(InvalidValue::class, fn () => NumberParser::parse('12a'));
		$this->assertThrows(InvalidValue::class, fn () => NumberParser::parse('1,2,3,4', 'dot'));
	}

	public function testDetectsDecimalSeparator(): void
	{
		$this->assertSame('comma', NumberParser::detectDecimalSeparator(['1 234,56', '12', '0,5']));
		$this->assertSame('dot', NumberParser::detectDecimalSeparator(['1,234.56', '12', '2.21']));
		$this->assertNull(NumberParser::detectDecimalSeparator(['12', '1 000', null]));
	}
}
