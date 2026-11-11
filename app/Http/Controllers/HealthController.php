<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthController extends Controller
{
    /**
     * Uygulama ve veritabanı sağlık kontrolü.
     * Hata ayrıntısı yalnızca log'a yazılır, yanıta konmaz.
     */
    public function __invoke(): JsonResponse
    {
        $database = 'ok';

        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            Log::error('Health check: veritabanı bağlantısı başarısız', ['exception' => $e->getMessage()]);
            $database = 'error';
        }

        $healthy = $database === 'ok';

        return response()->json([
            'status' => $healthy ? 'ok' : 'error',
            'database' => $database,
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
