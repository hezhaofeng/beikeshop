<?php

namespace Plugin\PaypalB\Models;

use Illuminate\Database\Eloquent\Model;

class PaypalBRefund extends Model
{
    protected $table = 'paypal_b_refunds';

    protected $fillable = [
        'transaction_id',
        'request_id',
        'idempotency_key',
        'provider_refund_id',
        'provider_event_id',
        'amount',
        'currency',
        'source',
        'status',
        'provider_status',
        'note_to_payer',
        'provider_payload',
        'failure_retryable',
        'failure_class',
        'last_error',
        'processed_at',
        'refunded_at',
    ];

    protected $casts = [
        'provider_payload'  => 'encrypted:array',
        'failure_retryable' => 'boolean',
        'processed_at'      => 'datetime',
        'refunded_at'       => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(PaypalBTransaction::class, 'transaction_id');
    }
}
