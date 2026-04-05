<?php
/**
 * WoningCheck Proxy — fetches data from external sources that need CORS bypass or POST requests.
 *
 * Endpoints:
 *   ?source=bag&postcode=2292CR&huisnummer=2          — BAG WFS property data
 *   ?source=walter_city&city=wateringen                — Walter Living city stats
 *   ?source=walter_buurt&gemeente=westland&plaats=wateringen&buurt=essellanden&code=BU17830611 — Walter buurt sold listings
 *   POST ?source=claude  (JSON body with property data)  — Claude AI valuation
 */

// Load .env if exists
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$source = $_GET['source'] ?? '';

switch ($source) {

    case 'bag':
        echo fetchBAG($_GET['postcode'] ?? '', $_GET['huisnummer'] ?? '');
        break;

    case 'walter_city':
        echo fetchWalterCity($_GET['city'] ?? '');
        break;

    case 'walter_buurt':
        echo fetchWalterBuurt(
            $_GET['gemeente'] ?? '',
            $_GET['plaats'] ?? '',
            $_GET['buurt'] ?? '',
            $_GET['code'] ?? ''
        );
        break;

    case 'woz':
        echo fetchWOZ($_GET['id'] ?? '');
        break;

    case 'energielabel':
        echo fetchEnergielabel($_GET['bag_id'] ?? '');
        break;

    case 'claude':
        $input = json_decode(file_get_contents('php://input'), true);
        echo fetchClaudeValuation($input ?? []);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown source. Use: bag, walter_city, walter_buurt']);
}

// ── BAG WFS (POST with XML filter) ──────────────────────────────────────────

function fetchBAG($postcode, $huisnummer) {
    $postcode = preg_replace('/\s+/', '', strtoupper($postcode));
    $huisnummer = intval($huisnummer);
    if (!$postcode || !$huisnummer) {
        return json_encode(['error' => 'postcode and huisnummer required']);
    }

    $filter = "<Filter xmlns='http://www.opengis.net/ogc'>"
        . "<And>"
        . "<PropertyIsEqualTo><PropertyName>postcode</PropertyName><Literal>{$postcode}</Literal></PropertyIsEqualTo>"
        . "<PropertyIsEqualTo><PropertyName>huisnummer</PropertyName><Literal>{$huisnummer}</Literal></PropertyIsEqualTo>"
        . "</And>"
        . "</Filter>";

    $url = 'https://service.pdok.nl/lv/bag/wfs/v2_0?'
        . http_build_query([
            'service' => 'WFS',
            'version' => '2.0.0',
            'request' => 'GetFeature',
            'typeName' => 'bag:verblijfsobject',
            'count' => 1,
            'outputFormat' => 'application/json',
            'Filter' => $filter,
        ]);

    $json = curlGet($url);
    $data = json_decode($json, true);

    if (!$data || empty($data['features'])) {
        return json_encode(['error' => 'Geen BAG-data gevonden', 'raw' => $data]);
    }

    $props = $data['features'][0]['properties'];
    return json_encode([
        'oppervlakte' => $props['oppervlakte'] ?? null,
        'bouwjaar' => $props['bouwjaar'] ?? null,
        'gebruiksdoel' => $props['gebruiksdoel'] ?? null,
        'status' => $props['status'] ?? null,
        'pandstatus' => $props['pandstatus'] ?? null,
    ]);
}

// ── Walter Living city stats ─────────────────────────────────────────────────

