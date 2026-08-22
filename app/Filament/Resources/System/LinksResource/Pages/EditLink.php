<?php

namespace App\Filament\Resources\System\LinksResource\Pages;

use App\Filament\EditRedirectIndexTrait;
use App\Filament\Resources\System\LinksResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLink extends EditRecord
{
    use EditRedirectIndexTrait;

    protected static string $resource = LinksResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
