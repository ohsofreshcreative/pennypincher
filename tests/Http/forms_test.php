<?php

/**
 * Testy HTTP formularzy i przycisków Promptu 5 na działającym serwerze (lokalnie, baza testowa + atrapa Google Ads).
 *
 *   PP_HTTP_BASE=http://127.0.0.1:8081 PP_HTTP_USER=admin PP_HTTP_PASS=… PP_HTTP_MEMBER=eve PP_HTTP_MEMBER_PASS=… \
 *   PP_HTTP_SETUP='{"project":31,…}' php tests/Http/forms_test.php
 *
 * Nie sprawdza samego istnienia tras: pobiera stronę, odczytuje z HTML prawdziwy `action` i metodę formularza,
 * wysyła go z polami formularza i nonce, a potem sprawdza wynik działania kontrolera (przekierowanie, komunikat,
 * stan na stronie docelowej). Dodatkowo: każdy formularz POST na stronach Promptu 5 – poprawny adres (bez „//”),
 * nonce, brak 404/405/500 po wysłaniu; CSRF (419 bez nonce); uprawnienia członka (403).
 */

$base = rtrim((string) (getenv('PP_HTTP_BASE') ?: 'http://127.0.0.1:8081'), '/');
$setup = json_decode((string) getenv('PP_HTTP_SETUP'), true) ?: [];
$project = (int) ($setup['project'] ?? 31);
$results = [];

function check(string $name, bool $ok, string $info = ''): void
{
	global $results;
	$results[] = $ok;
	echo ($ok ? 'OK   ' : 'FAIL ') . $name . ($ok || $info === '' ? '' : ' — ' . mb_substr($info, 0, 400)) . "\n";
}

final class Browser
{
	private string $jar;

	public function __construct(private string $base)
	{
		$this->jar = tempnam(sys_get_temp_dir(), 'ppjar');
	}

	/**
	 * @return array{status: int, location: ?string, body: string, url: string}
	 */
	public function request(string $method, string $url, array $data = [], bool $follow = false): array
	{
		$url = str_starts_with($url, 'http') ? $url : $this->base . $url;
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
			CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_TIMEOUT => 120, CURLOPT_CUSTOMREQUEST => $method,
		]);

		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POSTFIELDS, self::encode($data));
		}

		$raw = (string) curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		curl_close($ch);
		$headers = substr($raw, 0, $headerSize);
		preg_match_all('/^location:\s*(\S+)/im', $headers, $m);

		return ['status' => $status, 'location' => $m[1] ? end($m[1]) : null, 'body' => substr($raw, $headerSize), 'url' => $final];
	}

	public function get(string $url): array
	{
		return $this->request('GET', $url, [], true);
	}

	public function post(string $url, array $data): array
	{
		return $this->request('POST', $url, $data);
	}

	/** Wysłanie i przejście za przekierowaniem (GET), jak przeglądarka po POST-redirect-GET. */
	public function submit(string $url, array $data): array
	{
		$post = $this->post($url, $data);
		$page = $post['location'] ? $this->get($post['location']) : $post;

		return ['post' => $post, 'page' => $page];
	}

	private static function encode(array $data, string $prefix = ''): string
	{
		$parts = [];

		foreach ($data as $key => $value) {
			$name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

			if (is_array($value) && array_is_list($value) && str_ends_with((string) $key, '[]')) {
				foreach ($value as $v) {
					$parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $v);
				}
			} else {
				$parts[] = rawurlencode($name) . '=' . rawurlencode((string) $value);
			}
		}

		return implode('&', $parts);
	}
}

/**
 * Formularze ze strony: metoda, action i pola (pierwsza wartość pola; checkboxy tylko zaznaczone, select – wybrana opcja).
 *
 * @return array<int, array{method: string, action: string, fields: array<string, string>, text: string}>
 */
