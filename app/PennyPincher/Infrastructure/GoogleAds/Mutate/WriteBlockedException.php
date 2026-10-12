<?php

namespace App\PennyPincher\Infrastructure\GoogleAds\Mutate;

use RuntimeException;

/**
 * Zapis lub walidacja w Google Ads zablokowane przez tryb pracy (konfiguracja serwera, zgoda dla konta, uprawnienia).
 * Nic nie zostało wysłane do Google Ads.
 */
class WriteBlockedException extends RuntimeException
{
}
