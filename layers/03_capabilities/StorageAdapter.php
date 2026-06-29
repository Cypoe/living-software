<?php
/**
 * StorageAdapter.php — Layer 03 storage adapter interface
 *
 * Any external storage backend (S3, Nextcloud WebDAV, FTP, local FS)
 * must implement this interface. The kernel only speaks to storage
 * through this contract — it never imports a concrete driver.
 *
 * Implementing a new adapter:
 *   1. Create capabilities/storage_{name}.php
 *   2. Implement StorageAdapter
 *   3. Register an entity record of type 'storage_backend' with
 *      impl_ref pointing to your file
 *
 * The protocol algebra is storage-agnostic: carriers hold a 'locator'
 * that is resolved by whichever adapter is registered for that scheme.
 */

declare(strict_types=1);

interface StorageAdapter {
    /**
     * Scheme prefix this adapter handles.
     * e.g. 'file', 's3', 'webdav', 'ftp', 'nextcloud'
     */
    public function scheme(): string;

    /**
     * Read the content at $locator.
     * Returns raw bytes as string, or null if not found.
     */
    public function read(string $locator): ?string;

    /**
     * Write $content to $locator.
     * Returns the canonical locator string after write.
     */
    public function write(string $locator, string $content, array $meta = []): string;

    /**
     * Delete the resource at $locator.
     */
    public function delete(string $locator): bool;

    /**
     * Check existence.
     */
    public function exists(string $locator): bool;

    /**
     * List items under a prefix/path.
     * Returns array of locator strings.
     */
    public function list(string $prefix): array;
}

// ─── Registry ────────────────────────────────────────────────────────────────

final class StorageRegistry {
    private static array $adapters = [];

    public static function register(StorageAdapter $adapter): void {
        self::$adapters[$adapter->scheme()] = $adapter;
    }

    public static function for(string $locator): StorageAdapter {
        $scheme = strstr($locator, '://', true) ?: 'file';
        $adapter = self::$adapters[$scheme] ?? null;
        if (!$adapter) throw new RuntimeException("No storage adapter for scheme: {$scheme}");
        return $adapter;
    }

    public static function schemes(): array {
        return array_keys(self::$adapters);
    }
}

// ─── Built-in: local filesystem adapter ─────────────────────────────────────

final class LocalFsAdapter implements StorageAdapter {
    public function scheme(): string { return 'file'; }

    public function read(string $locator): ?string {
        $path = $this->_path($locator);
        return file_exists($path) ? file_get_contents($path) : null;
    }

    public function write(string $locator, string $content, array $meta = []): string {
        $path = $this->_path($locator);
        $dir  = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        file_put_contents($path, $content, LOCK_EX);
        return $locator;
    }

    public function delete(string $locator): bool {
        $path = $this->_path($locator);
        return file_exists($path) && unlink($path);
    }

    public function exists(string $locator): bool {
        return file_exists($this->_path($locator));
    }

    public function list(string $prefix): array {
        $path = $this->_path($prefix);
        if (!is_dir($path)) return [];
        return array_map(
            fn($f) => 'file://' . $f,
            glob(rtrim($path,'/').'/*') ?: []
        );
    }

    private function _path(string $locator): string {
        return str_starts_with($locator, 'file://') ? substr($locator, 7) : $locator;
    }
}

// ─── Built-in: WebDAV adapter (Nextcloud / any WebDAV) ───────────────────────

final class WebDavAdapter implements StorageAdapter {
    public function __construct(
        private string $base_url,
        private string $username,
        private string $password
    ) {}

    public function scheme(): string { return 'webdav'; }

    public function read(string $locator): ?string {
        $url = $this->_url($locator);
        $ch  = $this->_ch($url);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200 ? $body : null;
    }

    public function write(string $locator, string $content, array $meta = []): string {
        $url = $this->_url($locator);
        $ch  = $this->_ch($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS    => $content,
            CURLOPT_HTTPHEADER    => ['Content-Type: application/octet-stream'],
        ]);
        curl_exec($ch);
        curl_close($ch);
        return $locator;
    }

    public function delete(string $locator): bool {
        $ch = $this->_ch($this->_url($locator));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return in_array($code, [200, 204, 404]);
    }

    public function exists(string $locator): bool {
        $ch = $this->_ch($this->_url($locator));
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    public function list(string $prefix): array {
        // Basic PROPFIND depth-1
        $url = $this->_url($prefix);
        $ch  = $this->_ch($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PROPFIND',
            CURLOPT_HTTPHEADER    => ['Depth: 1', 'Content-Type: application/xml'],
            CURLOPT_POSTFIELDS    => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:href/></d:prop></d:propfind>',
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        preg_match_all('#<d:href>([^<]+)</d:href>#', (string)$body, $m);
        return array_map(fn($h) => 'webdav://' . $h, $m[1] ?? []);
    }

    private function _url(string $locator): string {
        $path = str_starts_with($locator, 'webdav://') ? substr($locator, 9) : $locator;
        return rtrim($this->base_url, '/') . '/' . ltrim($path, '/');
    }

    private function _ch(string $url): CurlHandle {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "{$this->username}:{$this->password}",
            CURLOPT_TIMEOUT        => 10,
        ]);
        return $ch;
    }
}

// ─── Auto-register LocalFs on include ───────────────────────────────────────
StorageRegistry::register(new LocalFsAdapter());
