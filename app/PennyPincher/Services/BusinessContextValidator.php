<?php

namespace App\PennyPincher\Services;

use App\PennyPincher\Domain\Import\InvalidValue;
use App\PennyPincher\Domain\Import\NumberParser;
use App\PennyPincher\Domain\Metrics\Money;
use App\PennyPincher\Domain\Projects\BusinessContext;
use App\PennyPincher\Domain\Support\ValidationFailed;

/**
 * Walidacja formularza kontekstu biznesowego. Zwraca kolumny projektu i JSON kontekstu.
 */
class BusinessContextValidator
{
	private const TEXT_FIELDS = ['products_services', 'priority_services', 'ideal_customer', 'excluded_audiences', 'target_locations', 'advantages', 'secondary_goals'];

	/**
	 * @return array<string, mixed> wartości do ProjectRepository::update()
	 * @throws ValidationFailed
	 */
	public function validate(array $input): array
	{
		$errors = [];
		$values = [];
		$context = [];

		foreach (['business_description' => 'Opis firmy', 'target_customer' => 'Grupa docelowa'] as $field => $label) {
			$values[$field] = $this->text($input, $field, $label, $errors);
		}

		$url = trim((string) ($input['landing_page_url'] ?? ''));

		if ($url === '') {
			$values['landing_page_url'] = null;
		} elseif (mb_strlen($url) > 2048 || ! in_array(strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) || ! filter_var($url, FILTER_VALIDATE_URL)) {
			$errors['landing_page_url'] = 'Podaj poprawny adres strony docelowej zaczynający się od https://.';
		} else {
			$values['landing_page_url'] = esc_url_raw($url, ['http', 'https']);
		}

		foreach (self::TEXT_FIELDS as $field) {
			$text = $this->text($input, $field, BusinessContext::FIELDS[$field], $errors);

			if ($text !== null) {
				$context[$field] = $text;
			}
		}

		$language = (string) ($input['language'] ?? 'pl');

		if (! array_key_exists($language, BusinessContext::LANGUAGES)) {
			$errors['language'] = 'Wybierz język z listy.';
		}

		$context['language'] = $language;

		$goal = (string) ($input['primary_goal'] ?? '');

		if ($goal !== '' && ! array_key_exists($goal, BusinessContext::GOALS)) {
			$errors['primary_goal'] = 'Wybierz cel z listy.';
		} elseif ($goal !== '') {
			$context['primary_goal'] = $goal;
		}

		$location = (int) ($input['keyword_location_code'] ?? 2616);

		if (! array_key_exists($location, BusinessContext::LOCATIONS)) {
			$errors['keyword_location_code'] = 'Wybierz kraj z listy.';
		}

		$context['keyword_location_code'] = $location;

		$average = trim((string) ($input['average_value'] ?? ''));

		if ($average !== '') {
			try {
				$number = NumberParser::parse($average, NumberParser::COMMA);
				$micros = $number === null ? null : Money::toMicros($number);

				if ($micros === null || $micros < 0) {
					throw new InvalidValue('negative');
				}

				$context['average_value_micros'] = $micros;
			} catch (InvalidValue|\InvalidArgumentException) {
				$errors['average_value'] = 'Podaj kwotę, np. 15000 albo 15 000,00.';
			}
		}

		if ($errors !== []) {
			throw new ValidationFailed($errors);
		}

		$values['business_context'] = wp_json_encode($context);

		return $values;
	}

	private function text(array $input, string $field, string $label, array &$errors): ?string
	{
		$text = trim(sanitize_textarea_field((string) ($input[$field] ?? '')));

		if (mb_strlen($text) > 5000) {
			$errors[$field] = "{$label}: maksymalnie 5000 znaków.";
		}

		return $text === '' ? null : $text;
	}
}
