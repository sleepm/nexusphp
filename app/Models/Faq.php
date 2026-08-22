<?php

namespace App\Models;

class Faq extends NexusModel
{
    protected $table = 'faq';

    const TYPE_CATEG = 'categ';
    const TYPE_ITEM = 'item';

    const FLAG_HIDDEN = 0;
    const FLAG_NORMAL = 1;
    const FLAG_UPDATED = 2;
    const FLAG_NEW = 3;

    public static array $flags = [
        self::FLAG_HIDDEN => 'Hidden',
        self::FLAG_NORMAL => 'Normal',
        self::FLAG_UPDATED => 'Updated',
        self::FLAG_NEW => 'New',
    ];

    protected $fillable = [
        'link_id', 'lang_id', 'type', 'question', 'answer', 'flag', 'categ', 'order',
    ];

    protected $casts = [
        'link_id' => 'integer',
        'lang_id' => 'integer',
        'flag' => 'integer',
        'categ' => 'integer',
        'order' => 'integer',
    ];

    public function language()
    {
        return $this->belongsTo(Language::class, 'lang_id');
    }

    protected static function booted()
    {
        static::saved(function () {
            \Nexus\Database\NexusDB::cache_del('faq');
        });
        static::deleted(function () {
            \Nexus\Database\NexusDB::cache_del('faq');
        });
    }
}
