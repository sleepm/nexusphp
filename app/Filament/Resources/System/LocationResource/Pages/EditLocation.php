<?php

namespace App\Filament\Resources\System\LocationResource\Pages;

use App\Filament\EditRedirectIndexTrait;
use App\Filament\Resources\System\LocationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLocation extends EditRecord
{
    use EditRedirectIndexTrait;

    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['theory_upspeed', 'practical_upspeed', 'theory_downspeed', 'practical_downspeed'] as $field) {
            $data[$field] = (int) ($data[$field] ?? 10);
        }

        return $data;
    }
}