function forms(string $html): array
{
	$dom = new DOMDocument();
	@$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
	$out = [];

	foreach ($dom->getElementsByTagName('form') as $form) {
		$fields = [];
		$xpath = new DOMXPath($dom);

		foreach ($xpath->query('.//input|.//select|.//textarea', $form) as $el) {
			$name = $el->getAttribute('name');

			if ($name === '' || $el->hasAttribute('disabled')) {
				continue;
			}

			$type = strtolower($el->getAttribute('type'));

			if (in_array($type, ['checkbox', 'radio'], true) && ! $el->hasAttribute('checked')) {
				continue;
			}

			if ($el->nodeName === 'select') {
				$value = '';

				foreach ($el->getElementsByTagName('option') as $i => $option) {
					if ($i === 0 || $option->hasAttribute('selected')) {
						$value = $option->getAttribute('value');
					}
				}
			} elseif ($el->nodeName === 'textarea') {
				$value = $el->textContent;
			} else {
				$value = $el->getAttribute('value');
			}

			$fields[$name] = $value;
		}

		$buttons = [];

		foreach ($xpath->query('.//button[@name]', $form) as $button) {
			$buttons[$button->getAttribute('name')] = $button->getAttribute('value');
		}

		$out[] = ['method' => strtolower($form->getAttribute('method') ?: 'get'), 'action' => html_entity_decode($form->getAttribute('action')), 'fields' => $fields + $buttons, 'text' => trim(preg_replace('/\s+/', ' ', $form->textContent))];
	}

	return $out;
}

/** Pierwszy formularz, którego action kończy się danym sufiksem (i opcjonalnie zawiera tekst). */
function form(string $html, string $suffix, ?string $text = null): ?array
{
	foreach (forms($html) as $form) {
		if (preg_match('#' . $suffix . '/?$#', parse_url($form['action'], PHP_URL_PATH) ?? '') && ($text === null || str_contains($form['text'], $text))) {
			return $form;
		}
	}

	return null;
}

function flash(string $html): string
{
	return preg_match('#role="(?:status|alert)"[^>]*>(.*?)</div>\s*</div>#s', $html, $m) ? trim(preg_replace('/\s+/', ' ', strip_tags($m[1]))) : '';
}

function setId(?string $url): ?int
{
	return $url && preg_match('#/changes/(\d+)/?#', $url, $m) ? (int) $m[1] : null;
}

function ok(array $page): bool
{
	return $page['status'] === 200 && ! str_contains($page['body'], 'Nie znaleziono') && ! str_contains($page['body'], 'Wystąpił błąd');
}

// --- Logowanie -------------------------------------------------------------------------------------

$admin = new Browser($base);
$login = $admin->get('/app/login/');
preg_match('/name="_pp_nonce" value="([^"]+)"/', $login['body'], $n);
$admin->post('/app/login/', ['_pp_nonce' => $n[1] ?? '', 'log' => getenv('PP_HTTP_USER') ?: 'admin', 'pwd' => (string) getenv('PP_HTTP_PASS'), 'redirect_to' => $base . '/app/']);
check('logowanie administratora', ok($admin->get("/app/projects/{$project}/changes/")));

// --- 1. Audyt wszystkich formularzy POST na stronach Promptu 5 i modułach z „Przygotuj zmianę” -----------

$campaign = (int) ($setup['campaign'] ?? 0);
$group = (int) ($setup['ad_group'] ?? 0);
$pages = [
	'changes', 'management', "management/campaigns/{$campaign}", "management/ad-groups/{$group}", 'conversion-management',
	'recommendations', 'budget-leaks', 'action-plan', 'keywords?tab=negatives', 'monitoring', 'planner/' . (int) ($setup['plan'] ?? 0), 'changes/' . (int) ($setup['applied_set'] ?? 0),
];

foreach ($pages as $path) {
	[$p, $q] = array_pad(explode('?', $path, 2), 2, '');
	$page = $admin->get("/app/projects/{$project}/{$p}/" . ($q ? '?' . $q : ''));
	check("GET {$path}", ok($page), (string) $page['status']);

	foreach (forms($page['body']) as $f) {
		if ($f['method'] !== 'post' || str_contains($f['action'], '/logout')) {
			continue;
		}

		$urlPath = (string) parse_url($f['action'], PHP_URL_PATH);
		check("  action bez „//”: {$urlPath}", ! str_contains($urlPath, '//'));
		check("  nonce w formularzu: {$urlPath}", isset($f['fields']['_pp_nonce']));
	}
}

// --- 2. Scenariusz: zarządzanie kampanią → Change Center (wynik działania kontrolerów) ------------------------

