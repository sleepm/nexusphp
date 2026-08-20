<?php

namespace App\Models;

class FunVote extends NexusModel
{
    protected $table = 'funvotes';

    protected $primaryKey = ['funid', 'userid'];

    public $incrementing = false;

    protected $fillable = [
        'funid', 'userid', 'added', 'vote',
    ];

    protected $casts = [
        'added' => 'datetime',
    ];

    public const VOTE_FUN = 'fun';

    public const VOTE_DULL = 'dull';
}