<?php

namespace App\Models;

class Ban extends NexusModel
{
    protected $fillable = [
        'added', 'addedby', 'comment', 'first', 'last',
    ];

    public function addedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'addedby');
    }
}
