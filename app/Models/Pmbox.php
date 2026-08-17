<?php

namespace App\Models;

class Pmbox extends NexusModel
{
    protected $table = 'pmboxes';

    protected $fillable = [
        'userid', 'boxnumber', 'name'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'userid');
    }
}