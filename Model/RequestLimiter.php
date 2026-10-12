<?php
declare(strict_types=1);

namespace Zero1\RequestLimiter\Model;

use Credis_Client;
use Magento\Framework\App\DeploymentConfig;
use Psr\Log\LoggerInterface;
use Throwable;

class RequestLimiter
{
    public const WINDOW_SECONDS = 60;
    private const RATE_LIMITED_TTL_SECONDS = 300;

    private Credis_Client $redis;
    private LoggerInterface $logger;
    private ?string $reservedKey = null;
    private ?string $reservedField = null;

    public function __construct(DeploymentConfig $deploymentConfig, LoggerInterface $logger)
    {
        $options = $deploymentConfig->get('cache/frontend/default/backend_options', []);
        $options = is_array($options) ? $options : [];

        $this->redis = new Credis_Client(
            (string)($options['server'] ?? '127.0.0.1'),
            (int)($options['port'] ?? 6379),
            isset($options['timeout']) ? (float)$options['timeout'] : 2.5,
            (string)($options['persistent'] ?? ''),
            (int)($options['database'] ?? 0),
            $options['password'] ?? null,
            $options['username'] ?? null
        );
        $this->logger = $logger;
    }

    public function acquire(string $path, int $concurrentLimit): bool
    {
        if ($this->reservedField !== null) {
            return true;
        }

        $key = 'rate-limit:' . $path;

        try {
            $field = getmypid() . ':' . bin2hex(random_bytes(8));
            $this->logger->debug('Created request limiter reservation.', [
                'field' => $field,
                'path' => $path,
            ]);
            $timestamp = time();
            $activeCount = 0;

            $fields = $this->redis->hKeys($key);
            foreach ($fields as $existingField) {
                $createdAt = $this->redis->hGet($key, $existingField);
                if (!is_numeric($createdAt) || $timestamp - (int)$createdAt > self::WINDOW_SECONDS) {
                    $this->redis->hDel($key, $existingField);
                    continue;
                }

                $activeCount++;
            }
            
            $this->logger->debug('Counted active request limiter reservations.', [
                'field' => $field,
                'active_count' => $activeCount,
                'path' => $path,
            ]);

            if ($activeCount >= max(1, $concurrentLimit)) {
                try {
                    $this->redis->setEx(
                        'rate-limited:' . $path,
                        self::RATE_LIMITED_TTL_SECONDS,
                        (string)$activeCount
                    );
                } catch (Throwable $exception) {
                    $this->logger->error('Request limiter could not store the rate-limited request count.', [
                        'exception' => $exception,
                    ]);
                }

                return false;
            }

            $this->redis->hSet($key, $field, (string)$timestamp);
            $this->reservedKey = $key;
            $this->reservedField = $field;
            $this->redis->expire($key, self::WINDOW_SECONDS + 1);

            return true;
        } catch (Throwable $exception) {
            $this->logger->error('Request limiter could not access Redis; allowing the request.', [
                'exception' => $exception,
            ]);
            return true;
        }
    }

    public function getRateLimitedRequests(): array
    {
        $requests = [];
        $cursor = null;

        try {
            do {
                $keys = $this->redis->scan($cursor, 'rate-limited:*', 100);
                foreach ((array)$keys as $key) {
                    $count = $this->redis->get($key);
                    $ttl = (int)$this->redis->ttl($key);
                    if ($count === false || $ttl <= 0) {
                        continue;
                    }

                    $requests[$key] = [
                        'path' => substr($key, strlen('rate-limited:')),
                        'concurrent_requests' => (int)$count,
                        'expires_in' => $ttl,
                    ];
                }
            } while ((int)$cursor !== 0);
        } catch (Throwable $exception) {
            $this->logger->error('Request limiter could not load rate-limited requests.', [
                'exception' => $exception,
            ]);
            return [];
        }

        $requests = array_values($requests);
        usort($requests, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        return $requests;
    }

    public function release(): void
    {
        if ($this->reservedKey === null || $this->reservedField === null) {
            return;
        }

        try {
            $this->redis->hDel($this->reservedKey, $this->reservedField);
        } catch (Throwable $exception) {
            $this->logger->error('Request limiter could not release its Redis reservation.', [
                'exception' => $exception,
            ]);
        } finally {
            $this->reservedKey = null;
            $this->reservedField = null;
        }
    }
}