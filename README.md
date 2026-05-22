# Filament Impersonation

[![Tests](https://github.com/it-chuimi/filament-impersonation/actions/workflows/tests.yml/badge.svg)](https://github.com/it-chuimi/filament-impersonation/actions/workflows/tests.yml)

Controlled and audited user impersonation plugin for Laravel and Filament applications.

Allows an authorized operator to temporarily authenticate as another user while preserving a mandatory audit trail of the real operator.

## Requirements

- PHP >= 8.2
- Laravel >= 12
- Filament >= 5
- [spatie/laravel-activitylog](https://github.com/spatie/laravel-activitylog)
- [spatie/laravel-permission](https://github.com/spatie/laravel-permission) _(optional — required only for role/permission-based authorization)_

## Installation

```bash
composer require chuimi/filament-impersonation
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=filament-impersonation-config
```

## Usage

### Register the plugin in a Filament panel

```php
use Chuimi\FilamentImpersonation\Filament\ImpersonationPlugin;

$panel->plugin(ImpersonationPlugin::make());
```

Once registered, the impersonation banner is shown automatically at the bottom of the panel while any impersonation session is active.

### Add the action to a resource or page

```php
use Chuimi\FilamentImpersonation\Filament\Actions\ImpersonateAction;

->actions([
    ImpersonateAction::make(),
])
```

The action is hidden automatically when the current user cannot impersonate the record. It must be added manually to the resources or pages where impersonation should be available.

### Enrich activity logs with impersonation context (opt-in)

Apply the trait to any Eloquent model that uses `spatie/laravel-activitylog` and should include impersonation context in its activity entries:

```php
use Chuimi\FilamentImpersonation\Concerns\HasImpersonationActivityContext;
use Spatie\Activitylog\Traits\LogsActivity;

class SomeAuditableModel extends Model
{
    use LogsActivity;
    use HasImpersonationActivityContext;
}
```

This trait is opt-in and must be added manually per model. The package does not apply impersonation context globally to all activity logs.

## Security notes

- `impersonation.started` is mandatory and recorded before the user switch. If it cannot be written, impersonation does not start.
- Manual logout during impersonation does not restore the original operator.
- The stop route uses `POST` with CSRF protection via the `web` middleware stack. The banner renders the CSRF token automatically.

Full details in [docs/SECURITY.md](docs/SECURITY.md).

## Documentation

| Document | Description |
|---|---|
| [Integration guide](docs/INTEGRATION.md) | Installation, plugin registration, authorization, redirects, audit trait, multi-guard |
| [Security guide](docs/SECURITY.md) | Security model, audit events, CSRF, known risks, design decisions, audit queries for administrators |
| [Architecture](docs/ARCHITECTURE.md) | Design decisions, session payload, activity log events, internal flow |
| [Architecture map](docs/ARCHITECTURE_MAP.md) | Component overview and sequence diagrams |

## License

MIT — see [LICENSE](LICENSE).
