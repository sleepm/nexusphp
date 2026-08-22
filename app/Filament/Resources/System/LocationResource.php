<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\LocationResource\Pages;
use App\Filament\Resources\System\LocationResource\RelationManagers;
use App\Models\Location;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 19;

    public static function getNavigationLabel(): string
    {
        return __('admin.sidebar.locations');
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
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('label.name'))
                            ->required(),
                        Forms\Components\TextInput::make('flagpic')
                            ->label(__('label.location.flagpic'))
                            ->helperText(__('label.location.flagpic_help')),
                        Forms\Components\TextInput::make('location_main')
                            ->label(__('label.location.main'))
                            ->required(),
                        Forms\Components\TextInput::make('location_sub')
                            ->label(__('label.location.sub')),
                        Forms\Components\TextInput::make('start_ip')
                            ->label(__('label.ban.first'))
                            ->required()
                            ->rule('ip')
                            ->placeholder('0.0.0.0'),
                        Forms\Components\TextInput::make('end_ip')
                            ->label(__('label.ban.last'))
                            ->required()
                            ->rule('ip')
                            ->placeholder('255.255.255.255'),
                        Forms\Components\TextInput::make('theory_upspeed')
                            ->label(__('label.location.theory_upspeed'))
                            ->numeric()
                            ->default(10),
                        Forms\Components\TextInput::make('practical_upspeed')
                            ->label(__('label.location.practical_upspeed'))
                            ->numeric()
                            ->default(10),
                        Forms\Components\TextInput::make('theory_downspeed')
                            ->label(__('label.location.theory_downspeed'))
                            ->numeric()
                            ->default(10),
                        Forms\Components\TextInput::make('practical_downspeed')
                            ->label(__('label.location.practical_downspeed'))
                            ->numeric()
                            ->default(10),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('name')
                    ->label(__('label.name'))
                    ->sortable(),
                ImageColumn::make('flagpic')
                    ->label(__('label.location.flagpic'))
                    ->url(fn (Location $record) => "/pic/location/{$record->flagpic}")
                    ->size(24),
                TextColumn::make('location_main')
                    ->label(__('label.location.main'))
                    ->limit(30),
                TextColumn::make('location_sub')
                    ->label(__('label.location.sub'))
                    ->limit(30),
                TextColumn::make('start_ip')
                    ->label(__('label.ban.first')),
                TextColumn::make('end_ip')
                    ->label(__('label.ban.last')),
                TextColumn::make('theory_upspeed')
                    ->label(__('label.location.theory_upspeed'))
                    ->numeric(),
                TextColumn::make('practical_upspeed')
                    ->label(__('label.location.practical_upspeed'))
                    ->numeric(),
                TextColumn::make('theory_downspeed')
                    ->label(__('label.location.theory_downspeed'))
                    ->numeric(),
                TextColumn::make('practical_downspeed')
                    ->label(__('label.location.practical_downspeed'))
                    ->numeric(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
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
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/create'),
            'edit' => Pages\EditLocation::route('/{record}/edit'),
        ];
    }
}
