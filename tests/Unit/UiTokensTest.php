<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Pilnuje skali typografii aplikacji: drobne teksty tylko przez tokeny z pp-app.css, nie wartości arbitralne.
 */
class UiTokensTest extends TestCase
{
	public function testSmallTextUsesScaleTokens(): void
	{
		$root = dirname(__DIR__, 2) . '/resources';
		$files = [
			...glob($root . '/views/app/{,*/,*/*/}*.blade.php', GLOB_BRACE),
			...glob($root . '/views/components/pp/*.blade.php'),
			$root . '/css/pp-app.css',
		];
		$this->assertTrue(count($files) > 40, 'Nie znaleziono widoków aplikacji.');

		$offenders = [];
		foreach ($files as $file) {
			if (preg_match_all('~text-\[(1[1-3](?:\.5)?|15)px\]~', (string) file_get_contents($file), $m)) {
				$offenders[] = basename($file) . ': ' . implode(', ', array_unique($m[0]));
			}
		}

		$this->assertSame([], $offenders, 'Użyj text-2xs / text-caption / text-meta / text-body.');

		$css = (string) file_get_contents($root . '/css/pp-app.css');
		foreach (['--text-2xs', '--text-caption', '--text-meta', '--text-body'] as $token) {
			$this->assertContains($token . ':', $css);
		}
	}
}
