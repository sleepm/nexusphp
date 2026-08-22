<?php

namespace App\Models;

class Request extends NexusModel
{
    protected $fillable = [
        'userid', 'request', 'descr', 'ori_descr', 'comments', 'hits',
        'added', 'amount', 'ori_amount', 'finish', 'cat', 'filledby', 'torrentid',
    ];

    protected $casts = [
        'added' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'userid');
    }
}
