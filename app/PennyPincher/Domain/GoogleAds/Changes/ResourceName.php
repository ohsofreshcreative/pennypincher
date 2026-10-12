<?php

namespace App\PennyPincher\Domain\GoogleAds\Changes;

/**
 * Resource names Google Ads (customers/{customer}/{kolekcja}/{id}) – budowanie i rozbiór.
 */
final class ResourceName
{
	public static function customer(string $resourceName): ?string
	{
		return preg_match('#^customers/(\d+)/#', $resourceName, $m) ? $m[1] : null;
	}

	/**
	 * Ostatni segment (np. „123”, „456~789”).
	 */
	public static function id(string $resourceName): string
	{
		$parts = explode('/', $resourceName);

		return (string) end($parts);
	}

	/**
	 * Identyfikator zasobu nadrzędnego dla nazw złożonych („grupa~kryterium”, „grupa~reklama”, „kampania~kryterium”).
	 */
	public static function parentId(string $resourceName): ?string
	{
		$id = self::id($resourceName);

		return str_contains($id, '~') ? explode('~', $id, 2)[0] : null;
	}

	public static function childId(string $resourceName): string
	{
		$id = self::id($resourceName);

		return str_contains($id, '~') ? explode('~', $id, 2)[1] : $id;
	}

	public static function campaign(string $customerId, string $id): string
	{
		return "customers/{$customerId}/campaigns/{$id}";
	}

	public static function adGroup(string $customerId, string $id): string
	{
		return "customers/{$customerId}/adGroups/{$id}";
	}

	public static function keyword(string $customerId, string $adGroupId, string $criterionId): string
	{
		return "customers/{$customerId}/adGroupCriteria/{$adGroupId}~{$criterionId}";
	}

	public static function campaignCriterion(string $customerId, string $campaignId, string $criterionId): string
	{
		return "customers/{$customerId}/campaignCriteria/{$campaignId}~{$criterionId}";
	}

	public static function conversionAction(string $customerId, string $id): string
	{
		return "customers/{$customerId}/conversionActions/{$id}";
	}

	public static function isValid(string $resourceName, ?string $customerId = null): bool
	{
		return (bool) preg_match('#^customers/' . ($customerId !== null ? preg_quote($customerId, '#') : '\d+') . '/[A-Za-z]+/[A-Za-z0-9~_-]+$#', $resourceName);
	}
}