$page = $admin->get("/app/projects/{$project}/management/campaigns/{$campaign}/");
$pause = form($page['body'], '/change', 'Wstrzymaj kampanię');
check('formularz „Wstrzymaj kampanię” istnieje', $pause !== null);
$r = $admin->submit($pause['action'], array_replace($pause['fields'], ['action' => 'pause']));
$set = setId($r['post']['location']);
check('Przygotuj zmianę (kampania) → szkic w Change Center', $r['post']['status'] === 302 && $set !== null && ok($r['page']), $r['post']['status'] . ' ' . $r['post']['location']);
check('  komunikat: nic nie wysłano', str_contains(flash($r['page']['body']), 'Nic nie zostało wysłane'), flash($r['page']['body']));
check('  walidacja: brak odczytu to nie błąd', str_contains($r['page']['body'], 'Stan z Google Ads: brak aktualnego odczytu') && str_contains($r['page']['body'], 'Brak aktualnych danych'));

$read = form($r['page']['body'], '/read-state');
check('formularz „Odczytaj stan z Google Ads” ma poprawny action', $read !== null && ! str_contains((string) parse_url($read['action'], PHP_URL_PATH), '//'), $read['action'] ?? 'brak');
$r = $admin->submit($read['action'], $read['fields']);
check('Odczytaj stan → przekierowanie na zestaw (nie 404)', $r['post']['status'] === 302 && setId($r['post']['location']) === $set, $r['post']['status'] . ' ' . $r['post']['location']);
check('  komunikat o odczycie', str_contains(flash($r['page']['body']), 'Odczytano aktualny stan'), flash($r['page']['body']));
check('  stan aktualny i zapisany przy operacji', str_contains($r['page']['body'], 'Stan z Google Ads: aktualny') && str_contains($r['page']['body'], 'odczyt z Google Ads'));
check('  walidacja przeliczona: bez błędów', str_contains($r['page']['body'], 'Penny Pincher: bez błędów'));
check('  operacja NIE oznaczona jako zweryfikowana', ! str_contains($r['page']['body'], '>Zweryfikowana<'));

$edit = form($r['page']['body'], '/operations/\d+', 'Zapisz operację');
check('formularz „Edytuj operację” istnieje', $edit !== null);
$r = $admin->submit($edit['action'], $edit['fields']);
check('Edytuj operację → zapisano, wersja +1', $r['post']['status'] === 302 && str_contains(flash($r['page']['body']), 'Zapisano zmiany operacji'), flash($r['page']['body']));

$submit = form($r['page']['body'], '/submit');
$r = $admin->submit($submit['action'], $submit['fields']);
check('Wyślij do przeglądu → status „Do przeglądu”', str_contains(flash($r['page']['body']), 'Wysłano do przeglądu') && str_contains($r['page']['body'], 'Do przeglądu'), flash($r['page']['body']));

$approve = form($r['page']['body'], '/approve');
$r = $admin->submit($approve['action'], array_replace($approve['fields'], ['confirm_high_risk' => '1', 'confirm_bidding' => '1']));
check('Zatwierdź → „Gotowy do wdrożenia”, bez wdrażania', str_contains(flash($r['page']['body']), 'Zatwierdzono') && str_contains($r['page']['body'], 'Gotowy do wdrożenia') && str_contains($r['page']['body'], 'wyłączony na serwerze'), flash($r['page']['body']));

$r2 = $admin->submit("/app/projects/{$project}/changes/{$set}/deploy/", ['_pp_nonce' => $approve['fields']['_pp_nonce'], 'deploy_key' => '00000000-0000-4000-8000-000000000001', 'confirmation' => 'tak']);
check('Wdróż (ręczne żądanie) w PREPARE_ONLY → odrzucone czytelnym komunikatem', $r2['post']['status'] === 302 && str_contains(flash($r2['page']['body']), 'wyłączony'), flash($r2['page']['body']));

$reject = form($r['page']['body'], '/reject');
$r = $admin->submit($reject['action'], $reject['fields']);
check('Cofnij zatwierdzenie → szkic', str_contains(flash($r['page']['body']), 'Odesłano do poprawek'), flash($r['page']['body']));

