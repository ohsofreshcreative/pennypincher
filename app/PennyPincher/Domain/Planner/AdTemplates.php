<?php

namespace App\PennyPincher\Domain\Planner;

use App\PennyPincher\Domain\Projects\BusinessContext;

/**
 * Propozycje tekstów reklam z kontekstu biznesowego – wyłącznie z faktów wpisanych przez użytkownika
 * (usługi, przewagi, lokalizacja). Bez wymyślonych obietnic, certyfikatów, wyników czy opinii.
 * Teksty są punktem wyjścia do ręcznej edycji.
 */
final class AdTemplates
{
	public const CTAS = ['Poproś o wycenę', 'Umów konsultację', 'Skontaktuj się z nami', 'Sprawdź ofertę', 'Zamów online'];

	/**
	 * @return array{headlines: array<int, string>, descriptions: array<int, string>}
	 */
	public static function suggest(BusinessContext $context, string $groupName, array $keywords = [], ?string $location = null): array
	{
		$headlines = [];
		$add = function (string $text) use (&$headlines) {
			$text = trim((string) preg_replace('/\s+/u', ' ', $text));
			$text = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);

			if ($text !== '' && AdValidator::length($text) <= AdValidator::HEADLINE_MAX && ! in_array(mb_strtolower($text), array_map('mb_strtolower', $headlines), true)) {
				$headlines[] = $text;
			}
		};

		$services = array_slice(array_values(array_unique([...$context->priorityTexts(), ...BusinessContext::lines($context->get('products_services'))])), 0, 4);
		$advantages = array_slice(BusinessContext::lines($context->get('advantages')), 0, 6);

		$add($groupName);
		if ($location) {
			$add($groupName . ' ' . $location);
		}

		foreach (array_slice($keywords, 0, 8) as $keyword) {
			$add((string) $keyword);
			// Fraza za długa na nagłówek – bez ostatnich słów (np. „… cena”), nadal tylko słowa użytkownika.
			$add(self::truncate((string) $keyword, AdValidator::HEADLINE_MAX));
		}

		foreach ($services as $service) {
			$add($service);

			if ($location) {
				$add($service . ' ' . $location);
			}
		}

		foreach ($advantages as $advantage) {
			$add($advantage);
		}

		foreach (self::CTAS as $cta) {
			$add($cta);
		}

		$descriptions = [];
		$addDescription = function (string $text) use (&$descriptions) {
			$text = self::truncate($text, AdValidator::DESCRIPTION_MAX);
			if ($text !== '' && ! in_array($text, $descriptions, true)) {
				$descriptions[] = $text;
			}
		};

		$addDescription((string) $context->businessDescription);
		if ($advantages !== []) {
			$addDescription(implode('. ', $advantages) . '.');
		}
		// Szablony złożone wyłącznie z nazw usług, fraz, lokalizacji i wezwań do działania – bez nowych obietnic.
		$subject = mb_strtoupper(mb_substr($groupName, 0, 1)) . mb_substr($groupName, 1);
		$addDescription($subject . ($location ? ' – ' . $location : '') . '. ' . self::CTAS[0] . '.');
		if ($services !== []) {
			$addDescription(implode(', ', array_slice($services, 0, 3)) . '. ' . self::CTAS[1] . '.');
		}
		if (count($keywords) > 1) {
			$addDescription(mb_strtoupper(mb_substr((string) $keywords[0], 0, 1)) . mb_substr(implode(', ', array_slice($keywords, 0, 3)), 1) . '. ' . self::CTAS[2] . '.');
		}

		return ['headlines' => array_slice($headlines, 0, 15), 'descriptions' => array_slice($descriptions, 0, 4)];
	}

	/**
	 * Skrócenie do granicy słowa (bez dopisywania treści).
	 */
	public static function truncate(string $text, int $max): string
	{
		$text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

		if (AdValidator::length($text) <= $max) {
			return $text;
		}

		$cut = mb_substr($text, 0, $max);
		$space = mb_strrpos($cut, ' ');

		return rtrim($space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,;:-–');
	}
}
