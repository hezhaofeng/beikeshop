<?php

/**
 * Western Union插件配置字段。
 */

return [
    [
        'name'      => 'transfer_instruction',
        'label_key' => 'common.transfer_instruction',
        'type'      => 'textarea',
        'required'  => true,
        'rules'     => 'required|string|max:5000',
    ],
    [
        'name'      => 'discount_percentage',
        'label_key' => 'common.discount_percentage',
        'type'      => 'string',
        'required'  => false,
        'rules'     => 'nullable|numeric|min:0|max:100',
    ],
    [
        'name'      => 'receipt_required',
        'label_key' => 'common.receipt_required',
        'type'      => 'select',
        'options'   => [
            ['value' => '1', 'label_key' => 'common.yes'],
            ['value' => '0', 'label_key' => 'common.no'],
        ],
        'required' => true,
        'rules'    => 'required|boolean',
    ],
    [
        'name'      => 'quantity_restriction_enabled',
        'label'     => '按商品件数限制支付方式',
        'type'      => 'select',
        'options'   => [
            ['value' => '1', 'label' => '是'],
            ['value' => '0', 'label' => '否'],
        ],
        'required' => true,
        'rules'    => 'required|boolean',
    ],
    [
        'name'      => 'quantity_restriction_threshold',
        'label'     => 'Western Union件数门槛',
        'type'      => 'string',
        'required'  => false,
        'rules'     => 'nullable|required_if:quantity_restriction_enabled,1|integer|min:1|max:1000000',
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