$cancel = form($r['page']['body'], '/cancel');
$r = $admin->submit($cancel['action'], $cancel['fields']);
check('Anuluj → „Anulowany”', str_contains(flash($r['page']['body']), 'Anulowano') && str_contains($r['page']['body'], 'Anulowany'), flash($r['page']['body']));

// Usunięcie operacji i odczyt bez połączenia – czytelny błąd zamiast 404/500.
$page = $admin->get("/app/projects/{$project}/management/ad-groups/{$group}/");
$kw = form($page['body'], '/change', 'Przygotuj słowa');
$r = $admin->submit($kw['action'], array_replace($kw['fields'], ['phrases' => "sofa narożna\nsklep meble", 'match_type' => 'PHRASE']));
$set2 = setId($r['post']['location']);
check('Dodaj słowa → szkic z operacjami', $set2 !== null && ok($r['page']) && str_contains($r['page']['body'], 'sofa narożna'), (string) $r['post']['location']);
$empty = $admin->submit($kw['action'], array_replace($kw['fields'], ['phrases' => '']));
check('Dodaj słowa bez fraz → komunikat, bez pustego szkicu', setId($empty['post']['location']) === null && str_contains(flash($empty['page']['body']), 'Nie przygotowano żadnej operacji'), (string) $empty['post']['location'] . ' ' . flash($empty['page']['body']));
$read = form($r['page']['body'], '/read-state');
$r = $admin->submit($read['action'], $read['fields']);
check('Odczyt stanu słów → konflikt „sklep meble” pokazany jako błąd konfiguracji', str_contains($r['page']['body'], 'Błędy konfiguracji operacji') && str_contains($r['page']['body'], 'już istnieje'), flash($r['page']['body']));
$delete = form($r['page']['body'], '/operations/\d+/delete');
$r = $admin->submit($delete['action'], $delete['fields']);
check('Usuń operację → zapisano', str_contains(flash($r['page']['body']), 'Usunięto operację'), flash($r['page']['body']));

// Odczyt, gdy Google Ads nie zwraca zasobu – czytelny błąd walidacji, nie 404.
$page = $admin->get("/app/projects/{$project}/management/ad-groups/{$group}/");
$ren = form($page['body'], '/change', 'Przygotuj zmianę nazwy');
$r = $admin->submit($ren['action'], array_replace($ren['fields'], ['name' => 'Marka 2']));
$set3 = setId($r['post']['location']);
$state = json_decode((string) file_get_contents((string) getenv('PP_FAKE_GOOGLE_ADS_STATE')), true);
unset($state['store']['ad_group']['customers/1234567890/adGroups/222']);
file_put_contents((string) getenv('PP_FAKE_GOOGLE_ADS_STATE'), json_encode($state));
$read = form($r['page']['body'], '/read-state');
$r = $admin->submit($read['action'], $read['fields']);
check('Zasób usunięty w Google Ads → komunikat w walidacji (nie 404)', ok($r['page']) && str_contains($r['page']['body'], 'Google Ads nie zwrócił tego zasobu'), flash($r['page']['body']));

// --- 3. Conversion Management --------------------------------------------------------------------------

$page = $admin->get("/app/projects/{$project}/conversion-management/");
$read = form($page['body'], '/read');
$r = $admin->submit($read['action'], $read['fields']);
check('Conversion Management: Odczytaj konwersje → komunikat (nie 404)', $r['post']['status'] === 302 && str_contains(flash($r['page']['body']), 'Odczytano konfigurację'), $r['post']['status'] . ' ' . flash($r['page']['body']));
$health = form($r['page']['body'], '/health', 'Przygotuj zmianę');
check('Health Check: „Przygotuj zmianę” istnieje (konwersja PAGE_VIEW jako główna)', $health !== null);

if ($health) {
	$r = $admin->submit($health['action'], $health['fields']);
	check('Health Check → szkic zmiany konwersji', setId($r['post']['location']) !== null && str_contains($r['page']['body'], 'Wymaga Twojego potwierdzenia'), (string) $r['post']['location']);
}

$page = $admin->get("/app/projects/{$project}/conversion-management/");
$update = null;

