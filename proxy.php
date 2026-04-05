<?php
/**
 * WoningCheck Proxy — fetches data from external sources that need CORS bypass or POST requests.
 *
 * Endpoints:
 *   ?source=bag&postcode=2292CR&huisnummer=2          — BAG WFS property data
 *   ?source=walter_city&city=wateringen                — Walter Living city stats
 *   ?source=walter_buurt&gemeente=westland&plaats=wateringen&buurt=essellanden&code=BU17830611 — Walter buurt sold listings
 */

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
    preg_match_all('/itemprop="name"[^>]*content="([^"]+)"/', $html, $addresses);
    preg_match_all('/itemprop="url"[^>]*content="([^"]+)"/', $html, $urls);

    // Extract m2 and postcode from detail spans
    preg_match_all('/(\d+)m²\s*·\s*[^·]+·\s*(\d{4}[A-Z]{2})/', $html, $details);

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
