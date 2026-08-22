<?php

namespace App\Filament\Resources\System\BansResource\Pages;

use App\Filament\PageList;
use App\Filament\Resources\System\BansResource;
use Filament\Actions\CreateAction;

class ListBans extends PageList
{
    protected static string $resource = BansResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
