<?php

namespace App\Filament\Resources\AedProfiles;

use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\AedProfiles\Pages\CreateAedProfile;
use App\Filament\Resources\AedProfiles\Pages\EditAedProfile;
use App\Filament\Resources\AedProfiles\Pages\ListAedProfiles;
use App\Models\AedProfile;
use App\Models\Channel;
use App\Models\Group;
use App\Services\AedExtractorService;
use App\Services\DateFormatService;
use App\Traits\HasUserFiltering;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class AedProfileResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = AedProfile::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $label = 'AED Profile';

    protected static ?string $pluralLabel = 'AED Profiles';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('EPG');
    }

    public static function getModelLabel(): string
    {
        return __('AED Profile');
    }

    public static function getPluralModelLabel(): string
    {
        return __('AED Profiles');
    }

    public static function getNavigationSort(): ?int
    {
        return 10;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::getForm());
    }

    public static function getForm(): array
    {
        return [
            TextInput::make('name')
                ->label(__('Profile Name'))
                ->required()
                ->maxLength(255)
                ->placeholder(__('e.g. DAZN PPV')),

            Section::make(__('Source Extraction'))
                ->compact()
                ->description(__('Define regex patterns to extract event data from the channel title. Capture group 1 is used when present; otherwise the full match.'))
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('title_regex')
                            ->label(__('Title Regex'))
                            ->placeholder(__('^(.*?)\\\\s*\\\\[DAZN\\\\]'))
                            ->helperText(__('Extracts the event title. Leave blank to use the full channel title.'))
                            ->maxLength(500),

                        TextInput::make('team_delimiter')
                            ->label(__('Team Delimiter (Optional)'))
                            ->placeholder(__('vs.'))
                            ->helperText(__('Split the extracted title into {team1} / {team2} using this delimiter.'))
                            ->maxLength(20),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('time_regex')
                            ->label(__('Time Regex'))
                            ->placeholder('\((\d{1,2}:\d{2}\s*[AP]M)\s+ET\)')
                            ->helperText(__('Extracts the start time string. Capture group 1 recommended.'))
                            ->maxLength(500),

                        TextInput::make('time_format')
                            ->label(__('Time Format'))
                            ->placeholder(__('g:i A|H:i'))
                            ->helperText(__('PHP date format(s) to parse the extracted time. Separate multiple with |'))
                            ->maxLength(100),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('date_regex')
                            ->label(__('Date Regex (Leave Blank if Entry Contains no Date)'))
                            ->placeholder('\((\d{2}\.\d{2})\s')
                            ->helperText(__('Extracts the start date. Leave blank if the title contains no date.'))
                            ->maxLength(500),

                        TextInput::make('date_format')
                            ->label(__('Date Format (Leave Blank if Entry Contains no Date)'))
                            ->placeholder(__('m.d'))
                            ->helperText(__('PHP date format(s) to parse the extracted date. Separate multiple with |'))
                            ->maxLength(100),
                    ]),

                    Grid::make(2)->schema([
                        Select::make('source_timezone')
                            ->label(__('Timezone of Source'))
                            ->options(fn () => collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz]))
                            ->searchable()
                            ->default('UTC'),

                        TextInput::make('logo_url')
                            ->label(__('Logo URL (Optional)'))
                            ->url()
                            ->maxLength(500)
                            ->placeholder(__('https://example.com/logo.png')),
                    ]),
                ]),

            Section::make(__('Output Format'))
                ->compact()
                ->description(__('Define how the extracted data is formatted in the generated EPG programme.'))
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('output_timezone')
                            ->label(__('Output Timezone'))
                            ->options(fn () => collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz]))
                            ->searchable()
                            ->default(fn () => config('dev.timezone') ?: 'UTC'),

                        TextInput::make('event_duration_minutes')
                            ->label(__('Event Duration (minutes)'))
                            ->numeric()
                            ->default(180)
                            ->minValue(1)
                            ->maxValue(1440)
                            ->helperText(__('How long the generated EPG event lasts (default: 180 = 3 hours).')),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('title_format')
                            ->label(__('Title Output Format'))
                            ->default('{title}')
                            ->maxLength(500)
                            ->helperText(__('Available: {title}, {team1}, {team2}, {channel}, {date}, {time}')),

                        TextInput::make('description_format')
                            ->label(__('Description Output Format'))
                            ->placeholder('{title} — {date} at {time}')
                            ->maxLength(500)
                            ->helperText(__('Leave blank to use the title. Same variables as title format.')),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('pre_event_format')
                            ->label(__('Pre-Event Format'))
                            ->default('Live in {time_until}: {title}')
                            ->maxLength(500)
                            ->helperText(__('Padding slots before the event. Available: {time_until}, {title}, {channel}, {date}, {time}')),

                        TextInput::make('post_event_format')
                            ->label(__('Post-Event Format'))
                            ->default('Signing Off')
                            ->maxLength(500)
                            ->helperText(__('Padding slots after the event ends. Available: {title}, {channel}, {date}, {time}')),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('no_event_format')
                            ->label(__('No Event Format (Optional)'))
                            ->default('{channel}')
                            ->maxLength(500)
                            ->helperText(__('Used when regex extraction fails entirely. {channel} = original channel title.')),

                        TextInput::make('category')
                            ->label(__('EPG Category (Optional)'))
                            ->placeholder(__('Sports'))
                            ->maxLength(100),
                    ]),
                ]),

            Actions::make([
                Action::make('test_extraction')
                    ->label(__('Test Extraction'))
                    ->icon('heroicon-o-beaker')
                    ->color('gray')
                    ->slideOver()
                    ->modalWidth('2xl')
                    ->modalHeading(__('Test Extraction'))
                    ->modalDescription(__('Select a group or channel to load sample titles, then run the test to see extraction results inline.'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->schema(function (Get $get): array {
                        return [
                            // Seed hidden fields from the parent form so the inner Run Test action can read them
                            Hidden::make('_title_regex')->default($get('title_regex')),
                            Hidden::make('_time_regex')->default($get('time_regex')),
                            Hidden::make('_time_format')->default($get('time_format')),
                            Hidden::make('_source_timezone')->default($get('source_timezone') ?? 'UTC'),
                            Hidden::make('_date_regex')->default($get('date_regex')),
                            Hidden::make('_date_format')->default($get('date_format')),
                            Hidden::make('_team_delimiter')->default($get('team_delimiter')),
                            Hidden::make('_output_timezone')->default($get('output_timezone') ?? 'UTC'),
                            Hidden::make('_event_duration_minutes')->default($get('event_duration_minutes') ?? 180),
                            Hidden::make('_title_format')->default($get('title_format') ?? '{title}'),
                            Hidden::make('_description_format')->default($get('description_format')),
                            Hidden::make('_no_event_format')->default($get('no_event_format') ?? '{channel}'),
                            Hidden::make('_pre_event_format')->default($get('pre_event_format') ?? 'Live in {time_until}: {title}'),
                            Hidden::make('_post_event_format')->default($get('post_event_format') ?? 'Signing Off'),
                            Hidden::make('tested')->default(false),
                            Hidden::make('match_count')->default(''),

                            Grid::make(2)->schema([
                                Select::make('group_id')
                                    ->label(__('Filter by Group'))
                                    ->placeholder(__('All groups'))
                                    ->options(fn () => Group::where([
                                        'type' => 'live',
                                        'user_id' => auth()->id(),
                                    ])->get(['name', 'id'])->pluck('name', 'id'))
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?int $state): void {
                                        $set('channel_id', null);
                                        if ($state) {
                                            $titles = auth()->user()->channels()
                                                ->withoutEagerLoads()
                                                ->where('group_id', $state)
                                                ->limit(100)
                                                ->pluck('title')
                                                ->filter()
                                                ->unique()
                                                ->values();
                                            $set('samples', $titles->implode("\n"));
                                            $set('results', []);
                                            $set('tested', false);
                                            $set('match_count', '');
                                        }
                                    }),

                                Select::make('channel_id')
                                    ->label(__('Specific Channel'))
                                    ->placeholder(__('Search channels...'))
                                    ->searchable()
                                    ->live()
                                    ->getSearchResultsUsing(function (string $search, Get $get) {
                                        $searchLower = strtolower($search);
                                        $channels = auth()->user()->channels()
                                            ->withoutEagerLoads()
                                            ->with('playlist')
                                            ->when($get('group_id'), fn ($q, $gid) => $q->where('group_id', $gid))
                                            ->where(function ($query) use ($searchLower) {
                                                $query->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(stream_id) LIKE ?', ["%{$searchLower}%"])
                                                    ->orWhereRaw('LOWER(stream_id_custom) LIKE ?', ["%{$searchLower}%"]);
                                            })
                                            ->limit(50)
                                            ->get();

                                        $options = [];
                                        foreach ($channels as $channel) {
                                            $displayTitle = $channel->title_custom ?: $channel->title;
                                            $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                                            $options[$channel->id] = "{$displayTitle} [{$playlistName}]";
                                        }

                                        return $options;
                                    })
                                    ->afterStateUpdated(function (Set $set, ?int $state): void {
                                        if ($state) {
                                            $channel = Channel::find($state);
                                            $set('samples', $channel?->title ?? $channel?->name ?? '');
                                            $set('results', []);
                                            $set('tested', false);
                                            $set('match_count', '');
                                        }
                                    }),
                            ]),

                            Textarea::make('samples')
                                ->label(__('Sample Titles'))
                                ->placeholder(__('Select a group or channel above, or paste titles here — one per line'))
                                ->rows(6)
                                ->columnSpanFull(),

                            Actions::make([
                                Action::make('run_test')
                                    ->label(__('Run Test'))
                                    ->icon('heroicon-o-play')
                                    ->color('primary')
                                    ->action(function (Get $get, Set $set): void {
                                        $titles = array_values(array_filter(array_map('trim', explode("\n", (string) ($get('samples') ?? '')))));

                                        if (empty($titles)) {
                                            Notification::make()
                                                ->title(__('No titles to test'))
                                                ->body(__('Select a group or channel, or paste titles into the box above.'))
                                                ->warning()
                                                ->send();

                                            return;
                                        }

                                        $profile = new AedProfile;
                                        $profile->title_regex = $get('_title_regex');
                                        $profile->time_regex = $get('_time_regex');
                                        $profile->time_format = $get('_time_format');
                                        $profile->source_timezone = $get('_source_timezone') ?? 'UTC';
                                        $profile->date_regex = $get('_date_regex');
                                        $profile->date_format = $get('_date_format');
                                        $profile->team_delimiter = $get('_team_delimiter');
                                        $profile->output_timezone = $get('_output_timezone') ?? 'UTC';
                                        $profile->event_duration_minutes = (int) ($get('_event_duration_minutes') ?? 180);
                                        $profile->title_format = $get('_title_format') ?? '{title}';
                                        $profile->description_format = $get('_description_format');
                                        $profile->no_event_format = $get('_no_event_format') ?? '{channel}';
                                        $profile->pre_event_format = $get('_pre_event_format') ?? 'Live in {time_until}: {title}';
                                        $profile->post_event_format = $get('_post_event_format') ?? 'Signing Off';

                                        $service = app(AedExtractorService::class);
                                        $rows = [];
                                        foreach ($titles as $title) {
                                            $result = $service->extract($profile, $title);
                                            $rows[] = [
                                                'input' => $title,
                                                'status' => $result ? __('Match') : __('No match'),
                                                'extracted_title' => $result?->title ?? '',
                                                'time' => $result?->hasTime()
                                                    ? $result->start->format('M j g:i A T').' – '.$result->end->format('g:i A')
                                                    : '',
                                            ];
                                        }

                                        $matched = count(array_filter($rows, fn ($r) => $r['status'] === __('Match')));
                                        $total = count($rows);
                                        $set('match_count', __("{$matched} of {$total} titles matched"));
                                        $set('results', $rows);
                                        $set('tested', true);
                                    }),
                            ]),

                            TextEntry::make('match_count_display')
                                ->label('')
                                ->state(fn (Get $get): string => (string) ($get('match_count') ?? ''))
                                ->visible(fn (Get $get): bool => filled($get('match_count')))
                                ->columnSpanFull(),

                            Repeater::make('results')
                                ->label('')
                                ->table([
                                    TableColumn::make(__('Input'))->width('35%'),
                                    TableColumn::make(__('Status'))->width('15%'),
                                    TableColumn::make(__('Extracted Title'))->width('25%'),
                                    TableColumn::make(__('Time'))->width('25%'),
                                ])
                                ->schema([
                                    TextInput::make('input')->hiddenLabel()->disabled(),
                                    TextEntry::make('status')
                                        ->hiddenLabel()
                                        ->badge()
                                        ->color(fn (string $state): string => $state === __('Match') ? 'success' : 'gray'),
                                    TextInput::make('extracted_title')->hiddenLabel()->disabled(),
                                    TextInput::make('time')->hiddenLabel()->disabled(),
                                ])
                                ->default([])
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->visible(fn (Get $get): bool => (bool) $get('tested') && filled($get('match_count')))
                                ->columnSpanFull(),
                        ];
                    }),
            ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('channels_count')
                    ->label(__('Channels'))
                    ->counts('channels')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('groups_count')
                    ->label(__('Groups'))
                    ->counts('groups')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('event_duration_minutes')
                    ->label(__('Duration (min)'))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('output_timezone')
                    ->label(__('Output TZ'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->button()->hiddenLabel()->size('sm'),
                EditAction::make()
                    ->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAedProfiles::route('/'),
            // 'create' => CreateAedProfile::route('/create'),
            // 'edit' => EditAedProfile::route('/{record}/edit'),
        ];
    }
}
