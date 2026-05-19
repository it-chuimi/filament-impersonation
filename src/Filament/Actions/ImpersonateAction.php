<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Filament\Actions;

use Chuimi\FilamentImpersonation\Exceptions\CannotImpersonateSelfException;
use Chuimi\FilamentImpersonation\Exceptions\ImpersonationAlreadyActiveException;
use Chuimi\FilamentImpersonation\Exceptions\ImpersonationStartFailedException;
use Chuimi\FilamentImpersonation\Exceptions\PackageDisabledException;
use Chuimi\FilamentImpersonation\Exceptions\ProtectedUserCannotBeImpersonatedException;
use Chuimi\FilamentImpersonation\Exceptions\UnauthorizedImpersonationException;
use Chuimi\FilamentImpersonation\ImpersonationManager;
use Chuimi\FilamentImpersonation\Support\RedirectResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ImpersonateAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'impersonate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament-impersonation::messages.action_label'));
        $this->icon('heroicon-o-user-circle');
        $this->color('warning');

        $this->visible(function (Model $record): bool {
            if (! $record instanceof Authenticatable) {
                return false;
            }

            return app(ImpersonationManager::class)->canImpersonate($record);
        });

        $this->schema([
            TextInput::make('reason')
                ->label(__('filament-impersonation::messages.reason_label'))
                ->helperText(__('filament-impersonation::messages.reason_helper'))
                ->required()
                ->minLength(fn (): int => (int) config('filament-impersonation.reason.min_length', 10)),
        ]);

        $this->action(function (array $data, Model $record): void {
            if (! $record instanceof Authenticatable) {
                Notification::make()
                    ->title(__('filament-impersonation::messages.start_failed'))
                    ->danger()
                    ->send();

                return;
            }

            $manager  = app(ImpersonationManager::class);
            $resolver = app(RedirectResolver::class);
            $operator = Auth::user();
            $reason   = $data['reason'];

            try {
                $manager->start($record, $reason);
            } catch (
                PackageDisabledException
                | ImpersonationAlreadyActiveException
                | CannotImpersonateSelfException
                | ProtectedUserCannotBeImpersonatedException
                | UnauthorizedImpersonationException
                | ImpersonationStartFailedException
                | \InvalidArgumentException $e
            ) {
                Notification::make()
                    ->title(__('filament-impersonation::messages.start_failed'))
                    ->body(__('filament-impersonation::messages.start_failed_body'))
                    ->danger()
                    ->send();

                return;
            }

            Notification::make()
                ->title(__('filament-impersonation::messages.start_success'))
                ->success()
                ->send();

            $payload = $manager->payload();

            $url = $resolver->afterStart(
                payload: $payload,
                operator: $operator instanceof Authenticatable ? $operator : null,
                impersonated: $record,
            );

            // navigate: false forces a full HTTP redirect instead of Livewire SPA
            // navigation, ensuring middleware (including AuthenticateSession) re-runs
            // with the new authenticated user on the next request.
            if ($url instanceof RedirectResponse) {
                $this->redirect($url->getTargetUrl(), navigate: false);
            } elseif (is_string($url)) {
                $this->redirect($url, navigate: false);
            }
        });
    }
}
