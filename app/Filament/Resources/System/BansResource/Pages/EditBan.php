<?php

namespace App\Filament\Resources\System\BansResource\Pages;

use App\Filament\EditRedirectIndexTrait;
use App\Filament\Resources\System\BansResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditBan extends EditRecord
{
    use EditRedirectIndexTrait;

    protected static string $resource = BansResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['first'] = long2ip((int) $data['first']);
        $data['last'] = long2ip((int) $data['last']);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $firstLong = ip2long($data['first'] ?? '');
        $lastLong = ip2long($data['last'] ?? '');
        abort_unless($firstLong !== false && $lastLong !== false, 422, 'Bad IP address.');
        $data['first'] = $firstLong;
        $data['last'] = $lastLong;

        return $data;
    }
}
