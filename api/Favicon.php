<?php
declare(strict_types=1);

/**
 * Favicon Service Interface
 *
 * Retrieves favicon data from a given URL with support for caching and debugging.
 *
 * PHP Version: 8.3
 *
 * @author Jerry Bendy
 * @link http://blog.icewingcc.com
 */

namespace Jerrybendy\Favicon;

use InvalidArgumentException;

class Favicon
{
    /**
     * Enable debug mode to log runtime information to error log.
     */
    public bool $debug_mode = false;

    /**
     * Parsed URL parameters and additional data.
     *
     * @var array<string, mixed>
     */
    private array $params = [];

    /**
     * Full host URL (e.g., http://example.com:8080).
     */
    private string $full_host = '';

    /**
     * Favicon binary data.
     */
    private ?string $data = null;

    /**
     * Time spent on the last request in seconds.
     */
    private float $last_time_spend = 0.0;

    /**
     * Memory usage of the last request in MB.
     */
    private string $last_memory_usage = '0';

    /**
     * Mapping of regex patterns to file paths for quick favicon retrieval.
     *
     * @var array<string, string>
     */
    private array $file_map = [];

    /**
     * Default favicon file path to use if retrieval fails.
     */
    private string $default_icon = '';

    /**
     * Retrieves and outputs a website's favicon.
     *
     * @param string $url The input URL
     * @param bool $return Whether to return binary content or output it directly
     * @return string|null Binary favicon data if $return is true, null otherwise
     * @throws InvalidArgumentException If the URL is empty or invalid
     */
    public function getFavicon(string $url, bool $return = false): ?string
    {
        if (empty($url)) {
            throw new InvalidArgumentException('URL cannot be empty', E_ERROR);
        }

        $this->params['origin_url'] = $url;

        if (!$this->formatUrl($url)) {
            throw new InvalidArgumentException('Invalid URL', E_WARNING);
        }

        $time_start = microtime(true);
        $this->logMessage("Begin to get favicon for {$url}");

        $this->data = $this->getData();

        $this->last_time_spend = microtime(true) - $time_start;
        $this->last_memory_usage = function_exists('memory_get_usage')
            ? sprintf('%.2f MB', memory_get_usage() / 1024 / 1024)
            : '0 MB';

        $this->logMessage("Favicon retrieval completed, time: {$this->last_time_spend}s, memory: {$this->last_memory_usage}");

        if ($this->data === null && $this->default_icon !== '') {
            $this->data = @file_get_contents($this->default_icon) ?: null;
        }

        if ($return) {
            return $this->data;
        }

        if ($this->data !== null) {
            foreach ($this->getHeader() as $header) {
                header($header);
            }
            echo $this->data;
        } else {
            header('Content-Type: application/json');
            echo json_encode(['status' => -1, 'msg' => 'Favicon not found']);
        }

        return null;
    }

    /**
     * Returns HTTP headers for favicon output.
     *
     * @return string[]
     */
    public function getHeader(): array
    {
        return [
            'X-Robots-Tag: noindex, nofollow',
            'Content-Type: image/x-icon',
            'Cache-Control: public, max-age=86400',
            'Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT',
        ];
    }

    /**
     * Sets regex-to-file mappings for favicon retrieval.
     *
     * @param array<string, string> $map Regex pattern to file path mappings
     * @return self
     */
    public function setFileMap(array $map): self
    {
        $this->file_map = $map;
        return $this;
    }

    /**
     * Sets the default favicon file path.
     *
     * @param string $filePath Path to default favicon
     * @return self
     */
    public function setDefaultIcon(string $filePath): self
    {
        $this->default_icon = $filePath;
        return $this;
    }

