<?php

declare(strict_types = 1);

namespace Modules\Security\Application\Services;

use Modules\Security\Infrastructure\Persistence\Statement;

/**
 * RateLimiter — DB-backed atomic rate limiting.
 *
 * State persists in the users.db tables rate_limits ("key", count, expires)
 * and login_attempts (email, ip_address, attempted_at), surviving restarts.
 *
 * Schema ownership: the canonical DDL lives in the Users module migrator
 * (task 7.1). This service self-heals with a cheap existence check (not a
 * CREATE per request) so it works on a fresh clone before any migrator runs.
 */
final readonly class RateLimiter
{
    private const int DEFAULT_LOGIN_LIMIT = 5;

    private const int DEFAULT_LOGIN_WINDOW = 60;

    public function __construct(private \PDO $pdo)
    {
        $this->ensureSchema();
    }

    /**
     * Check whether a request is allowed under a per-key limit.
     *
     * Counting is a single atomic INSERT ... ON CONFLICT upsert, so
     * concurrent requests cannot both pass a shared limit (no check-then-act
     * race). Expired windows reset to a fresh window. The upserted count is
     * read back with a SELECT because SQLite < 3.35 has no RETURNING clause.
     *
     * @param string $key           rate-limit key (e.g. 'login:127.0.0.1')
     * @param int    $maxRequests   maximum requests in the window
     * @param int    $windowSeconds window length in seconds
     */
    public function isAllowed(string $key, int $maxRequests, int $windowSeconds): bool
    {
        $this->pruneExpired();

        $now = time();
        $expires = $now + $windowSeconds;

        $stmt = Statement::prepare($this->pdo, <<<'SQL'
                INSERT INTO rate_limits ("key", count, expires)
                VALUES (:key, 1, :expires)
                ON CONFLICT("key") DO UPDATE SET
                    count   = CASE WHEN expires < :now THEN 1 ELSE count + 1 END,
                    expires = CASE WHEN expires < :now THEN :expires ELSE expires END
            SQL);
        $stmt->execute(['key' => $key, 'expires' => $expires, 'now' => $now]);

        // SQLite < 3.35 has no RETURNING clause (syntax error on older
        // servers) — read the upserted count back instead. The upsert is a
        // single atomic statement, so this SELECT cannot observe a stale
        // count from a concurrent request.
        $countStmt = Statement::prepare($this->pdo, 'SELECT count FROM rate_limits WHERE "key" = :key');
        $countStmt->execute(['key' => $key]);
        $count = (int) $countStmt->fetchColumn();

        return $count <= $maxRequests;
    }

    /**
     * Convenience: the login limit (5 attempts / minute per key).
     */
    public function isLoginAllowed(string $key): bool
    {
        return $this->isAllowed($key, self::DEFAULT_LOGIN_LIMIT, self::DEFAULT_LOGIN_WINDOW);
    }

    /**
     * Seconds until a blocked key may retry (0 when allowed).
     */
    public function getRetryAfter(string $key): int
    {
        $stmt = Statement::prepare($this->pdo, 'SELECT expires FROM rate_limits WHERE "key" = :key');
        $stmt->execute(['key' => $key]);
        /** @var array{expires: string}|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (false === $row) {
            return 0;
        }

        return max(0, (int) $row['expires'] - time());
    }

    /**
     * Reset the limit for a key (e.g. after successful login).
     */
    public function reset(string $key): void
    {
        Statement::prepare($this->pdo, 'DELETE FROM rate_limits WHERE "key" = :key')
            ->execute(['key' => $key]);
    }

    /**
     * Record a failed login attempt (per-email + per-IP audit trail).
     */
    public function recordLoginAttempt(string $email, string $ip): void
    {
        Statement::prepare($this->pdo, 'INSERT INTO login_attempts (id, email, ip_address, attempted_at) VALUES (:id, :email, :ip, :at)')
            ->execute([
                'id'    => bin2hex(random_bytes(16)),
                'email' => $email,
                'ip'    => $ip,
                'at'    => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Delete expired rate-limit rows.
     *
     * @return int number of pruned rows
     */
    public function pruneExpired(): int
    {
        return (int) $this->pdo->exec('DELETE FROM rate_limits WHERE expires < ' . time());
    }

    /**
     * Ensure the required tables exist — gated on a cheap existence check so
     * no DDL runs on requests after the schema exists.
     */
    private function ensureSchema(): void
    {
        if (!$this->tableExists('rate_limits')) {
            $this->pdo->exec(<<<'SQL'
                    CREATE TABLE IF NOT EXISTS rate_limits (
                        "key" VARCHAR(255) PRIMARY KEY,
                        count INTEGER NOT NULL DEFAULT 0,
                        expires INTEGER NOT NULL
                    )
                SQL);
        }

        if (!$this->tableExists('login_attempts')) {
            $this->pdo->exec(<<<'SQL'
                    CREATE TABLE IF NOT EXISTS login_attempts (
                        id VARCHAR(32) PRIMARY KEY,
                        email VARCHAR(255) NOT NULL,
                        ip_address VARCHAR(45),
                        attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )
                SQL);
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_email ON login_attempts(email)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip_address)');
        }
    }

    private function tableExists(string $name): bool
    {
        $stmt = Statement::prepare($this->pdo, "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name");
        $stmt->execute(['name' => $name]);

        return false !== $stmt->fetchColumn();
    }
}