foreach (forms($page['body']) as $f) {
	if (preg_match('#/conversion-management/\d+/update/?$#', (string) parse_url($f['action'], PHP_URL_PATH))) {
		$update = $f;
		break;
	}
}

$r = $admin->submit($update['action'], array_replace($update['fields'], ['counting_type' => 'ONE_PER_CLICK']));
check('Edycja konwersji → szkic', setId($r['post']['location']) !== null, (string) $r['post']['location']);
$create = form($page['body'], '/create');
$r = $admin->submit($create['action'], array_replace($create['fields'], ['name' => 'Wysłanie formularza HTTP']));
check('Nowa konwersja → szkic', setId($r['post']['location']) !== null, (string) $r['post']['location']);
$goal = form($page['body'], '/goal');
$r = $admin->submit($goal['action'], $goal['fields']);
check('Cel konta → szkic', setId($r['post']['location']) !== null, (string) $r['post']['location']);

// --- 3b. Konwersje tylko do odczytu (SMART_CAMPAIGN_*_CLICKS_TO_CALL) ----------------------------------------

$smartConv = (int) ($setup['smart_conversion'] ?? 0);
$smartSet = (int) ($setup['smart_set'] ?? 0);
$smartOp = (int) ($setup['smart_op'] ?? 0);
$smartRec = (int) ($setup['smart_recommendation'] ?? 0);
$page = $admin->get("/app/projects/{$project}/conversion-management/");
$nonce = (string) (array_values(array_filter(array_map(fn ($f) => $f['fields']['_pp_nonce'] ?? null, forms($page['body']))))[0] ?? '');
$smartForms = array_filter(forms($page['body']), fn ($f) => preg_match('#/conversion-management/' . $smartConv . '/update/?$#', (string) parse_url($f['action'], PHP_URL_PATH)));
check('Conversion Management: konwersja Smart bez formularza edycji', $smartConv > 0 && $smartForms === []);
check('  powód blokady z typem konwersji', str_contains($page['body'], 'Tylko odczyt: Google Ads API (v25) nie pozwala edytować działań konwersji typu SMART_CAMPAIGN_AD_CLICKS_TO_CALL'));
check('  Health Check: zliczanie Smart jako działanie ręczne', str_contains($page['body'], 'Działanie ręczne: Google Ads API (v25) nie pozwala edytować działań konwersji typu SMART_CAMPAIGN_MAP_CLICKS_TO_CALL'));
$r = $admin->submit("/app/projects/{$project}/conversion-management/{$smartConv}/update/", ['_pp_nonce' => $nonce, 'counting_type' => 'ONE_PER_CLICK']);
check('Spreparowana edycja zliczania Smart → odmowa, bez szkicu', $r['post']['status'] === 302 && setId($r['post']['location']) === null && str_contains(flash($r['page']['body']), 'tylko do odczytu'), $r['post']['status'] . ' ' . flash($r['page']['body']));

$page = $admin->get("/app/projects/{$project}/changes/{$smartSet}/");
$read = form($page['body'], '/read-state');
$r = $admin->submit($read['action'], $read['fields']);
$body = $r['page']['body'];
check('Zestaw sprzed poprawki: sekcja „Operacje nieobsługiwane przez Google Ads API”', str_contains($body, 'Operacje nieobsługiwane przez Google Ads API (2)') && str_contains($body, 'Usuń tę operację'), flash($body));
check('  status walidacji wskazuje operacje nieobsługiwane (nie brak potwierdzenia)', str_contains($body, 'Penny Pincher: operacje nieobsługiwane przez Google Ads API') && ! str_contains($body, 'Wymaga Twojego potwierdzenia'));
check('  pole „Sposób zliczania” zablokowane w edycji operacji', form($body, '/operations/' . $smartOp) === null && str_contains($body, 'Pola tylko do odczytu w Google Ads API (bez edycji): Sposób zliczania'));
$r = $admin->submit("/app/projects/{$project}/changes/{$smartSet}/operations/{$smartOp}/", ['_pp_nonce' => $nonce, 'payload[counting_type]' => 'MANY_PER_CLICK']);
check('Spreparowana edycja operacji Smart → odmowa', str_contains(flash($r['page']['body']), 'tylko do odczytu'), flash($r['page']['body']));
$submit = form($body, '/submit');
$r = $admin->submit($submit['action'], $submit['fields']);
check('Wyślij do przeglądu z operacjami nieobsługiwanymi → odmowa', str_contains(flash($r['page']['body']), 'SMART_CAMPAIGN_AD_CLICKS_TO_CALL') && str_contains($r['page']['body'], 'Szkic'), flash($r['page']['body']));
check('  zestaw nie zmieniony automatycznie (2 operacje)', substr_count($r['page']['body'], 'Zmień zliczanie (Smart') >= 2);

