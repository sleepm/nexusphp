<?php

namespace App\Models;

use Illuminate\Support\Facades\Cache;

class Link extends NexusModel
{
    protected $table = 'links';

    protected $fillable = [
        'name', 'url', 'title',
    ];

    protected static function booted()
    {
        static::saved(function () {
            Cache::forget('index_links');
        });
        static::deleted(function () {
            Cache::forget('index_links');
        });
    }
}
