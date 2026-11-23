<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    // The migration creates the SINGULAR "audit_log" table — without this
    // Eloquent would look for "audit_logs" and every audited action
    // (staff edits, order status changes…) would throw a 500 after the
    // data change itself had already been persisted.
    protected $table = 'audit_log';

    // Schema has created_at only.
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'actor_email', 'action', 'detail'];
}
