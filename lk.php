<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// =======================
// SUPPORT CLI & WEB SERVER
// =======================
if (php_sapi_name() === 'cli') {
    $args = getopt('', ['link:', 'startpage:', 'endpage:', 'limitfilm:', 'group:']);
    $_GET['link'] = $args['link'] ?? '';
    $_GET['startpage'] = $args['startpage'] ?? 1;
    $_GET['endpage'] = $args['endpage'] ?? 0;
    $_GET['limitfilm'] = $args['limitfilm'] ?? 0;
    $_GET['group'] = $args['group'] ?? "Movies lk21";
}

// =======================
// PARAMETER
// =======================
$link       = $_GET['link'] ?? '';
$START_PAGE = isset($_GET['startpage']) ? intval($_GET['startpage']) : 1;
$END_PAGE   = isset($_GET['endpage']) ? intval($_GET['endpage']) : 0;
$LIMIT_FILM = isset($_GET['limitfilm']) ? intval($_GET['limitfilm']) : 0;
$GROUP_TITLE= isset($_GET['group']) ? trim(urldecode($_GET['group'])) : "Movies lk21";

if (!$link) die("❌ Parameter 'link' tidak ditemukan.\n");

// =======================
// VALIDASI DOMAIN
// =======================
$allowedDomains = ['tv6.lk21official.cc', 'tv1.nontondrama.my'];
$valid = false;
foreach ($allowedDomains as $domain) {
    if (strpos($link, $domain) !== false) {
        $valid = true;
        $baseSite = "https://$domain/";
        break;
    }
}
if (!$valid) die("❌ Link tidak berasal dari domain yang diizinkan.\n");

// =======================
// 1️⃣ DETEKSI TOTAL HALAMAN
// =======================
function curlGet($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        CURLOPT_TIMEOUT => 20,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

$htmlFirst = curlGet($link);
if (!$htmlFirst) die("❌ Gagal ambil halaman pertama\n");

$maxPagesDetected = 1;
if (preg_match_all('/page\/(\d+)/i', $htmlFirst, $m)) {
    $maxPagesDetected = max($m[1]);
}
$start = max($START_PAGE, 1);
$end   = ($END_PAGE > 0 && $END_PAGE <= $maxPagesDetected) ? $END_PAGE : $maxPagesDetected;
echo "📑 Ambil halaman dari $start sampai $end (total halaman tersedia $maxPagesDetected)\n";

// =======================
// 2️⃣ AMBIL SEMUA HALAMAN FILM MULTI-CURL
// =======================
$mh = curl_multi_init();
$curlArray = [];
for ($page = $start; $page <= $end; $page++) {
    $url = ($page == 1) ? $link : rtrim($link, '/') . "/page/$page";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    ]);
    $curlArray[$page] = $ch;
    curl_multi_add_handle($mh, $ch);
}

$running = null;
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

$pageHtmls = [];
foreach ($curlArray as $page => $ch) {
    $pageHtmls[$page] = curl_multi_getcontent($ch);
    curl_multi_remove_handle($mh, $ch);
}
curl_multi_close($mh);

// =======================
// 3️⃣ EKSTRAK SEMUA LINK FILM SEKALIGUS
// =======================
$allFilmLinks = [];
foreach ($pageHtmls as $html) {
    preg_match_all('/<figure><a href="([^"]+)"/i', $html, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $u) {
            if (strpos($u, 'http') !== 0) $u = $baseSite . ltrim($u, '/');
            $allFilmLinks[] = $u;
        }
    }
}
$allFilmLinks = array_unique($allFilmLinks);
$totalFilms = count($allFilmLinks);
echo "✅ Ditemukan $totalFilms link film.\n";

if ($LIMIT_FILM > 0 && $totalFilms > $LIMIT_FILM) {
    $allFilmLinks = array_slice($allFilmLinks, 0, $LIMIT_FILM);
    echo "🎯 Dibatasi hanya $LIMIT_FILM film pertama dari total $totalFilms.\n";
}

// =======================
// 4️⃣ AMBIL SEMUA HALAMAN FILM SEKALIGUS MULTI-CURL
// =======================
$mh = curl_multi_init();
$curlArray = [];
foreach ($allFilmLinks as $i => $filmUrl) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $filmUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    ]);
    $curlArray[$i] = $ch;
    curl_multi_add_handle($mh, $ch);
}

$running = null;
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

$filmHtmls = [];
foreach ($curlArray as $i => $ch) {
    $filmHtmls[$i] = curl_multi_getcontent($ch);
    curl_multi_remove_handle($mh, $ch);
}
curl_multi_close($mh);

// =======================
// 5️⃣ EKSTRAK SEMUA API URL SEKALIGUS
// =======================
$apiUrls = [];
foreach ($filmHtmls as $html) {
    // 1️⃣ cek select
    if (preg_match('/<select id=["\']player-select["\'][^>]*>\s*<option value=["\']([^"\']+)["\']/', $html, $m)) {
        $url = html_entity_decode($m[1]);
    }
    // 2️⃣ cek iframe
    elseif (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/', $html, $m)) {
        $url = html_entity_decode($m[1]);
    } else {
        continue;
    }

    // ambil ID terakhir setelah /p2p/
    if (preg_match('#/p2p/([^/?"]+)#', $url, $idMatch)) {
        $videoId = $idMatch[1];
        $apiUrls[] = "https://cloud.hownetwork.xyz/api2.php?id=" . $videoId;
    }
}
$apiUrls = array_unique($apiUrls);
$totalApi = count($apiUrls);
echo "🔗 Ditemukan $totalApi link API\n";

// =======================
// 6️⃣ AMBIL SEMUA API JSON SEKALIGUS MULTI-CURL
// =======================
$mh = curl_multi_init();
$curlArray = [];
$headers = [
    'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36',
    'Origin: https://cloud.hownetwork.xyz',
    'Referer: https://playeriframe.sbs/',
];
$postFields = http_build_query([
    'r' => 'https://playeriframe.sbs/',
    'd' => 'cloud.hownetwork.xyz'
]);

foreach ($apiUrls as $i => $url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    $curlArray[$i] = $ch;
    curl_multi_add_handle($mh, $ch);
}

$running = null;
do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

$results = [];
foreach ($curlArray as $i => $ch) {
    $results[$i] = curl_multi_getcontent($ch);
    curl_multi_remove_handle($mh, $ch);
}
curl_multi_close($mh);

// =======================
// 7️⃣ BUAT OUTPUT M3U
// =======================
$m3u = "#EXTM3U\n";
foreach ($results as $res) {
    $data = json_decode($res, true);
    if (!$data) continue;

    $logo = $data['poster'] ?? '';
    $logo = preg_replace('/(\.jpg|\.png)?\.webp$/i', '$1', $logo);
    $logo = $logo ? "https://santai-2.serv00.net/lk21/logo.php?url=" . $logo : "https://saja.serv00.net/logo/default.jpg";

    $fileUrl = str_replace('/zzz/', '/xxx/', $data['file'] ?? '');

    $m3u .= "#EXTINF:-1 tvg-logo=\"{$logo}\" group-title=\"{$GROUP_TITLE}\",{$data['title']}\n";
    $m3u .= "#KODIPROP:inputstream.adaptive.stream_headers=referer=https://cloud.hownetwork.xyz/\n";
    $m3u .= "#EXTVLCOPT:http-user-agent=Mozilla/5.0 (Linux; Android 10; K)\n";
    $m3u .= "$fileUrl\n";
}

echo $m3u;
?>