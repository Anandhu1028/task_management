<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class McpAuditLog extends Model
{
    use HasFactory;

    protected $table = 'mcp_audit_logs';

    protected $fillable = [
        'user_id',
        'company_id',
        'client_id',
        'tool_name',
        'action',
        'resource_type',
        'resource_id',
        'status',
        'error_message',
        'duration_ms',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
