<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Professional Services enquiry from the /about form. consented_at records
 * when the person ticked the consent box; the utm_* columns and short_link_id
 * record which stream (utm_campaign) and channel (utm_source) sent them.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $company
 * @property string $message
 * @property \Illuminate\Support\Carbon $consented_at
 * @property int|null $short_link_id
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_content
 */
class Lead extends Model
{
    /** @use HasFactory<\Database\Factories\LeadFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'company',
        'message',
        'consented_at',
        'short_link_id',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
    ];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
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
