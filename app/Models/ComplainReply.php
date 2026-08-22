<?php

namespace App\Models;

class ComplainReply extends NexusModel
{
    protected $table = 'complain_replies';

    protected $fillable = [
        'complain', 'userid', 'added', 'body', 'ip',
    ];

    protected $casts = [
        'added' => 'datetime',
    ];
}