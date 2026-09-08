<?php

namespace Plugin\PaypalA\Models;

use Illuminate\Database\Eloquent\Model;

class PaypalATransaction extends Model
{
    protected $table = 'paypal_a_transactions';

    protected $fillable = [
        'order_id',
        'reference',
        'b_transaction_id',
        'amount',
        'currency',
        'payment_method',
        'status',
        'expires_at',
        'request_payload',
        'b_response',
        'callback_payload',
        'paid_at',
        'refunded_amount',
        'refund_status',
        'dispute_status',
        'fulfillment_status',
        'fulfillment_digest',
        'fulfillment_attempts',
        'fulfillment_next_attempt_at',
        'fulfillment_last_sent_at',
        'fulfillment_last_error',
    ];

    protected $casts = [
        'request_payload'             => 'array',
        'b_response'                  => 'array',
        'callback_payload'            => 'array',
        'expires_at'                  => 'datetime',
        'paid_at'                     => 'datetime',
        'refunded_amount'             => 'decimal:4',
        'fulfillment_next_attempt_at' => 'datetime',
        'fulfillment_last_sent_at'    => 'datetime',
    ];
}
