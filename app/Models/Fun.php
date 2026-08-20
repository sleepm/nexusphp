<?php

namespace App\Models;

class Fun extends NexusModel
{
    protected $table = 'fun';

    protected $fillable = [
        'userid', 'added', 'body', 'title', 'status',
    ];

    protected $casts = [
        'added' => 'datetime',
    ];

    public const STATUS_NORMAL = 'normal';

    public const STATUS_DULL = 'dull';

    public const STATUS_NOT_FUNNY = 'notfunny';

    public const STATUS_FUNNY = 'funny';

    public const STATUS_VERY_FUNNY = 'veryfunny';

    public const STATUS_BANNED = 'banned';

    public function votes()
    {
        return $this->hasMany(FunVote::class, 'funid');
    }

    public function isHidden()
    {
        return in_array($this->status, [self::STATUS_BANNED, self::STATUS_DULL], true);
    }
}