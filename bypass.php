<?php
// Proxy Script with Advanced Cloudflare Bypass
// Handles all requests and forwards them to target domain

error_reporting(0); // Suppress errors for cleaner output

// Target URL & Cookie Placeholders
$targetDomain = 'TARGET_DOMAIN_PLACEHOLDER'; // e.g. 'www.example.com'
$targetScheme = 'https';
$cookieDomain = 'COOKIE_DOMAIN_PLACEHOLDER'; // e.g. 'example.com'

// Get the request URI
$requestUri = $_SERVER['REQUEST_URI'];
$queryString = $_SERVER['QUERY_STRING'];

// Build the target URL
$targetUrl = $targetScheme . '://' . $targetDomain . $requestUri;

// Cookie refresh function
function refreshCloudflareTokens()
{
    global $targetScheme, $targetDomain;
    $cookieFile = __DIR__ . '/cookie.txt';
    $tempDir = sys_get_temp_dir();
    $cfCookieFile = $tempDir . '/cf_cookies.txt';

    // Use a headless approach to get fresh CF cookies
    $ch = curl_init($targetScheme . '://' . $targetDomain . '/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cfCookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cfCookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-RSA-AES128-SHA256');

    // Chrome-like TLS settings
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_TLSv1_3);

    $headers = [
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'sec-ch-ua: "Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "macOS"',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Upgrade-Insecure-Requests: 1'
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_exec($ch);
    curl_close($ch);

    return file_exists($cfCookieFile);
}

// Check if CF cookies need refresh (check age)
$cookieFile = __DIR__ . '/cookie.txt';
$needRefresh = false;

if (file_exists($cookieFile)) {
    $cookieContent = file_get_contents($cookieFile);
    // Check if __cf_bm exists and is recent (these expire quickly)
    if (strpos($cookieContent, '__cf_bm') === false) {
        $needRefresh = true;
    } else {
        // Parse expiry of __cf_bm
        preg_match('/__cf_bm\t([^\t]+)\t([^\t]+)\t([^\t]+)\t(\d+)/', $cookieContent, $matches);
        if (!empty($matches) && isset($matches[4])) {
            $expiry = (int) $matches[4];
            if ($expiry < time()) {
                $needRefresh = true;
            }
        }
    }
}

// Auto-refresh if needed
if ($needRefresh) {
    refreshCloudflareTokens();
}

// Initialize cURL
$ch = curl_init();

// Set the target URL
curl_setopt($ch, CURLOPT_URL, $targetUrl);

// Return the response instead of outputting it
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// Include headers in the output
curl_setopt($ch, CURLOPT_HEADER, true);

// Follow redirects
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_MAXREDIRS, 10);

// Set timeout
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

// Handle different request methods
$method = $_SERVER['REQUEST_METHOD'];
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

// Forward POST/PUT data if present
if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
    $postData = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
}

// Prepare headers to forward
$headersToForward = [];

// List of headers to forward from client (excluding Accept-Encoding - let cURL handle it)
$clientHeaders = [
    'Accept',
    'Accept-Language',
    // 'Accept-Encoding', // Removed - cURL handles this automatically with CURLOPT_ENCODING
    'Cache-Control',
    'Content-Type',
    'Content-Length',
    'Origin',
    'Referer',
    'User-Agent',
    'X-Requested-With',
    'Sec-Fetch-Mode',
    'Sec-Fetch-Site',
    'Sec-Fetch-Dest',
    'sec-ch-ua',
    'sec-ch-ua-mobile',
    'sec-ch-ua-platform'
];

// Get all request headers
$allHeaders = getallheaders();

// Add client headers
foreach ($clientHeaders as $header) {
    $headerKey = str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower($header))));
    if (isset($allHeaders[$headerKey])) {
        $headersToForward[] = $headerKey . ': ' . $allHeaders[$headerKey];
    } elseif (isset($allHeaders[strtolower($header)])) {
        $headersToForward[] = $headerKey . ': ' . $allHeaders[strtolower($header)];
    }
}

// Load cookies from cookie.txt file
$cookies = [];

