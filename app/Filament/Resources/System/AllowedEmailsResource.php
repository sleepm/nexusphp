<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\AllowedEmailsResource\Pages;
use App\Filament\Resources\System\AllowedEmailsResource\RelationManagers;
use App\Models\AllowedEmail;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class AllowedEmailsResource extends Resource
{
    protected static ?string $model = AllowedEmail::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-check-circle';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 16;

    public static function getNavigationLabel(): string
    {
        return __('admin.sidebar.allowed_emails');
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
                            ->label(__('label.allowed_email.value'))
                            ->helperText(new HtmlString(__('label.allowed_email.value_help')))
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
                    ->label(__('label.allowed_email.value'))
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
            'index' => Pages\ManageAllowedEmails::route('/'),
        ];
    }
}