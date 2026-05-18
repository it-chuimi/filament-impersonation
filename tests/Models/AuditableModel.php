<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Tests\Models;

use Chuimi\FilamentImpersonation\Concerns\HasImpersonationActivityContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AuditableModel extends Model
{
    use LogsActivity;
    use HasImpersonationActivityContext;

    protected $table    = 'auditable_models';
    protected $fillable = ['name'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable();
    }
}
