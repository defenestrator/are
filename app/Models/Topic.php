<?php

namespace App\Models;

use App\Support\RequestMemo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Topic extends Model
{
    /** The memoised current topic is stale (#173). */
    protected static function booted(): void
    {
        static::saved(fn () => RequestMemo::forgetTopic());
        static::deleted(fn () => RequestMemo::forgetTopic());
    }

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
        return app(RequestMemo::class)->remember('topic.current', fn () => self::active()->latest('id')->first());
    }

    /**
     * Replace the current topic, keeping the question queue as it is.
     */
    public static function set(string $topic): self
    {
        RequestMemo::forgetTopic();

        return tap(DB::transaction(function () use ($topic) {
            self::active()->update(['archived_at' => now()]);

            return self::create(['topic' => $topic]);
        }), fn () => RequestMemo::forgetTopic());
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
        RequestMemo::forgetTopic();
    }
}
