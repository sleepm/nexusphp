<?php

namespace App\Filament\Resources\System\BansResource\Pages;

use App\Filament\CreateRedirectIndexTrait;
use App\Filament\Resources\System\BansResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateBan extends CreateRecord
{
    use CreateRedirectIndexTrait;

    protected static string $resource = BansResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $firstLong = ip2long($data['first'] ?? '');
        $lastLong = ip2long($data['last'] ?? '');
        abort_unless($firstLong !== false && $lastLong !== false, 422, 'Bad IP address.');
        $data['first'] = $firstLong;
        $data['last'] = $lastLong;
        $data['addedby'] = Auth::id();
        $data['added'] = now()->format('Y-m-d H:i:s');

        return $data;
    }
}
