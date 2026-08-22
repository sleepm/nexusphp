<?php

namespace App\Filament\Resources\System\RuleResource\Pages;

use App\Filament\PageList;
use App\Filament\Resources\System\RuleResource;
use Filament\Actions\CreateAction;

class ListRules extends PageList
{
    protected static string $resource = RuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
