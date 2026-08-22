<?php

namespace App\Models;

class AdClick extends NexusModel
{
    protected $table = 'adclicks';

    protected $fillable = ['adid', 'userid', 'added'];

    protected $casts = [
        'added' => 'datetime:Y-m-d H:i:s',
    ];

    public function advertisement()
    {
        return $this->belongsTo(Advertisement::class, 'adid');
    }
}
