<?php

namespace Plugin\AdTracking\Models;

use Illuminate\Database\Eloquent\Model;

class AdTrackingEvent extends Model
{
    protected $table = 'ad_tracking_events';

    protected $guarded = [];

    protected $casts = [
        'request'  => 'array',
        'response' => 'string',
        'sent_at'  => 'datetime',
    ];
}