function fetchWalterCity($city) {
    $city = strtolower(trim($city));
    if (!$city) return json_encode(['error' => 'city required']);

    $url = "https://walterliving.com/city/{$city}";
    $html = curlGet($url);

    if (!$html || strlen($html) < 500) {
        return json_encode(['error' => 'Geen Walter-data gevonden voor ' . $city]);
    }

    // Extract all <strong> numbers from the market stats paragraph
    preg_match_all('/<strong>([\d.]+)<\/strong>/', $html, $matches);
    $nums = $matches[1] ?? [];

    // Pattern: verkocht_vorig, verkocht_huidig, trans_vorig, trans_huidig, m2_vorig, m2_huidig, vraag_vorig, vraag_huidig, woz_verschil
    if (count($nums) >= 8) {
        return json_encode([
            'city' => $city,
            'verkocht_vorig_kwartaal' => intval(str_replace('.', '', $nums[0])),
            'verkocht_huidig_kwartaal' => intval(str_replace('.', '', $nums[1])),
            'gem_transactieprijs_vorig' => intval(str_replace('.', '', $nums[2])) * 1000,
            'gem_transactieprijs_huidig' => intval(str_replace('.', '', $nums[3])) * 1000,
            'gem_m2_prijs_vorig' => intval(str_replace('.', '', $nums[4])),
            'gem_m2_prijs_huidig' => intval(str_replace('.', '', $nums[5])),
            'gem_vraagprijs_vorig' => intval(str_replace('.', '', $nums[6])) * 1000,
            'gem_vraagprijs_huidig' => intval(str_replace('.', '', $nums[7])) * 1000,
            'woz_verschil' => isset($nums[8]) ? intval(str_replace('.', '', $nums[8])) * 1000 : null,
        ]);
    }

    return json_encode(['error' => 'Kon Walter-statistieken niet parsen', 'nums_found' => count($nums)]);
}

// ── Walter Living buurt sold listings ────────────────────────────────────────

function fetchWalterBuurt($gemeente, $plaats, $buurt, $code) {
    $gemeente = strtolower(trim($gemeente));
    $plaats = strtolower(trim($plaats));
    $buurt = strtolower(trim($buurt));
    $code = trim($code);

    if (!$gemeente || !$plaats || !$buurt || !$code) {
        return json_encode(['error' => 'gemeente, plaats, buurt, and code required']);
    }

    $url = "https://walterliving.com/gemeente/{$gemeente}/plaats/{$plaats}/buurt/{$buurt}/{$code}";
    $html = curlGet($url);

    if (!$html || strlen($html) < 500) {
        return json_encode(['error' => 'Geen buurtdata gevonden']);
    }

    $listings = [];

    // Extract property listings using schema.org microdata
    // Note: Walter Living puts content="..." before itemprop="..." in <meta> tags
    preg_match_all('/content="([^"]+)"\s+itemprop="name"/', $html, $addresses);
    preg_match_all('/content="([^"]+)"\s+itemprop="url"/', $html, $urls);

    // Extract m2 and postcode from detail spans (separator is &middot HTML entity)
    preg_match_all('/(\d+)m²\s*&middot\s*[^&]+&middot\s*(\d{4}[A-Z]{2})/', $html, $details);

    // Extract asking prices
    preg_match_all('/Vraagprijs:<\/span>\s*\n?\s*€\s*([\d.]+)/', $html, $prices);

    // Extract sold status
    preg_match_all('/Verkocht\s+(boven|rond|onder)\s+de\s+vraagprijs/', $html, $statuses);

    $count = min(count($addresses[1] ?? []), count($prices[1] ?? []), count($statuses[1] ?? []));

    for ($i = 0; $i < $count; $i++) {
        $listing = [
            'adres' => $addresses[1][$i] ?? '',
            'vraagprijs' => intval(str_replace('.', '', $prices[1][$i] ?? '0')),
            'status' => $statuses[1][$i] ?? '',
        ];
        if (isset($details[1][$i])) $listing['m2'] = intval($details[1][$i]);
        if (isset($details[2][$i])) $listing['postcode'] = $details[2][$i];
        if (isset($urls[1][$i])) $listing['url'] = $urls[1][$i];
        $listings[] = $listing;
    }

    return json_encode([
        'gemeente' => $gemeente,
        'plaats' => $plaats,
        'buurt' => $buurt,
        'code' => $code,
        'listings' => $listings,
        'count' => count($listings),
    ]);
}

// ── WOZ Waardeloket API (gratis, geen key) ───────────────────────────────────

