<?php

namespace App\Models;


use App\Models\Traits\NexusActivityLogTrait;

class Poll extends NexusModel
{
    use NexusActivityLogTrait;

    protected $fillable = ['added', 'question', 'option0', 'option1', 'option2', 'option3', 'option4', 'option5', 'option6', 'option7', 'option8', 'option9', 'option10', 'option11', 'option12', 'option13', 'option14', 'option15', 'option16', 'option17', 'option18', 'option19'];

    protected $casts = [
        'added' => 'datetime'
    ];

    const MAX_OPTION_INDEX = 19;

    public function answers()
    {
        return $this->hasMany(PollAnswer::class, 'pollid');
    }

}