    /**
     * Core function to retrieve favicon data.
     *
     * @return string|null Favicon binary data or null if not found
     */
    protected function getData(): ?string
    {
        // Check file map first
        $this->data = $this->matchFileMap();
        if ($this->data !== null) {
            $this->logMessage("Retrieved favicon from file map for {$this->full_host}");
            return $this->data;
        }

        // Fetch HTML and parse for favicon link
        $html = $this->getFile($this->params['origin_url']);
        if ($html && $html['status'] === 'OK') {
            $htmlContent = str_replace(["\n", "\r"], '', $html['data']);
            if (preg_match('/<link[^>]+rel=["\'](?:icon|shortcut icon|apple-touch-icon)["\'][^>]+>/i', $htmlContent, $match_tag)) {
                if (preg_match('/href=["\'](.*?)["\']/i', $match_tag[0], $match_url)) {
                    $iconUrl = $this->filterRelativeUrl(trim($match_url[1]), $this->params['origin_url']);
                    $icon = $this->getFile($iconUrl, true);
                    if ($icon && $icon['status'] === 'OK') {
                        $this->logMessage("Retrieved favicon from {$iconUrl}");
                        return $this->data = $icon['data'];
                    }
                }
            }
        }

        // Try root favicon.ico
        $redirected_url = $html['real_url'] ?? $this->full_host;
        $data = $this->getFile($this->full_host . '/favicon.ico', true);
        if ($data && $data['status'] === 'OK') {
            $this->logMessage("Retrieved favicon from root: {$this->full_host}/favicon.ico");
            return $this->data = $data['data'];
        }

        // Try redirected URL's root favicon.ico
        if ($this->formatUrl($redirected_url)) {
            $data = $this->getFile($this->full_host . '/favicon.ico', true);
            if ($data && $data['status'] === 'OK') {
                $this->logMessage("Retrieved favicon from redirected root: {$this->full_host}/favicon.ico");
                return $this->data = $data['data'];
            }
        }

        // Fallback to external Google API
        $apiUrl = 'https://t0.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=TYPE,SIZE,URL&url=' . urlencode($this->full_host);
        $icon = @file_get_contents($apiUrl);
        if ($icon !== false) {
            $this->logMessage("Retrieved favicon from external Google API: {$apiUrl}");
            return $this->data = $icon;
        }

        // Fallback to external toolb API
        $apiUrl = 'https://toolb.cn/favicon/' . parse_url($this->full_host, PHP_URL_HOST);
        $icon = @file_get_contents($apiUrl);
        if ($icon !== false) {
            $this->logMessage("Retrieved favicon from external toolb API: {$apiUrl}");
            return $this->data = $icon;
        }        

        $this->logMessage("Failed to retrieve favicon for {$this->params['origin_url']}");
        return null;
    }

    /**
     * Parses a URL and sets full_host property.
     *
     * @param string $url Input URL
     * @return string|null Full host URL or null if invalid
     */
    public function formatUrl(string $url): ?string
    {
        $parsed_url = parse_url($url);
        if ($parsed_url === false || !isset($parsed_url['host']) || empty($parsed_url['host'])) {
            if (!preg_match('/^https?:\/\//', $url)) {
                $url = 'http://' . $url;
                $parsed_url = parse_url($url);
                if ($parsed_url === false) {
                    return null;
                }
                $this->params['origin_url'] = $url;
            }
        }

        $this->full_host = sprintf(
            '%s://%s%s',
            $parsed_url['scheme'] ?? 'http',
            $parsed_url['host'],
            isset($parsed_url['port']) ? ':' . $parsed_url['port'] : ''
        );

        return $this->full_host;
    }

    /**
     * Converts relative URLs to absolute URLs.
     *
     * @param string $url Relative or absolute URL from HTML
     * @param string $baseUri Base URI for reference
     * @return string|null Absolute URL or null if invalid
     */
    private function filterRelativeUrl(string $url, string $baseUri): ?string
    {
        if (str_contains($url, '://')) {
            return $url;
        }

        $uriParts = parse_url($baseUri);
        if ($uriParts === false) {
            return null;
        }

        $uriRoot = sprintf('%s://%s%s', $uriParts['scheme'], $uriParts['host'], $uriParts['port'] ?? '');

        if (str_starts_with($url, '//')) {
            return $uriParts['scheme'] . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $uriRoot . $url;
        }

        $uriDir = isset($uriParts['path']) && $uriParts['path'] !== '' ? '/' . ltrim(dirname($uriParts['path']), '/') : '';
        if (!str_contains($url, './')) {
            return $uriDir !== '' ? $uriRoot . $uriDir . '/' . $url : $uriRoot . '/' . $url;
        }

        $url = preg_replace('/[^\.]\.\/|\/\//', '/', $url);
        if (str_starts_with($url, './')) {
            $url = substr($url, 2);
        }

        $urlParts = explode('/', ltrim($uriDir . '/' . $url, '/'));
        if ($urlParts[0] === '..') {
            array_shift($urlParts);
        }

        $dstParts = $urlParts;
        for ($i = 1; $i < count($urlParts); $i++) {
            if ($urlParts[$i] === '..') {
                $j = 1;
                while (isset($dstParts[$i - $j]) && $dstParts[$i - $j] !== false) {
                    $dstParts[$i - $j] = false;
                    $dstParts[$i] = false;
                    break;
                }
            }
        }

        $dstStr = $uriRoot;
        foreach ($dstParts as $part) {
            if ($part !== false) {
                $dstStr .= '/' . $part;
            }
        }

        return $dstStr;
    }

