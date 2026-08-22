<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\BannedEmailsResource\Pages;
use App\Filament\Resources\System\BannedEmailsResource\RelationManagers;
use App\Models\BannedEmail;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class BannedEmailsResource extends Resource
{
    protected static ?string $model = BannedEmail::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 15;

    public static function getNavigationLabel(): string
    {
        return __('admin.sidebar.banned_emails');
    }

    public static function getBreadcrumb(): string
    {
        return self::getNavigationLabel();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Forms\Components\Textarea::make('value')
                            ->label(__('label.banned_email.value'))
                            ->helperText(new HtmlString(__('label.banned_email.value_help')))
                            ->rows(6)
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('value')
                    ->label(__('label.banned_email.value'))
                    ->limit(80),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBannedEmails::route('/'),
        ];
    }
}