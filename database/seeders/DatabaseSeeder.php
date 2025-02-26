<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 100; $i++) {
            DB::table("questions")->insert([
                "user_id" => $user->id,
                "question" => "Question $i",
                "created_at" => now(),
                "updated_at" => now(),
            ]);
        }
    }
}
