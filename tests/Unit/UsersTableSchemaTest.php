<?php

namespace Tests\Unit;

use App\Support\Database\UsersTableSchema;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsersTableSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_users_table_has_deleted_at_column_for_soft_deletes(): void
    {
        UsersTableSchema::ensureSoftDeletesColumn();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasColumn('users', 'deleted_at'));
    }
}
