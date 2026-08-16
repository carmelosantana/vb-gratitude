<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbGratitude\Models;

use App\Plugins\PluginModel;

/**
 * @property string $id
 * @property string $tenant_type
 * @property string $tenant_id
 * @property string $user_id
 * @property string $badge_key
 * @property \Illuminate\Support\Carbon $earned_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class GratitudeBadgeAward extends PluginModel
{
    protected $table = 'vb_gratitude_badge_awards';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'badge_key',
        'earned_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'earned_at' => 'datetime',
    ];

    /**
     * Attributes withheld from array/JSON serialization.
     *
     * Controllers in this plugin return models DIRECTLY inside the
     * ApiResponse envelope, so serialization is the API contract:
     * every column NOT listed here is in the response body the moment
     * it exists on the row. Add anything internal — tokens, secrets,
     * raw provider payloads, notes not meant for the caller — here as
     * you add the column, not after someone finds it in a response.
     *
     * @var list<string>
     */
    protected $hidden = [
        //
    ];
}
