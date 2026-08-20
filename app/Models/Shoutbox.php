<?php

namespace App\Models;

class Shoutbox extends NexusModel
{
    protected $table = 'shoutbox';

    protected $fillable = [
        'userid', 'date', 'text', 'type',
    ];

    protected $casts = [
        'date' => 'integer',
    ];

    public const TYPE_SHOUTBOX = 'sb';

    public const TYPE_HELPBOX = 'hb';

    public function user()
    {
        return $this->belongsTo(User::class, 'userid');
    }
}