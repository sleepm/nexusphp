<?php

namespace App\Filament\Resources\System\AdvertisementResource\Pages;

use App\Filament\EditRedirectIndexTrait;
use App\Filament\Resources\System\AdvertisementResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAdvertisement extends EditRecord
{
    use EditRedirectIndexTrait;

    protected static string $resource = AdvertisementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave()
    {
        $this->record->regenerateCode();
        $this->record->save();
    }
}
