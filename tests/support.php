<?php

declare(strict_types=1);

/**
 * Tiny test helpers: a result printer, a cookie-keeping HTTP client built on
 * cURL, and a starter for PHP's built-in web server.
 */

final class T
{
    private static int $passed = 0;
    private static int $failed = 0;

    public static function section(string $title): void
    {
        echo "\n" . $title . "\n";
    }

    public static function check(string $name, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            self::$passed++;
            echo '  PASS  ' . $name . "\n";
        } else {
            self::$failed++;
            echo '  FAIL  ' . $name . ($detail !== '' ? '  (' . $detail . ')' : '') . "\n";
        }
    }

    public static function same(string $name, mixed $expected, mixed $actual): void
    {
        self::check(
            $name,
            $expected === $actual,
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }

    public static function contains(string $name, string $haystack, string $needle): void
    {
        self::check($name, str_contains($haystack, $needle), 'missing: ' . $needle);
    }

    public static function notContains(string $name, string $haystack, string $needle): void
    {
        self::check($name, !str_contains($haystack, $needle), 'found: ' . $needle);
    }

    public static function matches(string $name, string $pattern, string $subject): void
    {
        self::check($name, preg_match($pattern, $subject) === 1, 'no match for ' . $pattern);
    }

    public static function summary(): int
    {
        echo "\nResult: " . self::$passed . ' passed, ' . self::$failed . " failed\n";

        return self::$failed === 0 ? 0 : 1;
    }
}

/**
 * Reads a page the way a browser would build its document tree, so a test can
 * ask "what is inside what" instead of matching text with a regular expression.
 */
final class Page
{
    public static function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    /**
     * IDs of comments whose "Depth N" label is not equal to the number of reply
     * branches around them. A correct page returns an empty list.
     *
     * @return list<string>
     */
    public static function depthMismatches(DOMXPath $x): array
    {
        $wrong = [];

        foreach ($x->query('//article[starts-with(@id, "comment-")]') as $article) {
            $label = $x->query('.//span[@class="depth-label"]', $article)->item(0)?->textContent ?? '';
            $branches = $x->query('ancestor::div[contains(@class, "reply-branch")]', $article)->length;

            if ($label !== 'Depth ' . $branches) {
                $wrong[] = $article->getAttribute('id');
            }
        }

        return $wrong;
    }

    /**
     * IDs of the comments found inside one reply branch (all levels).
     *
     * @return list<string>
     */
    public static function commentsInBranch(DOMXPath $x, string $branchId): array
    {
        $ids = [];

        foreach ($x->query('//div[@id="' . $branchId . '"]//article[starts-with(@id, "comment-")]') as $article) {
            $ids[] = $article->getAttribute('id');
        }

        return $ids;
    }
}

/**
 * A very small "browser": keeps its own cookies, never follows redirects, so a
 * test can look at the status code and Location header of each response.
 */
final class Browser
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly string $base)
    {
    }

    /** @return array{status: int, headers: array<string, list<string>>, body: string, location: ?string} */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, headers: array<string, list<string>>, body: string, location: ?string}
     */
    public function post(string $path, array $fields = []): array
    {
        return $this->request('POST', $path, $fields);
    }

    /** Loads a page and returns the CSRF token from its first form. */
    public function token(string $path): string
    {
        $page = $this->get($path);

        if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $page['body'], $m) !== 1) {
            throw new RuntimeException('No CSRF token found on ' . $path);
        }

        return $m[1];
    }

    public function sessionId(): ?string
    {
        return $this->cookies['forum_sid'] ?? null;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{status: int, headers: array<string, list<string>>, body: string, location: ?string}
     */
    private function request(string $method, string $path, array $fields = []): array
    {
        $headers = [];
        $ch = curl_init($this->base . $path);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))][] = trim($value);
                }

                return strlen($line);
            },
        ]);

        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $pairs));
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException('Request failed: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            $this->storeCookie($cookie);
        }

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => (string) $body,
            'location' => $headers['location'][0] ?? null,
        ];
    }

    private function storeCookie(string $header): void
    {
        $parts = explode(';', $header);
        [$name, $value] = array_pad(explode('=', trim(array_shift($parts)), 2), 2, '');

        if ($value === '' || $value === 'deleted') {
            unset($this->cookies[$name]);

            return;
        }
        foreach ($parts as $attr) {
            if (stripos(trim($attr), 'expires=') === 0 && strtotime(substr(trim($attr), 8)) < time()) {
                unset($this->cookies[$name]);

                return;
            }
        }

        $this->cookies[$name] = $value;
    }
}

/** Starts "php -S" on a free port with the test database in its environment. */
final class TestServer
{
    /** @var resource|null */
    private $process = null;
    public string $base = '';

    /** @param array<string, string> $env */
    public function __construct(private readonly string $root, private readonly array $env)
    {
    }

    public function start(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException('Could not find a free port: ' . $errstr);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) strrchr($name, ':'), 1);

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $this->root . '/public', $this->root . '/public/index.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            $this->root,
            array_merge(getenv(), $this->env)
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the PHP built-in server.');
        }
        $this->process = $process;

        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
            if ($probe !== false) {
                fclose($probe);
                $this->base = 'http://127.0.0.1:' . $port;

                return;
            }
            usleep(100000);
        }

        throw new RuntimeException('The PHP built-in server did not start in time.');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }
}
