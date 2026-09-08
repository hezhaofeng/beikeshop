<?php

namespace Plugin\PaypalB\Models;

use Illuminate\Database\Eloquent\Model;

class PaypalBWebhookEvent extends Model
{
    protected $table = 'paypal_b_webhook_events';

    protected $fillable = [
        'account_id',
        'paypal_event_id',
        'event_type',
        'status',
        'transaction_id',
        'payload',
        'last_error',
        'processed_at',
    ];

    protected $casts = [
        'payload'      => 'encrypted:array',
        'processed_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(PaypalBAccount::class, 'account_id');
    }

    public function transaction()
    {
        return $this->belongsTo(PaypalBTransaction::class, 'transaction_id');
    }
}
