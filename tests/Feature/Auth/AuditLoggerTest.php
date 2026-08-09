<?php

namespace Tests\Feature\Auth;

use App\Support\AuditAction;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_writes_row_with_request_id_and_action(): void
    {
        AuditLogger::record(AuditAction::AUTH_LOGIN_FAILED, 'failed', [
            'reason_code' => 'BAD_CREDENTIALS',
            'metadata_json' => ['username' => 'someone'],
        ]);

        $row = DB::table('audit_logs')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('AUTH.LOGIN_FAILED', $row->action);
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->request_id);
        $this->assertNotNull($row->occurred_at);
    }
}