function fetchWOZ($nummeraanduidingId) {
    if (!$nummeraanduidingId) {
        return json_encode(['error' => 'nummeraanduiding ID required']);
    }

    $url = "https://api.kadaster.nl/lvwoz/wozwaardeloket-api/v1/wozwaarde/nummeraanduiding/{$nummeraanduidingId}";
    $json = curlGet($url);
    $data = json_decode($json, true);

    if (!$data || empty($data['wozWaardeObject'])) {
        return json_encode(['error' => 'Geen WOZ-data gevonden']);
    }

    $obj = $data['wozWaardeObject'];
    $waarden = [];
    if (!empty($obj['wpiWozWaarden'])) {
        foreach ($obj['wpiWozWaarden'] as $w) {
            $waarden[] = [
                'peildatum' => $w['peildatum'] ?? '',
                'vastgesteldeWaarde' => $w['vastgesteldeWaarde'] ?? 0,
            ];
        }
        // Sort newest first
        usort($waarden, function($a, $b) { return strcmp($b['peildatum'], $a['peildatum']); });
    }

    return json_encode([
        'wozobjectnummer' => $obj['wozObjectNummer'] ?? null,
        'grondoppervlakte' => $obj['grondoppervlakte'] ?? null,
        'waarden' => $waarden,
    ]);
}

// ── EP-Online Energielabel (gratis API key vereist) ──────────────────────────

function fetchEnergielabel($bagId) {
    if (!$bagId) {
        return json_encode(['error' => 'BAG adresseerbaar object ID required']);
    }

    $apiKey = $_ENV['EPONLINE_API_KEY'] ?? '';
    if (!$apiKey) {
        return json_encode(['error' => 'EPONLINE_API_KEY niet geconfigureerd in .env']);
    }

    $url = "https://public.ep-online.nl/api/v5/PandEnergielabel/AdresseerbaarObject/{$bagId}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $apiKey,
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return json_encode(['error' => 'EP-Online API error', 'status' => $httpCode]);
    }

    $data = json_decode($response, true);
    if (!$data || empty($data)) {
        return json_encode(['error' => 'Geen energielabel gevonden']);
    }

    // Filter on Woningbouw only, then take the most recent
    if (is_array($data) && isset($data[0])) {
        $woningen = array_filter($data, function($item) {
            return ($item['Gebouwklasse'] ?? '') === 'Woningbouw';
        });
        if (empty($woningen)) {
            // No residential label found, use first anyway but flag it
            $woningen = $data;
        }
        $woningen = array_values($woningen);
        usort($woningen, function($a, $b) {
            return strcmp($b['Registratiedatum'] ?? '', $a['Registratiedatum'] ?? '');
        });
        $label = $woningen[0];
    } else {
        $label = $data;
    }

    return json_encode([
        'energieklasse' => $label['Energieklasse'] ?? null,
        'energieIndex' => $label['EnergieIndex'] ?? null,
        'gebouwtype' => $label['Gebouwtype'] ?? null,
        'gebouwsubtype' => $label['Gebouwsubtype'] ?? $label['Gebouwtype'] ?? null,
        'registratiedatum' => $label['Registratiedatum'] ?? null,
        'geldig_tot' => $label['Geldig_tot'] ?? null,
    ]);
}

// ── Claude AI valuation ─────────────────────────────────────────────────────

