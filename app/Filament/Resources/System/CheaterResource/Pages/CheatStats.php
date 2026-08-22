<?php

namespace App\Filament\Resources\System\CheaterResource\Pages;

use App\Filament\Resources\System\CheaterResource;
use App\Models\User;
use Filament\Resources\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class CheatStats extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = CheaterResource::class;

    protected string $view = 'filament.pages.cheat-stats';

    protected static ?string $title = '';

    public function getTitle(): string
    {
        return __('label.cheater.label');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(self::getBaseQuery())
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('username')
                    ->label(__('label.username'))
                    ->formatStateUsing(fn ($record) => new HtmlString(get_username($record->id, false, true, true, true)))
                    ->sortable(),
                TextColumn::make('added')
                    ->label(__('label.cheater.registered'))
                    ->formatStateUsing(fn ($record) => $record->added == '0000-00-00 00:00:00' || $record->added == null ? 'N/A' : get_elapsed_time(strtotime($record->added)) . ' ago'),
                TextColumn::make('uploaded')
                    ->label(__('label.uploaded'))
                    ->formatStateUsing(fn ($record) => mksize($record->uploaded)),
                TextColumn::make('downloaded')
                    ->label(__('label.downloaded'))
                    ->formatStateUsing(fn ($record) => mksize($record->downloaded)),
                TextColumn::make('share_ratio')
                    ->label(__('label.ratio'))
                    ->formatStateUsing(function ($record) {
                        if ($record->downloaded > 0) {
                            $ratio = number_format($record->uploaded / $record->downloaded, 3);
                            $color = get_ratio_color($ratio);
                            return $color ? '<font color="' . $color . '">' . $ratio . '</font>' : $ratio;
                        }
                        return $record->uploaded > 0 ? 'Inf.' : '---';
                    })
                    ->html(),
                TextColumn::make('cheat')
                    ->label(__('label.cheater.cheat_value'))
                    ->sortable(),
            ])
            ->defaultSort('cheat', 'desc')
            ->filters([
                SelectFilter::make('class')
                    ->label(__('label.user.class'))
                    ->options(function () {
                        $options = [1 => __('label.cheater.label')];
                        foreach (User::$classes as $class => $info) {
                            $options[$class + 2] = '<= ' . ($info['text'] ?? $class);
                        }
                        return $options;
                    })
                    ->query(function (Builder $query, array $data) {
                        return $query->when($data['value'] > 2, fn (Builder $query) => $query->where('class', '<', $data['value'] - 1));
                    }),
                SelectFilter::make('ratio')
                    ->label(__('label.ratio'))
                    ->options([
                        1 => __('label.cheater.label'),
                        2 => '>= 1.000',
                        3 => '>= 2.000',
                        4 => '>= 3.000',
                        5 => '>= 4.000',
                        6 => '>= 5.000',
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query->when($data['value'] > 1, fn (Builder $query) => $query->whereRaw('(uploaded / downloaded) > ' . ($data['value'] - 1)));
                    }),
            ])
            ->paginated([20]);
    }

    private static function getBaseQuery(): Builder
    {
        return User::query()
            ->where('enabled', 1)
            ->where('downloaded', '>', 0)
            ->where('uploaded', '>', 0);
    }
}