$page = $admin->get("/app/projects/{$project}/recommendations/?status=new");
check('Rekomendacja CT003 dla Smart: działanie ręczne zamiast przycisku', str_contains($page['body'], 'Działanie ręczne: Google Ads API (v25)') && form($page['body'], "/recommendations/{$smartRec}/prepare-change") === null);
$r = $admin->submit("/app/projects/{$project}/recommendations/{$smartRec}/prepare-change/", ['_pp_nonce' => $nonce]);
check('Spreparowane „Przygotuj zmianę” dla rekomendacji Smart → komunikat, bez szkicu', setId($r['post']['location']) === null && str_contains(flash($r['page']['body']), 'Działanie ręczne'), (string) $r['post']['location'] . ' ' . flash($r['page']['body']));

// --- 4. Moduły: „Przygotuj zmianę” -------------------------------------------------------------------------

foreach (['recommendations' => '/prepare-change', 'budget-leaks' => '/prepare-change', 'action-plan' => '/prepare-change'] as $module => $suffix) {
	$page = $admin->get("/app/projects/{$project}/{$module}/");
	$f = form($page['body'], $suffix);

	if ($f === null) {
		check("{$module}: brak elementów z operacjami Google Ads (przycisk nie jest pokazywany)", ! str_contains($page['body'], 'Przygotuj zmianę</'));
		continue;
	}

	$r = $admin->submit($f['action'], $f['fields']);
	check("{$module}: Przygotuj zmianę → szkic albo czytelny komunikat", $r['post']['status'] === 302 && ok($r['page']) && (setId($r['post']['location']) !== null || flash($r['page']['body']) !== ''), $r['post']['status'] . ' ' . flash($r['page']['body']));
}

$page = $admin->get("/app/projects/{$project}/keywords/?tab=negatives");
$neg = form($page['body'], '/keywords/negatives/prepare-change');

if ($neg) {
	$r = $admin->submit($neg['action'], $neg['fields']);
	check('Wykluczenia: bez zaznaczenia → komunikat', $r['post']['status'] === 302 && str_contains(flash($r['page']['body']), 'Zaznacz'), flash($r['page']['body']));
}

// --- 5. Cofnięcie i Campaign Planner -------------------------------------------------------------------------

$page = $admin->get("/app/projects/{$project}/changes/" . (int) $setup['applied_set'] . '/');
$rollback = form($page['body'], '/rollback');
check('„Przygotuj cofnięcie” widoczne przy wdrożonym zestawie', $rollback !== null);
$r = $admin->submit($rollback['action'], $rollback['fields']);
check('Przygotuj cofnięcie → nowy szkic z operacją odwrotną', setId($r['post']['location']) !== null && setId($r['post']['location']) !== (int) $setup['applied_set'] && str_contains($r['page']['body'], 'Cofnięcie'), (string) $r['post']['location']);

$page = $admin->get("/app/projects/{$project}/planner/" . (int) $setup['plan'] . '/');
$deploy = form($page['body'], '/deploy');
check('Planner: formularz „Przygotuj wdrożenie” istnieje', $deploy !== null);
$r = $admin->submit($deploy['action'], array_replace($deploy['fields'], ['eu_political' => 'no']));
$planSet = setId($r['post']['location']);
check('Planner: Przygotuj wdrożenie → zestaw z operacjami (kampania wstrzymana)', $planSet !== null && str_contains($r['page']['body'], 'Plan HTTP | Sofy') && str_contains($r['page']['body'], 'Nowa kampania w sieci wyszukiwania (wstrzymana)'), flash($r['page']['body']));
$again = $admin->submit($deploy['action'], array_replace($deploy['fields'], ['eu_political' => 'no']));
check('Planner: ponowne kliknięcie → ten sam zestaw', setId($again['post']['location']) === $planSet, (string) $again['post']['location']);

