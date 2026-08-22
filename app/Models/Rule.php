<?php

namespace App\Models;

class Rule extends NexusModel
{
    protected $table = 'rules';

    protected $fillable = [
        'lang_id', 'title', 'text',
    ];

    protected $casts = [
        'lang_id' => 'integer',
    ];

    protected static function booted()
    {
        static::saved(function () {
            \Nexus\Database\NexusDB::cache_del('rules');
        });
        static::deleted(function () {
            \Nexus\Database\NexusDB::cache_del('rules');
        });
    }
}