if (file_exists($cookieFile)) {
    $cookieLines = file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($cookieLines as $line) {
        // Skip comments and empty lines
        if (empty($line) || $line[0] == '#') {
            continue;
        }

        // Parse cookie line (Netscape format)
        // Format: domain flag path secure expiration name value
        $parts = preg_split('/\t/', $line);
        if (count($parts) >= 7) {
            $domain = $parts[0];
            $expiration = (int) $parts[4];
            $name = $parts[5];
            $value = $parts[6];

            // Only include cookies for target/cookie domain and skip expired cookies
            if ((strpos($domain, $cookieDomain) !== false || strpos($domain, $targetDomain) !== false) && ($expiration == 0 || $expiration > time())) {
                // Don't URL decode - send as-is
                $cookies[] = $name . '=' . $value;
            }
        }
    }
}

// Add cookies to headers
if (!empty($cookies)) {
    $headersToForward[] = 'Cookie: ' . implode('; ', $cookies);
}

// Add required headers for target domain and Cloudflare bypass
$headersToForward[] = 'Host: ' . $targetDomain;

// Add modern browser headers - critical for Cloudflare bypass
if (!isset($allHeaders['User-Agent'])) {
    $headersToForward[] = 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
}

if (!isset($allHeaders['Accept'])) {
    $headersToForward[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7';
}

if (!isset($allHeaders['Accept-Language'])) {
    $headersToForward[] = 'Accept-Language: en-US,en;q=0.9';
}

// Critical Cloudflare bypass headers
$headersToForward[] = 'sec-ch-ua: "Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"';
$headersToForward[] = 'sec-ch-ua-mobile: ?0';
$headersToForward[] = 'sec-ch-ua-platform: "macOS"';
$headersToForward[] = 'Sec-Fetch-Dest: document';
$headersToForward[] = 'Sec-Fetch-Mode: navigate';
$headersToForward[] = 'Sec-Fetch-Site: none';
$headersToForward[] = 'Sec-Fetch-User: ?1';
$headersToForward[] = 'Upgrade-Insecure-Requests: 1';
$headersToForward[] = 'Cache-Control: max-age=0';
$headersToForward[] = 'Connection: keep-alive';

// Set headers for cURL
curl_setopt($ch, CURLOPT_HTTPHEADER, $headersToForward);

// Enable HTTP/2 for more realistic browser behavior with ALPN
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);

// Advanced TLS configuration to match Chrome's fingerprint
// Use specific cipher suites that match Chrome 131
curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-RSA-AES128-SHA256');

// Enable SSL verification
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// Use TLS 1.2 and 1.3 (Cloudflare requirement)
curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_TLSv1_3);

// Automatically handle all supported compressed responses (empty string = all supported encodings)
curl_setopt($ch, CURLOPT_ENCODING, '');

// Get response info
curl_setopt($ch, CURLINFO_HEADER_OUT, true);

// Set TCP_NODELAY to reduce latency (more browser-like)
curl_setopt($ch, CURLOPT_TCP_NODELAY, true);

// Set realistic connection options
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 120);

// Execute the request
$response = curl_exec($ch);

// Check for errors
if (curl_errno($ch)) {
    http_response_code(502);
    echo 'Proxy Error: ' . curl_error($ch);
    curl_close($ch);
    exit;
}

// Get information about the request
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

// Close cURL
curl_close($ch);

// Split headers and body
$responseHeaders = substr($response, 0, $headerSize);
$responseBody = substr($response, $headerSize);

// Parse and forward response headers
$headerLines = explode("\r\n", $responseHeaders);

// Headers to skip (will be set automatically by PHP or should not be forwarded)
$skipHeaders = [
    'transfer-encoding',
    'connection',
    'keep-alive',
    'proxy-authenticate',
    'proxy-authorization',
    'te',
    'trailers',
    'upgrade',
    'content-encoding', // Skip - already decoded by cURL
    'content-length',   // Skip - will be recalculated by PHP
];

