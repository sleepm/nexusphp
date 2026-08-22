<?php

namespace App\Filament\Resources\TorrentCustomFields\Pages;

use App\Filament\EditRedirectIndexTrait;
use App\Filament\Resources\TorrentCustomFields\TorrentCustomFieldResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTorrentCustomField extends EditRecord
{
    use EditRedirectIndexTrait;

    protected static string $resource = TorrentCustomFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
