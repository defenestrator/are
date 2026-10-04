<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Topic extends Model
{
    protected $fillable = [
        'topic',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Topic>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public static function current(): ?self
    {
        return self::active()->latest('id')->first();
    }

    /**
     * Replace the current topic, keeping the question queue as it is.
     */
    public static function set(string $topic): self
    {
        return DB::transaction(function () use ($topic) {
            self::active()->update(['archived_at' => now()]);

            return self::create(['topic' => $topic]);
        });
    }

    /**
     * Archive the current topic and every open question. Votes stay attached to
     * their archived questions so past streams can still be reviewed.
     */
    public static function archiveAll(): void
    {
        DB::transaction(function () {
            self::active()->update(['archived_at' => now()]);
            Question::active()->update(['archived_at' => now()]);
        });
    }
}
