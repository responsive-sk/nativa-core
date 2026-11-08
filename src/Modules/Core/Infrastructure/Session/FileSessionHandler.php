<?php

declare(strict_types = 1);

namespace Modules\Core\Infrastructure\Session;

/**
 * FileSessionHandler — custom session handler writing to a specific directory.
 *
 * Implements SessionHandlerInterface directly, bypassing PHP's session.save_path
 * which FPM pools can override via php_admin_value. All session data goes to
 * the configured directory with strict filename validation to prevent path traversal.
 */
final readonly class FileSessionHandler implements \SessionHandlerInterface
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
    }

    public function open(string $path, string $name): bool
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0o755, true);
        }

        return is_dir($this->dir);
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $file = $this->file($id);

        if (!is_file($file)) {
            return '';
        }

        $data = @file_get_contents($file);

        return false !== $data ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        $file = $this->file($id);

        $result = @file_put_contents($file, $data, LOCK_EX);

        return false !== $result;
    }

    public function destroy(string $id): bool
    {
        $file = $this->file($id);

        if (is_file($file)) {
            return @unlink($file);
        }

        return true;
    }

    public function gc(int $max_lifetime): int
    {
        if (!is_dir($this->dir)) {
            return 0;
        }

        $count = 0;
        $threshold = time() - $max_lifetime;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.sess') && $file->getMTime() < $threshold) {
                @unlink($file->getPathname());
                $count++;
            }
        }

        return $count;
    }

    /**
     * Derive the file path from session ID — single directory level,
     * strict hex validation to prevent traversal.
     */
    private function file(string $id): string
    {
        $safe = (string) preg_replace('/[^a-f0-9]/', '', $id);

        if ('' === $safe || strlen($safe) < 8) {
            $safe = hash('sha256', $id);
        }

        return $this->dir . '/' . $safe . '.sess';
    }
}
