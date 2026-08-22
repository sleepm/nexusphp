<?php

namespace App\Filament\Resources\System\CheaterResource\Pages;

use App\Filament\Resources\System\CheaterResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCheater extends ViewRecord
{
    protected static string $resource = CheaterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
