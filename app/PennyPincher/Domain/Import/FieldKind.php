<?php

namespace App\PennyPincher\Domain\Import;

enum FieldKind: string
{
	case Text = 'text';
	case Id = 'id';
	case Integer = 'integer';
	case Money = 'money';
	case Decimal = 'decimal';
	case Date = 'date';
	case Status = 'status';
	case MatchType = 'match_type';
	case Boolean = 'boolean';
	case Currency = 'currency';

	public function isNumeric(): bool
	{
		return in_array($this, [self::Integer, self::Money, self::Decimal], true);
	}
}
