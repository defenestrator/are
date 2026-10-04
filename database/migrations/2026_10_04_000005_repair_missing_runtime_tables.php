<?php

use App\Support\RuntimeTables;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Also repairs installations whose original migrations are already recorded.
        // Each table is independent: retries preserve existing rows and sibling tables.
        RuntimeTables::sessions();
        RuntimeTables::cache();
        RuntimeTables::queues();
    }

    public function down(): void
    {
        // This repair adopts shared tables and cannot know who created them.
        // A code rollback retains this compatible schema instead of deleting data.
    }
};
