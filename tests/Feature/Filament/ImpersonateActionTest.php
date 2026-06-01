<?php

declare(strict_types=1);

use Chuimi\FilamentImpersonation\Filament\Actions\ImpersonateAction;
use Chuimi\FilamentImpersonation\ImpersonationManager;
use Chuimi\FilamentImpersonation\Support\RedirectResolver;
use Chuimi\FilamentImpersonation\Tests\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Auth;

// ---------------------------------------------------------------------------
// 1. Class exists and make() returns a valid Action
// ---------------------------------------------------------------------------

it('ImpersonateAction::make() returns an instance of Action', function () {
    $action = ImpersonateAction::make();

    expect($action)
        ->toBeInstanceOf(ImpersonateAction::class)
        ->toBeInstanceOf(Action::class);
});

// ---------------------------------------------------------------------------
// 2. Default action name
// ---------------------------------------------------------------------------

it('ImpersonateAction has default name impersonate', function () {
    expect(ImpersonateAction::getDefaultName())->toBe('impersonate');
});

// ---------------------------------------------------------------------------
// 3. Schema has a TextInput named reason
// ---------------------------------------------------------------------------

it('action schema contains a TextInput named reason', function () {
    $action = ImpersonateAction::make();

    $schema = (new ReflectionProperty($action, 'schema'))->getValue($action);

    expect($schema)->toBeArray()->not->toBeEmpty();

    $reasonField = collect($schema)
        ->first(fn ($c) => $c instanceof TextInput && $c->getName() === 'reason');

    expect($reasonField)->toBeInstanceOf(TextInput::class);
});

// ---------------------------------------------------------------------------
// 4. min_length comes from config
// ---------------------------------------------------------------------------

it('reason field minLength reads from config at evaluation time', function () {
    config()->set('filament-impersonation.reason.min_length', 25);

    $action = ImpersonateAction::make();

    $schema = (new ReflectionProperty($action, 'schema'))->getValue($action);

    /** @var TextInput $reasonField */
    $reasonField = collect($schema)
        ->first(fn ($c) => $c instanceof TextInput && $c->getName() === 'reason');

    expect($reasonField->getMinLength())->toBe(25);
});

// ---------------------------------------------------------------------------
// 5. Visibility delegates to ImpersonationManager — hidden when disabled
// ---------------------------------------------------------------------------