foreach ($headerLines as $headerLine) {
    $headerLine = trim($headerLine);

    // Skip empty lines
    if (empty($headerLine)) {
        continue;
    }

    // Skip HTTP status line (handled separately)
    if (preg_match('/^HTTP\//', $headerLine)) {
        continue;
    }

    // Parse header
    $headerParts = explode(':', $headerLine, 2);
    if (count($headerParts) == 2) {
        $headerName = strtolower(trim($headerParts[0]));
        $headerValue = trim($headerParts[1]);

        // Skip certain headers
        if (in_array($headerName, $skipHeaders)) {
            continue;
        }

        // Rewrite location headers to point to this proxy
        if ($headerName == 'location') {
            $headerValue = str_replace(
                [$targetScheme . '://' . $targetDomain, 'http://' . $targetDomain, 'https://' . $targetDomain],
                '//' . $_SERVER['HTTP_HOST'],
                $headerValue
            );
        }

        // Rewrite content-security-policy and other domain-specific headers
        if (in_array($headerName, ['content-security-policy', 'content-security-policy-report-only'])) {
            $headerValue = str_replace($targetDomain, $_SERVER['HTTP_HOST'], $headerValue);
        }

        // Forward the header
        header($headerName . ': ' . $headerValue, false);
    }
}

// Set HTTP response code
http_response_code($httpCode);

// Rewrite URLs in the response body for HTML/CSS/JS content
$contentType = '';
foreach ($headerLines as $headerLine) {
    if (preg_match('/^content-type:\s*(.+)/i', $headerLine, $matches)) {
        $contentType = strtolower(trim($matches[1]));
        break;
    }
}

// Rewrite URLs in HTML, CSS, and JavaScript
if (
    strpos($contentType, 'text/html') !== false ||
    strpos($contentType, 'text/css') !== false ||
    strpos($contentType, 'application/javascript') !== false ||
    strpos($contentType, 'application/x-javascript') !== false ||
    strpos($contentType, 'text/javascript') !== false
) {

    // Replace absolute URLs
    $responseBody = str_replace(
        [
            'https://' . $targetDomain,
            'http://' . $targetDomain,
            '//' . $targetDomain,
            '"https://' . $targetDomain,
            "'https://" . $targetDomain,
            '"http://' . $targetDomain,
            "'http://" . $targetDomain,
            '//' . $targetDomain,
            'https://' . $cookieDomain,
            'http://' . $cookieDomain,
            '//' . $cookieDomain,
            '"https://' . $cookieDomain,
            "'https://" . $cookieDomain,
            '"http://' . $cookieDomain,
            "'http://" . $cookieDomain,
            '//' . $cookieDomain,
        ],
        [
            '//' . $_SERVER['HTTP_HOST'],
            '//' . $_SERVER['HTTP_HOST'],
            '//' . $_SERVER['HTTP_HOST'],
            '"//' . $_SERVER['HTTP_HOST'],
            "'//" . $_SERVER['HTTP_HOST'],
            '"//' . $_SERVER['HTTP_HOST'],
            "'//" . $_SERVER['HTTP_HOST'],
            '//' . $_SERVER['HTTP_HOST'],
            '//' . $_SERVER['HTTP_HOST'],
            '//' . $_SERVER['HTTP_HOST'],
            '//' . $_SERVER['HTTP_HOST'],
            '"//' . $_SERVER['HTTP_HOST'],
            "'//" . $_SERVER['HTTP_HOST'],
            '"//' . $_SERVER['HTTP_HOST'],
            "'//" . $_SERVER['HTTP_HOST'],
            '//' . $_SERVER['HTTP_HOST'],
        ],
        $responseBody
    );

    // Also replace domain-only references
    $responseBody = str_replace(
        ['"' . $targetDomain . '"', "'" . $targetDomain . "'", '"' . $cookieDomain . '"', "'" . $cookieDomain . "'"],
        ['"' . $_SERVER['HTTP_HOST'] . '"', "'" . $_SERVER['HTTP_HOST'] . "'", '"' . $_SERVER['HTTP_HOST'] . '"', "'" . $_SERVER['HTTP_HOST'] . "'"],
        $responseBody
    );
}

// Output the response body
echo $responseBody;
