<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Denetim kayıtları. Yalnızca Admin (`can:view-audit`). Kayıtlar salt okunurdur. */
class AuditLogController extends Controller
{
    public const ACTIONS = [
        'login' => 'Giriş',
        'login_failed' => 'Başarısız giriş',
        'logout' => 'Çıkış',
        'created' => 'Oluşturma',
        'updated' => 'Güncelleme',
        'reversed' => 'İptal / ters kayıt',
        'role_changed' => 'Rol değişikliği',
        'deactivated' => 'Pasife alma',
        'activated' => 'Aktifleştirme',
        'password_changed' => 'Parola değişikliği',
        'exported' => 'Rapor indirme',
    ];

    public function index(Request $request): View
    {
        $filters = self::validateFilters($request);

        return view('audit.index', [
            'logs' => self::query($filters)->with('user')->paginate(50)->withQueryString(),
            'filters' => $filters,
            'users' => User::orderBy('username')->pluck('username', 'id'),
            'types' => AuditLog::whereNotNull('auditable_type')->distinct()->orderBy('auditable_type')->pluck('auditable_type'),
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        return view('audit.show', ['log' => $auditLog->load('user')]);
    }

    /** @return array<string, mixed> */
    public static function validateFilters(Request $request): array
    {
        return $request->validate([
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::ACTIONS))],
            'type' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
    }

    public static function query(array $filters): Builder
    {
        return AuditLog::query()
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('auditable_type', $t))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], Str::lower($term)).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(endpoint) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(username, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('COALESCE(ip_address, \'\') LIKE ?', [$like]));
            })
            ->orderByDesc('id');
    }
}