    /**
     * Executes cURL request with redirect following.
     *
     * @param resource $ch cURL handle
     * @param int $maxRedirect Maximum number of redirects
     * @return string|null Response data or null on failure
     */
    private function curlExecFollow($ch, int $maxRedirect = 5): ?string
    {
        // If open_basedir and safe_mode are disabled, use native redirect following
        if (ini_get('open_basedir') === '' && ini_get('safe_mode') === '0') {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $maxRedirect > 0);
            curl_setopt($ch, CURLOPT_MAXREDIRS, $maxRedirect);
            $result = curl_exec($ch);
            return $result !== false ? $result : null;
        }

        // Manual redirect handling
        $currentUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $remainingRedirects = $maxRedirect;

        while ($remainingRedirects > 0) {
            $rch = curl_copy_handle($ch);
            curl_setopt_array($rch, [
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_NOSIGNAL => 1,
                CURLOPT_CONNECTTIMEOUT_MS => 1000, // Increased to 1s for reliability
                CURLOPT_FORBID_REUSE => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_URL => $currentUrl,
            ]);

            $header = curl_exec($rch);
            if (curl_errno($rch)) {
                $this->logMessage("cURL error for {$currentUrl}: " . curl_error($rch));
                curl_close($rch);
                return null;
            }

            $code = curl_getinfo($rch, CURLINFO_HTTP_CODE);
            curl_close($rch);

            if ($code !== 301 && $code !== 302) {
                break; // Not a redirect, proceed with final request
            }

            if (!preg_match('/Location:\s*(.*?)\r?\n/i', $header, $matches)) {
                $this->logMessage("No Location header found for {$currentUrl}");
                return null;
            }

            $newUrl = trim($matches[1]);
            $this->logMessage("Redirect from {$currentUrl} to {$newUrl}");
            $newUrl = $this->filterRelativeUrl($newUrl, $currentUrl ?: $this->params['origin_url']);
            if ($newUrl === null) {
                $this->logMessage("Failed to resolve redirect URL: {$newUrl}");
                return null;
            }

            $currentUrl = $newUrl;
            $remainingRedirects--;
        }

        if ($remainingRedirects === 0) {
            $this->logMessage("Too many redirects for {$this->params['origin_url']}");
            trigger_error('Too many redirects.', E_USER_WARNING);
            return null;
        }

        // Perform final request with updated URL
        curl_setopt($ch, CURLOPT_URL, $currentUrl);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        $result = curl_exec($ch);
        $this->logMessage("Final request to {$currentUrl}, status: " . curl_getinfo($ch, CURLINFO_HTTP_CODE));
        return $result !== false ? $result : null;
    }

    /**
     * Fetches file content from a URL using cURL.
     *
     * @param string $url URL to fetch
     * @param bool $isImage Whether the file is expected to be an image
     * @param int $timeout Timeout in seconds
     * @return array{status: string, data: string, real_url: string, code?: int} File data and status
     */
    private function getFile(string $url, bool $isImage = false, int $timeout = 2): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT => 5,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_TCP_NODELAY => 1,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Range: bytes=0-512000', 'Connection: close'],
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_FAILONERROR => true,
        ]);

        $result = $this->curlExecFollow($ch, 5); // Increased max redirects to 5
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $realUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        $response = [
            'status' => 'FAIL',
            'data' => '',
            'real_url' => $realUrl,
        ];

        if ($isImage) {
            $imgInfo = @getimagesizefromstring($result ?: '');
            if (empty($imgInfo)) {
                $this->logMessage("Not an image: {$url}");
                curl_close($ch);
                return $response;
            }
            $mimeType = explode('/', $mime)[0] ?? '';
            if ($mimeType !== 'image') {
                $this->logMessage("Not an image (invalid MIME type): {$url}");
                curl_close($ch);
                return $response;
            }
        }

        if ($result !== false && $statusCode >= 200 && $statusCode <= 399) {
            $response = [
                'code' => $statusCode,
                'status' => 'OK',
                'data' => $result,
                'real_url' => $realUrl,
            ];
        }

        curl_close($ch);
        return $response;
    }

    /**
     * Matches URL against file map and returns content if matched.
     *
     * @return string|null File content or null if no match
     */
    private function matchFileMap(): ?string
    {
        foreach ($this->file_map as $rule => $file) {
            if (preg_match($rule, $this->full_host)) {
                return @file_get_contents($file) ?: null;
            }
        }
        return null;
    }

    /**
     * Logs message if debug mode is enabled.
     *
     * @param string $message Message to log
     */
    private function logMessage(string $message): void
    {
        if ($this->debug_mode) {
            error_log(sprintf('%s : %s%s', date('d/m/Y H:i:s'), $message, PHP_EOL), 3, './my-errors.log');
        }
    }
}