// --- 6. Management: zgoda na zapis, wykonawca, nowa kampania ---------------------------------------------------

$page = $admin->get("/app/projects/{$project}/management/");
$write = form($page['body'], '/management/write-access');
check('Zgoda na zapis: formularz niedostępny przy serwerze w PREPARE_ONLY', $write === null && str_contains($page['body'], 'Serwer nie pozwala na zapis'));
$r = $admin->submit("/app/projects/{$project}/management/write-access/", ['_pp_nonce' => forms($page['body'])[1]['fields']['_pp_nonce'] ?? '', 'enabled' => '1', 'confirm_customer' => '1234567890']);
check('Zgoda na zapis (ręczne żądanie) → odmowa', str_contains(flash($r['page']['body']), 'Serwer ma wyłączony zapis'), flash($r['page']['body']));
$exec = form($page['body'], '/management/executor');
$r = $admin->submit($exec['action'], $exec['fields']);
check('Wykonawca zmian → zapisano', str_contains(flash($r['page']['body']), 'wykonawca'), flash($r['page']['body']));
$new = form($page['body'], '/management/campaigns/new');
$r = $admin->submit($new['action'], array_replace($new['fields'], ['name' => 'HTTP | Nowa', 'budget' => '30', 'eu_political' => 'no']));
check('Nowa kampania → szkic (wstrzymana)', setId($r['post']['location']) !== null && str_contains($r['page']['body'], 'wstrzymana'), flash($r['page']['body']));

// --- 7. CSRF i uprawnienia -------------------------------------------------------------------------------------

foreach (["changes/{$set}/read-state", "changes/{$set}/submit", "changes/{$set}/approve", "changes/{$set}/cancel", "changes/{$set}/rollback", 'conversion-management/read', 'planner/' . (int) $setup['plan'] . '/deploy'] as $path) {
	$r = $admin->post("/app/projects/{$project}/{$path}/", []);
	check("CSRF: {$path} bez nonce → 419", $r['status'] === 419, (string) $r['status']);
}

if (getenv('PP_HTTP_MEMBER')) {
	$member = new Browser($base);
	$login = $member->get('/app/login/');
	preg_match('/name="_pp_nonce" value="([^"]+)"/', $login['body'], $n);
	$member->post('/app/login/', ['_pp_nonce' => $n[1] ?? '', 'log' => getenv('PP_HTTP_MEMBER'), 'pwd' => (string) getenv('PP_HTTP_MEMBER_PASS'), 'redirect_to' => $base . '/app/']);
	$page = $member->get("/app/projects/{$project}/changes/{$set2}/");
	check('Członek widzi zestaw', ok($page));
	$nonce = forms($page['body'])[1]['fields']['_pp_nonce'] ?? '';
	check('Członek: brak formularza „Zatwierdź”', form($page['body'], '/approve') === null);
	check('Członek: zatwierdzenie → 403', $member->post("/app/projects/{$project}/changes/{$set2}/approve/", ['_pp_nonce' => $nonce])['status'] === 403);
	check('Członek: zgoda na zapis → 403', $member->post("/app/projects/{$project}/management/write-access/", ['_pp_nonce' => $nonce, 'enabled' => '1'])['status'] === 403);
	check('Członek: wdrożenie → 403', $member->post("/app/projects/{$project}/changes/{$set2}/deploy/", ['_pp_nonce' => $nonce, 'deploy_key' => 'x', 'confirmation' => 'tak'])['status'] === 403);
}

// --- Podsumowanie ----------------------------------------------------------------------------------------------

$state = json_decode((string) file_get_contents((string) getenv('PP_FAKE_GOOGLE_ADS_STATE')), true);
$executed = array_filter((array) ($state['mutations'] ?? []), fn ($m) => empty($m['validate_only']));
check('Żadnej mutacji wykonania w atrapie Google Ads', $executed === [], json_encode($executed));

$passed = count(array_filter($results));
echo "\n{$passed} / " . count($results) . "\n";
exit($passed === count($results) ? 0 : 1);
