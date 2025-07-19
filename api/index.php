<?php
/**
 * Favicon Retrieval Script
 *
 */

declare(strict_types=1);

namespace FaviconService;

use Jerrybendy\Favicon\Favicon;
use RuntimeException;
use InvalidArgumentException;

require_once 'config.php';
require_once 'Favicon.php';

// Input validation
if (!isset($_GET['url']) || empty(trim($_GET['url']))) {
    http_response_code(400);
    exit;
}

try {
    $favicon = new Favicon();
    $favicon->debug_mode = defined('DEBUG_MODE') ? DEBUG_MODE : false;
    
    // Configuration constants
    $cacheDir = CACHE_DIR ?? 'cache';
    $hashKey = HASH_KEY ?? throw new RuntimeException('HASH_KEY not defined');
    $defaultIcon = DEFAULT_ICO ?? throw new RuntimeException('DEFAULT_ICO not defined');
    $expire = EXPIRE ?? 86400; // Default to 24 hours

    // Update default HASH_KEY if needed
    if ($hashKey === 'iowen') {
        $newHashKey = substr(hash('sha256', random_bytes(16)), 0, 16);
        $configContent = file_get_contents('config.php');
        if ($configContent === false) {
            throw new RuntimeException('Failed to read config.php');
        }
        $updatedContent = str_replace('iowen', $newHashKey, $configContent);
        if (file_put_contents('config.php', $updatedContent) === false) {
            throw new RuntimeException('Failed to update config.php');
        }
        $hashKey = $newHashKey;
    }

    $favicon->setDefaultIcon($defaultIcon);
    $url = filter_var($_GET['url'], FILTER_SANITIZE_URL);
    
    // URL validation and formatting
    $formatUrl = $favicon->formatUrl($url);
    if ($formatUrl === false) {
        http_response_code(400);
        exit;
    }

    $cache = new Cache($hashKey, $cacheDir);
    
    if ($expire === 0 || (isset($_GET['refresh']) && $_GET['refresh'] === 'true')) {
        // No caching or refresh requested
        // outputFavicon($favicon, $favicon->getFavicon($formatUrl, false));
        $rdata = $favicon->getFavicon($formatUrl, false);
        outputFavicon($favicon, $rdata);
    } else {
        // Try cache first
        $defaultMD5 = md5_file($defaultIcon) ?: throw new RuntimeException('Failed to read default icon');
        $cachedData = $cache->get($formatUrl, $defaultMD5, $expire);
        
        if ($cachedData !== null) {
            outputFavicon($favicon, $cachedData, 'IO');
        } else {
            // Cache miss, fetch and store new favicon
            $content = $favicon->getFavicon($formatUrl, true);
            $cache->set($formatUrl, $content);
            outputFavicon($favicon, $content);
        }
    }

} catch (Exception $e) {
    error_log("Favicon error: " . $e->getMessage());
    http_response_code(500);
    exit;
}

/**
 * Output favicon with appropriate headers
 */
function outputFavicon(Favicon $favicon, string $content, string $cacheType = null): void {
    foreach ($favicon->getHeader() as $header) {
        header($header);
    }
    if ($cacheType) {
        header("X-Cache-Type: $cacheType");
    }
    echo $content;
    exit;
}

/**
 * Cache handling class
 */
class Cache
{
    private string $dir;
    private string $hashKey;

    public function __construct(string $hashKey, string $dir = 'cache')
    {
        $this->hashKey = $hashKey;
        $this->dir = $dir;
    }

    /**
     * Get cached favicon
     */
    public function get(string $key, string $defaultMD5, int $expire): ?string
    {
        $host = strtolower(parse_url($key, PHP_URL_HOST) ?: throw new InvalidArgumentException('Invalid URL'));
        $hash = substr(hash_hmac('sha256', $host, $this->hashKey), 8, 16);
        $filePath = sprintf('%s/%s_%s.txt', $this->dir, $host, $hash);

        if (!is_file($filePath)) {
            return null;
        }

        $data = file_get_contents($filePath);
        if ($data === false) {
            return null;
        }

        $isDefaultIcon = md5($data) === $defaultMD5;
        $effectiveExpire = $isDefaultIcon ? 43200 : $expire;

        if ((time() - filemtime($filePath)) > $effectiveExpire) {
            return null;
        }

        return $data;
    }

    /**
     * Store favicon in cache
     */
    public function set(string $key, string $value): void
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true)) {
            throw new RuntimeException('Failed to create cache directory');
        }

        $host = strtolower(parse_url($key, PHP_URL_HOST) ?: throw new InvalidArgumentException('Invalid URL'));
        $hash = substr(hash_hmac('sha256', $host, $this->hashKey), 8, 16);
        $filePath = sprintf('%s/%s_%s.txt', $this->dir, $host, $hash);

        $fp = fopen($filePath, 'w');
        if ($fp === false) {
            throw new RuntimeException('Unable to open cache file');
        }

        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $value);
            flock($fp, LOCK_UN);
        } else {
            throw new RuntimeException('Failed to acquire file lock');
        }
        fclose($fp);
    }
}