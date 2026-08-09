<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_has_username_and_phone(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['username', 'phone']));
    }

    public function test_audit_logs_table_exists_with_key_columns(): void
    {
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertTrue(Schema::hasColumns('audit_logs', [
            'occurred_at', 'request_id', 'actor_type', 'user_id', 'action', 'status',
        ]));
    }
}
