<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{

    // Schema has created_at only.
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'actor_email', 'action', 'detail'];
}
