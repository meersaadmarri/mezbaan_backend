<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionalCampaign extends Model
{
    protected $fillable = [
        'title',
        'body',
        'audience',
        'scheduled_at',
        'sent_at',
        'status',
        'recipients_count',
        'success_count',
        'last_error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
