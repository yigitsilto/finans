<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditService
{
    /** Bu anahtarları içeren alanlar denetim kaydına asla yazılmaz. */
    private const SECRET_KEYS = ['password', 'token', 'secret', 'remember', 'api_key', 'apikey', 'authorization', 'cookie'];

    /** Bu alanlar maskelenerek yazılır (yalnızca son 4 karakter). */
    private const MASKED_KEYS = ['iban'];

    public const LOGIN = 'login';

    public const LOGIN_FAILED = 'login_failed';

    public const LOGOUT = 'logout';

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const ROLE_CHANGED = 'role_changed';

    public const DEACTIVATED = 'deactivated';

    public const ACTIVATED = 'activated';

    public const PASSWORD_CHANGED = 'password_changed';

    public const REVERSED = 'reversed';

    public const EXPORTED = 'exported';

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?User $user = null,
        ?string $username = null,
        ?int $httpStatus = null,
    ): AuditLog {
        $user ??= $this->request->user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'username' => $username ?? $user?->username,
            'action' => $action,
            'auditable_type' => $subject ? class_basename($subject) : null,
            'auditable_id' => $subject?->getKey(),
            'method' => $this->request->method(),
            'endpoint' => Str::limit('/'.ltrim($this->request->path(), '/'), 250, ''),
            'http_status' => $httpStatus,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 250, ''),
            'old_values' => $old === null ? null : self::sanitize($old),
            'new_values' => $new === null ? null : self::sanitize($new),
        ]);
    }

    /**
     * Model güncellemesinde yalnızca değişen alanların eski/yeni özetini döndürür.
     *
     * @param  array<string, mixed>  $original  getOriginal() çıktısı (kayıttan önce alınmalı)
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $original, Model $model): array
    {
        $old = [];
        $new = [];
        foreach ($model->getChanges() as $key => $value) {
            if ($key === 'updated_at') {
                continue;
            }
            $old[$key] = $original[$key] ?? null;
            $new[$key] = $model->getAttribute($key);
        }

        return [$old, $new];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function sanitize(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            $lower = strtolower((string) $key);

            if (self::matches($lower, self::SECRET_KEYS)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::sanitize($value);
            } elseif ($value instanceof \BackedEnum) {
                $clean[$key] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $clean[$key] = $value->format(DATE_ATOM);
            } elseif (self::matches($lower, self::MASKED_KEYS) && is_string($value) && $value !== '') {
                $clean[$key] = str_repeat('*', max(strlen($value) - 4, 0)).substr($value, -4);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** @param list<string> $needles */
    private static function matches(string $key, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
