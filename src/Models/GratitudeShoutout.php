<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbGratitude\Models;

use Illuminate\Database\Eloquent\Model;

class GratitudeShoutout extends Model
{
    protected $table = 'vb_gratitude_shoutouts';

    /** @var list<string> */
    protected $fillable = [
        'giver_user_id',
        'recipient_staff_id',
        'message',
        'category',
        'points_awarded',
        'posted_channel_id',
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
