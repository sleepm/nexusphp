<?php

namespace App\Models;

class Sub extends NexusModel
{
    protected $table = 'subs';

    protected $fillable = [
        'torrent_id', 'lang_id', 'title', 'filename', 'added', 'uppedby', 'anonymous', 'size', 'ext', 'hits',
    ];

    protected $casts = [
        'added' => 'datetime',
    ];
}