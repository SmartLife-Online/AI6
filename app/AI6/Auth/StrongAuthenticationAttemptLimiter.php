<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Config\AuthConfiguration;
use App\AI6\Auth\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class StrongAuthenticationAttemptLimiter
{
    public const PRIMARY_SCOPE = 'primary';

    public const SESSION_SCOPE_LIMIT = 16;

    private const SESSION_KEY = 'ai6.auth.strong_attempts';

    public function __construct(
        private AuthConfiguration $configuration,
        private AuthenticationHmac $hmac,
        private RateLimiter $rateLimiter,
        private AuthenticationAudit $audit,
    ) {}

    public function isLocked(Request $request, User $user, string $scope): bool
    {
        $record = $this->record($request, $user, $scope);

        return $record === false
            || (is_array($record)
                && (int) ($record['attempt_count'] ?? 0) >= $this->configuration->strongAuthenticationMaxAttempts)
            || $this->rateLimiter->tooManyAttempts(
                $this->rateLimitKey($user, $scope),
                $this->configuration->strongAuthenticationMaxAttempts,
            );
    }

    public function recordFailure(Request $request, User $user, string $scope): bool
    {
        $this->assertScope($scope);
        $wasLocked = $this->isLocked($request, $user, $scope);
        $records = $this->records($request);
        $key = hash('sha256', $scope);
        $record = $this->record($request, $user, $scope);
        $attemptCount = $record === false
            ? $this->configuration->strongAuthenticationMaxAttempts
            : min(
                $this->configuration->strongAuthenticationMaxAttempts,
                (int) ($record['attempt_count'] ?? 0) + 1,
            );
        unset($records[$key]);
        $records[$key] = [
            'user_id' => (int) $user->getKey(),
            'scope' => $scope,
            'session_binding' => $this->sessionBinding($request),
            'attempt_count' => $attemptCount,
        ];
        $records = $this->boundedRecords($records);
        $request->session()->put(self::SESSION_KEY, $records);

        $globalAttempts = $this->rateLimiter->hit(
            $this->rateLimitKey($user, $scope),
            $this->configuration->strongAuthenticationDecaySeconds,
        );
        $locked = $attemptCount >= $this->configuration->strongAuthenticationMaxAttempts
            || $globalAttempts >= $this->configuration->strongAuthenticationMaxAttempts;

        if (! $wasLocked
            && $locked
            && $this->rateLimiter->hit(
                $this->auditRateLimitKey($user, $scope),
                $this->configuration->strongAuthenticationDecaySeconds,
            ) === 1) {
            $this->audit->record($user, 'strong_authentication_locked', $this->auditContext($scope));
        }

        return $locked;
    }

    public function clear(Request $request, User $user, string $scope): void
    {
        $this->assertScope($scope);
        $records = $this->records($request);
        $key = hash('sha256', $scope);
        $record = $this->record($request, $user, $scope);

        if ($record === false) {
            $request->session()->forget(self::SESSION_KEY);
        } else {
            unset($records[$key]);

            if ($records === []) {
                $request->session()->forget(self::SESSION_KEY);
            } else {
                $request->session()->put(self::SESSION_KEY, $records);
            }
        }

        $this->rateLimiter->clear($this->rateLimitKey($user, $scope));
        $this->rateLimiter->clear($this->auditRateLimitKey($user, $scope));
    }

    /** @return array<string, mixed>|false|null */
    private function record(Request $request, User $user, string $scope): array|false|null
    {
        $this->assertScope($scope);
        $stored = $request->session()->get(self::SESSION_KEY);

        if ($stored === null) {
            return null;
        }

        if (! is_array($stored)) {
            return false;
        }

        $record = $stored[hash('sha256', $scope)] ?? null;

        if ($record === null) {
            return null;
        }

        if (! is_array($record)
            || ! is_int($record['user_id'] ?? null)
            || $record['user_id'] !== (int) $user->getKey()
            || ! is_string($record['scope'] ?? null)
            || $record['scope'] !== $scope
            || ! is_string($record['session_binding'] ?? null)
            || ! hash_equals($record['session_binding'], $this->sessionBinding($request))
            || ! is_int($record['attempt_count'] ?? null)
            || $record['attempt_count'] < 1) {
            return false;
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private function records(Request $request): array
    {
        $records = $request->session()->get(self::SESSION_KEY, []);

        return is_array($records) ? $records : [];
    }

    /**
     * @param  array<string, mixed>  $records
     * @return array<string, mixed>
     */
    private function boundedRecords(array $records): array
    {
        if (count($records) > self::SESSION_SCOPE_LIMIT) {
            return array_slice($records, -self::SESSION_SCOPE_LIMIT, null, true);
        }

        return $records;
    }

    /** @return array<string, string> */
    private function auditContext(string $scope): array
    {
        if ($scope === self::PRIMARY_SCOPE) {
            return ['method' => 'primary'];
        }

        return [
            'method' => 'step_up',
            'action' => substr($scope, strlen('step-up:')),
        ];
    }

    private function rateLimitKey(User $user, string $scope): string
    {
        $family = $scope === self::PRIMARY_SCOPE ? 'primary' : 'step-up';

        return 'ai6-strong-authentication:'.$family.':'.(int) $user->getKey();
    }

    private function auditRateLimitKey(User $user, string $scope): string
    {
        return $this->rateLimitKey($user, $scope).':lock-audited';
    }

    private function sessionBinding(Request $request): string
    {
        return $this->hmac->digest('AI6-STRONG-AUTHENTICATION-ATTEMPTS-SESSION-V1', [
            $request->session()->getId(),
        ]);
    }

    private function assertScope(string $scope): void
    {
        if ($scope !== self::PRIMARY_SCOPE
            && preg_match('/\Astep-up:[a-z][a-z0-9._-]{0,63}\z/D', $scope) !== 1) {
            throw new InvalidArgumentException('Invalid strong authentication attempt scope.');
        }
    }
}
