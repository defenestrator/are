<?php

namespace App\Models;

use Database\Factories\ShortLinkClickFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One dated click on a short link. It records when, and nothing about who.
 *
 * @property int $id
 * @property int $short_link_id
 * @property Carbon $clicked_at
 */
class ShortLinkClick extends Model
{
    /** @use HasFactory<ShortLinkClickFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'short_link_id',
        'clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'clicked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ShortLink, $this>
     */
    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class);
    }
}