it('action is hidden when package is disabled', function () {
    $operator = User::create(['name' => 'Op', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target', 'email' => 'target@example.com']);

    Auth::login($operator);
    config()->set('filament-impersonation.enabled', false);

    $action = ImpersonateAction::make()->record($target);

    expect($action->isVisible())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 6. Visibility delegates to ImpersonationManager — visible when authorized
// ---------------------------------------------------------------------------

it('action is visible when operator is authorized to impersonate target', function () {
    $operator = User::create(['name' => 'Op', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target', 'email' => 'target@example.com']);

    Auth::login($operator);
    config()->set('filament-impersonation.enabled', true);
    config()->set('filament-impersonation.can_impersonate', fn ($op, $t) => true);

    $action = ImpersonateAction::make()->record($target);

    expect($action->isVisible())->toBeTrue();
});

// ---------------------------------------------------------------------------
// 7. Translation keys — EN
// ---------------------------------------------------------------------------

it('en messages contains all required action translation keys', function () {
    $messages = require __DIR__ . '/../../../resources/lang/en/messages.php';

    expect($messages)
        ->toHaveKey('action_label')
        ->toHaveKey('reason_label')
        ->toHaveKey('reason_helper')
        ->toHaveKey('start_success')
        ->toHaveKey('start_failed')
        ->toHaveKey('start_failed_body');

    expect($messages['action_label'])->toBeString()->not->toBeEmpty();
    expect($messages['reason_label'])->toBeString()->not->toBeEmpty();
    expect($messages['start_success'])->toBeString()->not->toBeEmpty();
    expect($messages['start_failed'])->toBeString()->not->toBeEmpty();
    expect($messages['start_failed_body'])->toBeString()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 7b. Translation keys — ES
// ---------------------------------------------------------------------------

it('es messages contains all required action translation keys', function () {
    $messages = require __DIR__ . '/../../../resources/lang/es/messages.php';

    expect($messages)
        ->toHaveKey('action_label')
        ->toHaveKey('reason_label')
        ->toHaveKey('reason_helper')
        ->toHaveKey('start_success')
        ->toHaveKey('start_failed')
        ->toHaveKey('start_failed_body');

    expect($messages['action_label'])->toBeString()->not->toBeEmpty();
    expect($messages['reason_label'])->toBeString()->not->toBeEmpty();
    expect($messages['start_success'])->toBeString()->not->toBeEmpty();
    expect($messages['start_failed'])->toBeString()->not->toBeEmpty();
    expect($messages['start_failed_body'])->toBeString()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 8. Runtime execution — verifies action calls manager::start and
//    resolver::afterStart with the correct arguments.
//
//    Approach: bind container fakes before calling call().
//    Action::call() resolves $data and $record via Filament's named/typed
//    dependency injection (getData() / getRecord()), and shouldDeselect-
//    RecordsAfterCompletion() defaults to false so the finally block never
//    touches getLivewire(). The fake resolver returns null so neither branch
//    of the redirect check fires, keeping the test entirely Livewire-free.
// ---------------------------------------------------------------------------

it('action closure calls ImpersonationManager::start and RedirectResolver::afterStart on success', function () {
    $operator = User::create(['name' => 'Op', 'email' => 'op@example.com']);
    $target   = User::create(['name' => 'Target', 'email' => 'target@example.com']);

    Auth::login($operator);
    config()->set('filament-impersonation.enabled', true);
    config()->set('filament-impersonation.can_impersonate', fn ($op, $t) => true);

    $fakeManager = new class {
        public bool   $startCalled  = false;
        public mixed  $startTarget  = null;
        public string $startReason  = '';

        public function start(mixed $target, string $reason): void
        {
            $this->startCalled = true;
            $this->startTarget = $target;
            $this->startReason = $reason;
        }

        public function payload(): ?array { return ['test' => true]; }
        public function isImpersonating(): bool { return false; }
        public function canImpersonate(mixed $target): bool { return true; }
    };

    $fakeResolver = new class {
        public bool  $afterStartCalled       = false;
        public mixed $afterStartImpersonated = null;

        public function afterStart(
            ?array $payload = null,
            mixed $operator = null,
            mixed $impersonated = null,
        ): mixed {
            $this->afterStartCalled       = true;
            $this->afterStartImpersonated = $impersonated;
            // Returning null prevents both redirect branches from firing,
            // so the action exits cleanly without a Livewire component.
            return null;
        }
    };

    app()->instance(ImpersonationManager::class, $fakeManager);
    app()->instance(RedirectResolver::class, $fakeResolver);

    $reason = 'Sufficient reason for impersonation test';

    $action = ImpersonateAction::make()
        ->record($target)
        ->data(['reason' => $reason]);

    $action->call();

    expect($fakeManager->startCalled)->toBeTrue();
    expect($fakeManager->startTarget)->toBe($target);
    expect($fakeManager->startReason)->toBe($reason);

    expect($fakeResolver->afterStartCalled)->toBeTrue();
    expect($fakeResolver->afterStartImpersonated)->toBe($target);
});

// ---------------------------------------------------------------------------
// 9b. reason field is always required — reason.required removed from config
// ---------------------------------------------------------------------------

it('reason TextInput is always required regardless of config', function () {
    $action = ImpersonateAction::make();

    $schema = (new ReflectionProperty($action, 'schema'))->getValue($action);

    /** @var TextInput $reasonField */
    $reasonField = collect($schema)
        ->first(fn ($c) => $c instanceof TextInput && $c->getName() === 'reason');

    expect($reasonField)->toBeInstanceOf(TextInput::class);
    expect($reasonField->isRequired())->toBeTrue();
});

// ---------------------------------------------------------------------------
// 9. navigate: false is hardcoded in both redirect call sites
//
//    Filament 5's CanRedirect::redirect() delegates entirely to getLivewire(),
//    which requires a running Livewire component — unavailable in unit tests.
//    This test verifies at the source level that both redirect call sites pass
//    navigate: false, ensuring AuthenticateSession middleware re-runs with the
//    new authenticated user after every impersonation redirect.
// ---------------------------------------------------------------------------

it('action source contains navigate: false in both redirect call sites', function () {
    $source = file_get_contents(__DIR__ . '/../../../src/Filament/Actions/ImpersonateAction.php');

    expect(substr_count($source, 'navigate: false'))->toBeGreaterThanOrEqual(2);
});
