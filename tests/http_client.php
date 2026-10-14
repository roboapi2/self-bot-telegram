<?php
declare(strict_types=1);
// Disposable integration-test client. Never include in application entrypoints.
function fixture_http(string $base, string $path, string &$cookie, ?array $post = null, int $expected = 200): array
{
    if (!in_array($path, ['/', '/index.php'], true) && !str_starts_with($path, '/api.php?')) throw new RuntimeException('Fixture request path rejected');
    $options = ['method' => $post === null ? 'GET' : 'POST', 'timeout' => 12,
        'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
        'header' => $cookie === '' ? '' : "Cookie: $cookie\r\n"];
    if ($post !== null) {
        $options['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $options['content'] = http_build_query($post);
    }
    $body = @file_get_contents($base . $path, false, stream_context_create(['http' => $options]));
    $status = 0; $location = null;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+ (\d{3})~', $line, $m)) $status = (int) $m[1];
        if (preg_match('/^Set-Cookie: (PHPSESSID=[a-zA-Z0-9,-]+);/i', $line, $m)) $cookie = $m[1];
        if (str_starts_with(strtolower($line), 'location:')) $location = trim(substr($line, 9));
    }
    if ($body === false || $status !== $expected) {
        // Never include bodies, response headers, cookies, POST data or URLs containing tokens.
        throw new RuntimeException("Fixture HTTP failure: expected=$expected actual=$status method=" . $options['method']);
    }
    return ['body' => $body, 'status' => $status, 'location' => $location];
}
function fixture_token(string $body, bool $dashboard): string
{
    $pattern = $dashboard ? '/csrf\s*:\s*"([a-f0-9]{64})"/' : '/name="csrf" value="([a-f0-9]{64})"/';
    if (preg_match($pattern, $body, $m) !== 1) throw new RuntimeException($dashboard ? 'Fixture dashboard CSRF missing' : 'Fixture login CSRF missing');
    return $m[1];
}
function fixture_login(string $base, string &$cookie, string $password): string
{
    $login = fixture_http($base, '/', $cookie);
    $token = fixture_token($login['body'], false);
    if ($cookie === '') throw new RuntimeException('Fixture login session cookie missing');
    $oldCookie = $cookie;
    $redirect = fixture_http($base, '/', $cookie, ['panel_password' => $password, 'csrf' => $token], 302);
    if ($cookie === $oldCookie) throw new RuntimeException('Fixture login session was not regenerated');
    if (!in_array($redirect['location'], ['/', '/index.php', 'index.php'], true)) throw new RuntimeException('Fixture login redirect rejected');
    // The renewed session cookie must be sent on a fresh GET, never on automatic redirects.
    $dashboard = fixture_http($base, '/index.php', $cookie);
    return fixture_token($dashboard['body'], true);
}
function fixture_json(string $body): array
{
    try { $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
    catch (Throwable) { throw new RuntimeException('Fixture API response is not JSON'); }
    if (!is_array($result) || !isset($result['ok']) || !is_bool($result['ok'])) throw new RuntimeException('Fixture API response structure invalid');
    return $result;
}
