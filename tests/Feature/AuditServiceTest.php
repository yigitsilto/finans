<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanitize_removes_secrets_and_masks_iban(): void
    {
        $clean = AuditService::sanitize([
            'name' => 'Ali',
            'password' => 'gizli',
            'password_confirmation' => 'gizli',
            'remember_token' => 'abc',
            '_token' => 'csrf',
            'api_key' => 'key',
            'iban' => 'TR330006100519786457841326',
            'nested' => ['access_token' => 'x', 'amount' => '10.00'],
        ]);

        $this->assertSame([
            'name' => 'Ali',
            'iban' => '**********************1326',
            'nested' => ['amount' => '10.00'],
        ], $clean);
    }

    public function test_audit_logs_are_immutable(): void
    {
        $log = app(AuditService::class)->log('login');

        $this->expectException(LogicException::class);
        $log->update(['action' => 'degisti']);
    }

    public function test_audit_logs_cannot_be_deleted(): void
    {
        $log = app(AuditService::class)->log('login');

        try {
            $log->delete();
            $this->fail('Silme engellenmeliydi.');
        } catch (LogicException) {
            $this->assertSame(1, AuditLog::count());
        }
    }
}
