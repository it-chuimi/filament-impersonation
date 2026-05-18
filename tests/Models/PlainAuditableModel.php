<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PlainAuditableModel extends Model
{
    use LogsActivity;

    protected $table    = 'auditable_models';
    protected $fillable = ['name'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable();
    }
}
