<?php

/**
 * Zelle 插件配置字段。
 */

return [
    [
        'name'      => 'recipient_type',
        'label_key' => 'common.recipient_type',
        'type'      => 'select',
        'options'   => [
            ['value' => 'email', 'label_key' => 'common.recipient_email'],
            ['value' => 'mobile', 'label_key' => 'common.recipient_mobile'],
        ],
        'required' => true,
        'rules'    => 'required|in:email,mobile',
    ],
    [
        'name'      => 'recipient_identifier',
        'label_key' => 'common.recipient_identifier',
        'type'      => 'string',
        'required'  => true,
        'rules'     => 'required|string|max:255',
    ],
    [
        'name'      => 'recipient_name',
        'label_key' => 'common.recipient_name',
        'type'      => 'string',
        'required'  => true,
        'rules'     => 'required|string|max:255',
    ],
    [
        'name'      => 'payment_note',
        'label_key' => 'common.payment_note',
        'type'      => 'string',
        'required'  => false,
        'rules'     => 'nullable|string|max:500',
    ],
    [
        'name'      => 'status',
        'label_key' => 'common.status',
        'type'      => 'select',
        'options'   => [
            ['value' => '1', 'label_key' => 'common.enabled'],
            ['value' => '0', 'label_key' => 'common.disabled'],
        ],
        'required' => true,
        'rules'    => 'required|boolean',
    ],
];
