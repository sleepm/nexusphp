<?php

namespace App\Filament\Resources\System\UserBanLogResource\Pages;

use App\Filament\PageListSingle;
use App\Filament\Resources\System\UserBanLogResource;

class ManageUserBanLogs extends PageListSingle
{
    protected static string $resource = UserBanLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
//            Actions\CreateAction::make(),
        ];
    }
}