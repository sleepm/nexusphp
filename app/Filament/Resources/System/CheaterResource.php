<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\CheaterResource\Pages;
use App\Filament\Resources\System\CheaterResource\RelationManagers;
use App\Models\Cheater;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class CheaterResource extends Resource
{
    protected static ?string $model = Cheater::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 13;

    public static function getNavigationLabel(): string
    {
        return __('admin.sidebar.cheaters');
    }

    public static function getBreadcrumb(): string
    {
        return self::getNavigationLabel();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('added')
                    ->label(__('label.added'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('userid')
                    ->label(__('label.username'))
                    ->formatStateUsing(fn ($record) => new HtmlString(get_username($record->userid, false, true, true, true)))
                    ->sortable(),
                TextColumn::make('hit')
                    ->label(__('label.cheater.hit'))
                    ->sortable(),
                TextColumn::make('torrentid')
                    ->label(__('label.torrent.label'))
                    ->formatStateUsing(fn ($record) => $record->torrent ? new HtmlString('<a href="' . nexus_env('BASEURL') . '/details.php?id=' . $record->torrentid . '">' . htmlspecialchars($record->torrent->name) . '</a>') : __('label.torrent.label'))
                    ->limit(30),
                TextColumn::make('uploaded')
                    ->label(__('label.uploaded'))
                    ->formatStateUsing(fn ($record) => mksize($record->uploaded)),
                TextColumn::make('downloaded')
                    ->label(__('label.downloaded'))
                    ->formatStateUsing(fn ($record) => mksize($record->downloaded)),
                TextColumn::make('anctime')
                    ->label(__('label.cheater.ann_time'))
                    ->formatStateUsing(fn ($record) => $record->anctime . ' sec'),
                TextColumn::make('seeders')
                    ->label(__('label.cheater.seeders')),
                TextColumn::make('leechers')
                    ->label(__('label.cheater.leechers')),
                TextColumn::make('comment')
                    ->label(__('label.comment'))
                    ->limit(30),
                TextColumn::make('dealtwith')
                    ->label(__('label.cheater.dealtwith'))
                    ->formatStateUsing(fn ($record) => $record->dealtwith
                        ? '<font color="green">' . __('label.cheater.yes') . '</font>'
                        : '<font color="red">' . __('label.cheater.no') . '</font>')
                    ->html(),
                TextColumn::make('dealtby')
                    ->label(__('label.cheater.dealtby'))
                    ->formatStateUsing(fn ($record) => $record->dealtby ? new HtmlString(get_username($record->dealtby, false, true, true, true)) : ''),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('dealtwith')
                    ->options([0 => __('label.cheater.no'), 1 => __('label.cheater.yes')])
                    ->label(__('label.cheater.dealtwith')),
                Filter::make('userid')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('userid')
                            ->label(__('label.username'))
                            ->placeholder(__('label.username')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['userid'], fn (Builder $query, $value) => $query->where('userid', $value))),
                Filter::make('torrentid')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('torrentid')
                            ->label(__('label.torrent.label'))
                            ->placeholder(__('label.torrent.label')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['torrentid'], fn (Builder $query, $value) => $query->where('torrentid', $value))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->groupedBulkActions([
                BulkAction::make('setDealt')
                    ->label(__('label.cheater.dealtwith'))
                    ->action(function (Collection $records) {
                        $ids = $records->pluck('id')->toArray();
                        Cheater::query()->whereIn('id', $ids)
                            ->where('dealtwith', 0)
                            ->update(['dealtwith' => 1, 'dealtby' => Auth::id()]);
                        \Nexus\Database\NexusDB::cache_del('staff_new_cheater_count');
                    })
                    ->deselectRecordsAfterCompletion()
                    ->icon('heroicon-o-check-circle'),
                BulkAction::make('bulkDelete')
                    ->label(__('label.cheater.label'))
                    ->action(function (Collection $records) {
                        Cheater::query()->whereIn('id', $records->pluck('id')->toArray())->delete();
                        \Nexus\Database\NexusDB::cache_del('staff_new_cheater_count');
                    })
                    ->deselectRecordsAfterCompletion()
                    ->icon('heroicon-o-trash'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id'),
                TextEntry::make('added')
                    ->label(__('label.added'))
                    ->dateTime('Y-m-d H:i:s'),
                TextEntry::make('userid')
                    ->label(__('label.username'))
                    ->formatStateUsing(fn ($record) => new HtmlString(get_username($record->userid, false, true, true, true))),
                TextEntry::make('hit')
                    ->label(__('label.cheater.hit')),
                TextEntry::make('torrentid')
                    ->label(__('label.torrent.label'))
                    ->formatStateUsing(fn ($record) => $record->torrent ? new HtmlString('<a href="' . nexus_env('BASEURL') . '/details.php?id=' . $record->torrentid . '">' . htmlspecialchars($record->torrent->name) . '</a>') : __('label.torrent.label')),
                TextEntry::make('uploaded')
                    ->label(__('label.uploaded'))
                    ->formatStateUsing(fn ($record) => mksize($record->uploaded)),
                TextEntry::make('downloaded')
                    ->label(__('label.downloaded'))
                    ->formatStateUsing(fn ($record) => mksize($record->downloaded)),
                TextEntry::make('anctime')
                    ->label(__('label.cheater.ann_time'))
                    ->formatStateUsing(fn ($record) => $record->anctime . ' sec'),
                TextEntry::make('seeders')
                    ->label(__('label.cheater.seeders')),
                TextEntry::make('leechers')
                    ->label(__('label.cheater.leechers')),
                TextEntry::make('comment')
                    ->label(__('label.comment')),
                TextEntry::make('dealtwith')
                    ->label(__('label.cheater.dealtwith'))
                    ->formatStateUsing(fn ($record) => $record->dealtwith ? __('label.cheater.yes') : __('label.cheater.no')),
                TextEntry::make('dealtby')
                    ->label(__('label.cheater.dealtby'))
                    ->formatStateUsing(fn ($record) => $record->dealtby ? new HtmlString(get_username($record->dealtby, false, true, true, true)) : ''),
            ])
            ->columns(4);
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
            'index' => Pages\ListCheaters::route('/'),
            'stats' => Pages\CheatStats::route('/stats'),
            'view' => Pages\ViewCheater::route('/{record}'),
        ];
    }
}
