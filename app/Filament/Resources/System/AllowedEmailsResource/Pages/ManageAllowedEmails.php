<?php

namespace App\Filament\Resources\System\AllowedEmailsResource\Pages;

use App\Filament\PageListSingle;
use App\Filament\Resources\System\AllowedEmailsResource;
use App\Models\AllowedEmail;

class ManageAllowedEmails extends PageListSingle
{
    protected static string $resource = AllowedEmailsResource::class;

    public function mount(): void
    {
        parent::mount();
        AllowedEmail::query()->firstOrCreate([], ['value' => '']);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}