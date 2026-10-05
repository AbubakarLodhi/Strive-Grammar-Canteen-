<?php

namespace App\Filament\Pages;

use App\Enums\AttachmentMetaType;
use App\Enums\AttachmentType;
use App\Models\Merchant;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EditProfile extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'merchant';
    }

    // 🔴 IMPORTANT: Page MUST have a view
    protected string $view = 'filament.pages.edit-profile';

    public array $data = [];

    public static function getLabel(): string
    {
        return 'Edit Profile';
    }

    public function getTitle(): string
    {
        return 'Edit Profile';
    }

    /**
     * Pre-fill form (including existing profile photo)
     */
    public function mount(): void
    {
        $merchant = Auth::guard('merchant')->user();

        abort_unless($merchant instanceof Merchant, 403);

        $this->data = [
            'name' => $merchant->name,
            'email' => $merchant->email,
            'profile_photo' => $merchant->profilePhoto
                ? [$merchant->profilePhoto->photo_url]
                : null,
            'phone' => $merchant->phone,
            'address_line_1' => $merchant->address_line_1,
            'address_line_2' => $merchant->address_line_2,
            'city' => $merchant->city,
            'website' => $merchant->website,
            'whatsapp_number' => $merchant->whatsapp_number,
            'ntn_number' => $merchant->ntn_number,
            'extra_fields' => $merchant->extra_fields ?? [],
        ];
    }

    /**
     * Filament v4 form schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Profile')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->disabled()
                            ->dehydrated(false),

                        FileUpload::make('profile_photo')
                            ->label('Profile Photo')
                            ->image()
                            ->disk('public')
                            ->directory('merchants/profile-photos')
                            ->imagePreviewHeight(120)
                            ->getUploadedFileNameForStorageUsing(function ($file) {
                                $ext = $file->getClientOriginalExtension();

                                return 'profile-photo-'.now()->format('YmdHis').'.'.$ext;
                            }),
                    ])->columns(2),

                Section::make('Merchant Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('phone')
                            ->label('Phone No')
                            ->tel()
                            ->nullable()
                            ->helperText('Optional')
                            ->maxLength(50)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : null),

                        TextInput::make('website')
                            ->label('Website')
                            ->maxLength(255),

                        TextInput::make('address_line_1')
                            ->label('Address Line 1')
                            ->maxLength(255),

                        TextInput::make('address_line_2')
                            ->label('Address Line 2')
                            ->maxLength(255),

                        TextInput::make('city')
                            ->label('City')
                            ->maxLength(120),

                        TextInput::make('whatsapp_number')
                            ->label('WhatsApp No')
                            ->tel()
                            ->nullable()
                            ->helperText('Optional')
                            ->maxLength(50)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : null),

                        TextInput::make('ntn_number')
                            ->label('NTN No (Optional)')
                            ->maxLength(50),

                        //                        TextInput::make('cash_in_hand')
                        //                            ->label('Cash In Hand')
                        //                            ->prefix('PKR')
                        //                            ->numeric()
                        //                            ->default(0)
                        //                            ->minValue(0)
                        //                            ->step(0.01),
                        //
                        //                        TextInput::make('cash_in_bank')
                        //                            ->label('Cash In Bank')
                        //                            ->prefix('PKR')
                        //                            ->numeric()
                        //                            ->default(0)
                        //                            ->minValue(0)
                        //                            ->step(0.01),

                        Repeater::make('extra_fields')
                            ->label('Additional Fields')
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('label')
                                    ->label('Field')
                                    ->required()
                                    ->maxLength(100),
                                TextInput::make('value')
                                    ->label('Value')
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->default([])
                            ->addActionLabel('Add Field')
                            ->reorderable(false),
                    ]),
            ]);
    }

    /**
     * Save handler
     */
    public function save(): void
    {
        $merchant = Auth::guard('merchant')->user();

        abort_unless($merchant instanceof Merchant, 403);

        $merchant->update([
            'name' => $this->data['name'],
            'phone' => $this->data['phone'] ?? null,
            'address_line_1' => $this->data['address_line_1'] ?? null,
            'address_line_2' => $this->data['address_line_2'] ?? null,
            'city' => $this->data['city'] ?? null,
            'website' => $this->data['website'] ?? null,
            'whatsapp_number' => $this->data['whatsapp_number'] ?? null,
            'ntn_number' => $this->data['ntn_number'] ?? null,
            'extra_fields' => array_values($this->data['extra_fields'] ?? []),
        ]);

        $uploaded = collect($this->data['profile_photo'] ?? []);

        if ($uploaded->isNotEmpty()) {
            $file = $uploaded->first();

            if ($file instanceof TemporaryUploadedFile) {
                $path = $file->store('merchants/profile-photos', 'public');
                $merchant->profilePhoto()?->delete();

                $merchant->profilePhoto()->create([
                    'merchant_id' => $merchant->id,
                    'type' => AttachmentType::IMAGE,
                    'meta_type' => AttachmentMetaType::PROFILE_PHOTO,
                    'photo_url' => $path,
                ]);
            }
        } else {
            $merchant->profilePhoto()?->delete();
        }

        Notification::make()
            ->title('Profile updated successfully')
            ->success()
            ->send();

        $this->redirect(
            Filament::getCurrentPanel()->getUrl()
        );

    }
}
