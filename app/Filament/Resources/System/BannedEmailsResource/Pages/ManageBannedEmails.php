<?php

namespace App\Filament\Resources\System\BannedEmailsResource\Pages;

use App\Filament\PageListSingle;
use App\Filament\Resources\System\BannedEmailsResource;
use App\Models\BannedEmail;

class ManageBannedEmails extends PageListSingle
{
    protected static string $resource = BannedEmailsResource::class;

    public function mount(): void
    {
        parent::mount();
        BannedEmail::query()->firstOrCreate([], ['value' => '']);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}