function fetchClaudeValuation($data) {
    $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? '';
    if (!$apiKey) {
        return json_encode(['error' => 'ANTHROPIC_API_KEY niet geconfigureerd in .env']);
    }

    $prompt = buildValuationPrompt($data);

    $payload = json_encode([
        'model' => 'claude-sonnet-4-20250514',
        'max_tokens' => 1024,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return json_encode(['error' => 'Claude API error', 'status' => $httpCode, 'response' => $response]);
    }

    $result = json_decode($response, true);
    $text = $result['content'][0]['text'] ?? '';
    $inputTokens = $result['usage']['input_tokens'] ?? 0;
    $outputTokens = $result['usage']['output_tokens'] ?? 0;

    // Sonnet pricing: $3/MTok input, $15/MTok output
    $costUsd = ($inputTokens * 3 / 1000000) + ($outputTokens * 15 / 1000000);
    $costEur = $costUsd * 0.92; // approx USD to EUR

    // Parse the JSON from Claude's response
    $jsonMatch = [];
    preg_match('/\{[\s\S]*\}/', $text, $jsonMatch);
    $parsed = json_decode($jsonMatch[0] ?? '{}', true);

    return json_encode([
        'schatting' => $parsed['schatting'] ?? null,
        'range_laag' => $parsed['range_laag'] ?? null,
        'range_hoog' => $parsed['range_hoog'] ?? null,
        'onderbouwing' => $parsed['onderbouwing'] ?? $text,
        'correcties' => $parsed['correcties'] ?? [],
        'tokens_input' => $inputTokens,
        'tokens_output' => $outputTokens,
        'cost_eur' => round($costEur, 4),
    ]);
}

function buildValuationPrompt($d) {
    $features = implode(', ', $d['features'] ?? []);
    $refs = '';
    if (!empty($d['walter_buurt_listings'])) {
        foreach (array_slice($d['walter_buurt_listings'], 0, 10) as $r) {
            $refs .= "- {$r['adres']}: vraagprijs €" . number_format($r['vraagprijs'], 0, ',', '.')
                   . ", {$r['m2']}m², verkocht {$r['status']} de vraagprijs\n";
        }
    }

    return <<<PROMPT
Je bent een Nederlandse vastgoedtaxateur-AI. Geef een onderbouwde indicatieve marktwaardeschatting voor onderstaande woning.

WONING:
- Adres: {$d['adres']}
- Woonoppervlak: {$d['m2']} m²
- Perceeloppervlak: {$d['perceel']} m²
- Bouwjaar: {$d['bouwjaar']}
- Woningtype: {$d['type']}
- Energielabel: {$d['energielabel']}
- Bijzonderheden: {$features}

MARKTDATA (Walter Living, huidig kwartaal):
- Gem. transactieprijs stad: €{$d['gem_transactieprijs']}
- Gem. m²-prijs stad: €{$d['gem_m2_prijs']}
- Gem. vraagprijs stad: €{$d['gem_vraagprijs']}
- Aantal verkocht dit kwartaal: {$d['verkocht_kwartaal']}

CBS BUURTDATA:
- Gem. WOZ-waarde buurt: €{$d['woz_buurt']}
- Buurt: {$d['buurtnaam']}
- % Koopwoningen: {$d['koopwoningen_pct']}%
- % Eengezinswoning: {$d['eengezins_pct']}%
- Gem. inkomen: €{$d['gem_inkomen']}
- % Gebouwd na 2000: {$d['bouwjaar_na2000_pct']}%

WOZ-WAARDE (individueel):
- Meest recente WOZ: €{$d['woz_individueel']}
- WOZ-historie: {$d['woz_historie']}

VERKOCHTE REFERENTIEWONINGEN IN DE BUURT:
{$refs}

WISKUNDIGE SCHATTING (ter referentie): €{$d['wiskundig_schatting']}

INSTRUCTIES:
1. Analyseer de woning in context van de buurt en marktdata
2. Houd rekening met afnemend meerrendement bij grote woningen (>150m²)
3. Weeg de referentiewoningen mee (vraagprijs + boven/rond/onder)
4. Geef je schatting als JSON (geen markdown, alleen JSON):

{
  "schatting": 1100000,
  "range_laag": 1020000,
  "range_hoog": 1180000,
  "onderbouwing": "Korte onderbouwing in 2-3 zinnen, in het Nederlands",
  "correcties": [
    {"factor": "Afnemend meerrendement groot m²", "effect": "-€40.000"},
    {"factor": "Ander voorbeeld", "effect": "+€15.000"}
  ]
}
PROMPT;
}

// ── cURL helper ──────────────────────────────────────────────────────────────

function curlGet($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: nl,en;q=0.5',
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}
