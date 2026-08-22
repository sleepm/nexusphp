<?php

namespace App\Models;


class Offer extends NexusModel
{
    protected $fillable = ['userid', 'name', 'descr', 'comments', 'added', 'category', 'yeah', 'against', 'allowed', 'allowedtime'];

    protected $casts = [
        'added' => 'datetime',
        'allowedtime' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'userid');
    }

}
