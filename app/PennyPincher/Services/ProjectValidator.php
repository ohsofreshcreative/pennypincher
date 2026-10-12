<?php

namespace App\PennyPincher\Services;

use App\PennyPincher\Domain\Import\InvalidValue;
use App\PennyPincher\Domain\Import\NumberParser;
use App\PennyPincher\Domain\Metrics\Money;
use App\PennyPincher\Domain\Projects\Project;
use App\PennyPincher\Domain\Support\ValidationFailed;

/**
 * Walidacja i normalizacja danych projektu z formularza (tworzenie i ustawienia).
 */
class ProjectValidator
{
	public const CURRENCIES = ['PLN', 'EUR', 'USD', 'GBP', 'CHF', 'CZK', 'SEK', 'NOK', 'DKK', 'HUF', 'RON', 'UAH'];

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed> wartości gotowe do zapisu w bazie
	 * @throws ValidationFailed
	 */
	public function validate(array $input): array
	{
		$errors = [];
		$values = [];

		$name = trim((string) ($input['name'] ?? ''));

		if ($name === '') {
			$errors['name'] = 'Podaj nazwę projektu.';
		} elseif (mb_strlen($name) > 191) {
			$errors['name'] = 'Nazwa może mieć maksymalnie 191 znaków.';
		}

		$values['name'] = $name;
		$values['platform'] = array_key_exists((string) ($input['platform'] ?? 'google_ads'), Project::PLATFORMS) ? (string) ($input['platform'] ?? 'google_ads') : null;

		if ($values['platform'] === null) {
			$errors['platform'] = 'Wybierz obsługiwaną platformę.';
		}

		$currency = strtoupper(trim((string) ($input['currency'] ?? '')));

		if (! in_array($currency, self::CURRENCIES, true)) {
			$errors['currency'] = 'Wybierz walutę konta reklamowego.';
		}

		$values['currency'] = $currency;

		$timezone = trim((string) ($input['timezone'] ?? ''));

		if (! in_array($timezone, timezone_identifiers_list(), true)) {
			$errors['timezone'] = 'Wybierz strefę czasową konta reklamowego.';
		}

		$values['timezone'] = $timezone;

		foreach (['website_url' => 'adres strony', 'landing_page_url' => 'adres landing page'] as $field => $label) {
			$url = trim((string) ($input[$field] ?? ''));

			if ($url === '') {
				$values[$field] = null;
				continue;
			}

			$scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));

			if (mb_strlen($url) > 2048 || ! in_array($scheme, ['http', 'https'], true) || ! filter_var($url, FILTER_VALIDATE_URL)) {
				$errors[$field] = "Podaj poprawny {$label} zaczynający się od https://.";
			}

			$values[$field] = esc_url_raw($url, ['http', 'https']);
		}

		foreach (['business_description' => 'Opis działalności', 'target_customer' => 'Opis klienta docelowego'] as $field => $label) {
			$text = trim(sanitize_textarea_field((string) ($input[$field] ?? '')));

			if (mb_strlen($text) > 5000) {
				$errors[$field] = "{$label} może mieć maksymalnie 5000 znaków.";
			}

			$values[$field] = $text === '' ? null : $text;
		}

		$values['monthly_budget_micros'] = $this->money($input, 'monthly_budget', 'Budżet miesięczny', false, $errors);
		$values['min_project_value_micros'] = $this->money($input, 'min_project_value', 'Minimalna wartość projektu', true, $errors);
		$values['max_project_value_micros'] = $this->money($input, 'max_project_value', 'Maksymalna wartość projektu', false, $errors);

		if (
			! isset($errors['max_project_value']) && $values['max_project_value_micros'] !== null
			&& $values['min_project_value_micros'] !== null && $values['max_project_value_micros'] < $values['min_project_value_micros']
		) {
			$errors['max_project_value'] = 'Maksymalna wartość nie może być mniejsza niż minimalna.';
		}

		$status = (string) ($input['status'] ?? 'active');

		if (! array_key_exists($status, Project::STATUSES)) {
			$errors['status'] = 'Wybierz status projektu.';
		}

		$values['status'] = $status;

		if ($errors !== []) {
			throw new ValidationFailed($errors);
		}

		return $values;
	}

	private function money(array $input, string $field, string $label, bool $required, array &$errors): ?int
	{
		$raw = trim((string) ($input[$field] ?? ''));

		if ($raw === '') {
			if ($required) {
				$errors[$field] = "Podaj: {$label}.";
			}

			return null;
		}

		try {
			$number = NumberParser::parse($raw, NumberParser::COMMA);
			$micros = $number === null ? null : Money::toMicros($number);
		} catch (InvalidValue|\InvalidArgumentException) {
			$errors[$field] = "{$label}: podaj kwotę, np. 3000 albo 3 000,00.";

			return null;
		}

		if ($micros !== null && $micros < 0) {
			$errors[$field] = "{$label} nie może być ujemna.";
		}

		return $micros;
	}
}
