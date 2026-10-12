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

		$add($groupName);

		foreach (array_slice($keywords, 0, 4) as $keyword) {
			$add((string) $keyword);
		}

		foreach (array_slice($context->priorityTexts(), 0, 3) as $service) {
			$add($service);

			if ($location) {
				$add($service . ' ' . $location);
			}
		}

		foreach (array_slice(BusinessContext::lines($context->get('advantages')), 0, 4) as $advantage) {
			$add($advantage);
		}

		foreach (array_slice(self::CTAS, 0, 2) as $cta) {
			$add($cta);
		}

		$descriptions = [];

		foreach ([$context->businessDescription, implode('. ', BusinessContext::lines($context->get('advantages')))] as $text) {
			$text = self::truncate((string) $text, AdValidator::DESCRIPTION_MAX);

			if ($text !== '') {
				$descriptions[] = $text;
			}
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
