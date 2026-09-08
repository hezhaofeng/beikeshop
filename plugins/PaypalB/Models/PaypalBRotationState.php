<?php

namespace Plugin\PaypalB\Models;

use Illuminate\Database\Eloquent\Model;

class PaypalBRotationState extends Model
{
    protected $table = 'paypal_b_rotation_states';

    protected $fillable = [
        'pool_key',
        'last_account_id',
    ];

    protected $casts = [
        'last_account_id' => 'integer',
    ];
}
