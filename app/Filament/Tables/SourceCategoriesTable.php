<?php

namespace App\Filament\Tables;

use App\Models\SourceCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SourceCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SourceCategory::query())
            ->modifyQueryUsing(function (Builder $query) use ($table): Builder {
                $arguments = $table->getArguments();

                if ($playlistId = $arguments['playlist_id'] ?? null) {
                    $query->where('playlist_id', $playlistId);
                }

                return $query;
            })
            ->defaultSort('name', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Category Name'))
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('enabled')
                    ->label(__('Enabled'))
                    ->placeholder(__('All categories'))
                    ->trueLabel(__('Enabled only'))
                    ->falseLabel(__('Disabled only'))
                    ->queries(
                        // "Enabled" means this source category has already been imported as
                        // a Category with enabled=true. Categories carry no soft-delete
                        // column, unlike Groups, so there's no deleted_at check here.
                        // Correlate via source_category_id (the provider-stable ID), which
                        // is the same key PlaylistAlias::series() uses to resolve a
                        // SourceCategory back to its imported Category/Series records,
                        // rather than name matching.
                        true: fn (Builder $query): Builder => $query->whereExists(
                            fn ($subQuery) => $subQuery->selectRaw('1')
                                ->from('categories')
                                ->whereColumn('categories.source_category_id', 'source_categories.source_category_id')
                                ->whereColumn('categories.playlist_id', 'source_categories.playlist_id')
                                ->where('categories.enabled', true)
                        ),
                        false: fn (Builder $query): Builder => $query->whereNotExists(
                            fn ($subQuery) => $subQuery->selectRaw('1')
                                ->from('categories')
                                ->whereColumn('categories.source_category_id', 'source_categories.source_category_id')
                                ->whereColumn('categories.playlist_id', 'source_categories.playlist_id')
                                ->where('categories.enabled', true)
                        ),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(15)
            ->headerActions([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    //
                ]),
            ]);
    }
}
