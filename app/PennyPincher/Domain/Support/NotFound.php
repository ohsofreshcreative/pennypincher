<?php

namespace App\PennyPincher\Domain\Support;

use RuntimeException;

/**
 * Zasób nie istnieje albo użytkownik nie ma do niego dostępu.
 * Celowo nie rozróżniamy tych przypadków, żeby nie ujawniać istnienia cudzych danych.
 */
class NotFound extends RuntimeException
{
}
