<?php

namespace Plugin\PaypalB\Models;

use Illuminate\Database\Eloquent\Model;

class PaypalBTransaction extends Model
{
    public const SOURCE_BRIDGE = 'bridge';

    public const SOURCE_LOCAL = 'local';

    protected $table = 'paypal_b_transactions';

    protected $fillable = [
        'transaction_id',
        'public_token',
        'account_id',
        'account_fingerprint',
        'reference',
        'order_number',
        'source',
        'amount',
        'currency',
        'buyer_locale',
        'callback_url',
        'mode',
        'status',
        'paypal_order_id',
        'paypal_capture_id',
        'shop_order_id',
        'approval_url',
        'provider_status',
        'order_snapshot_hash',
        'buyer_snapshot',
        'order_items',
        'paypal_order_items',
        'paypal_item_source',
        'order_totals',
        'shipping_address',
        'fulfillment_evidence',
        'fulfillment_updated_at',
        'buyer_email_hash',
        'buyer_ip_hash',
        'risk_fingerprint',
        'risk_flags',
        'paypal_client_metadata_id',
        'payer_email_hash',
        'payment_exception_status',
        'payment_exception_payload',
        'failure_retryable',
        'failure_class',
        'bridge_request',
        'provider_payload',
        'callback_payload',
        'callback_attempts',
        'callback_status',
        'callback_next_attempt_at',
        'callback_last_sent_at',
        'callback_last_error',
        'expires_at',
        'paid_at',
        'refunded_amount',
        'refund_status',
        'dispute_status',
        'dispute_payload',
    ];

    protected $casts = [
        'bridge_request'            => 'array',
        'buyer_snapshot'            => 'encrypted:array',
        'order_items'               => 'encrypted:array',
        'paypal_order_items'        => 'encrypted:array',
        'order_totals'              => 'array',
        'shipping_address'          => 'encrypted:array',
        'fulfillment_evidence'      => 'encrypted:array',
        'fulfillment_updated_at'    => 'datetime',
        'risk_flags'                => 'array',
        'payment_exception_payload' => 'encrypted:array',
        'failure_retryable'         => 'boolean',
        'provider_payload'          => 'encrypted:array',
        'callback_payload'          => 'array',
        'callback_attempts'         => 'integer',
        'callback_next_attempt_at'  => 'datetime',
        'callback_last_sent_at'     => 'datetime',
        'expires_at'                => 'datetime',
        'paid_at'                   => 'datetime',
        'dispute_payload'           => 'encrypted:array',
    ];

    public function account()
    {
        return $this->belongsTo(PaypalBAccount::class, 'account_id');
    }

    public function isLocal(): bool
    {
        return (string) $this->source === self::SOURCE_LOCAL;
    }

    public function refunds()
    {
        return $this->hasMany(PaypalBRefund::class, 'transaction_id');
    }
}
