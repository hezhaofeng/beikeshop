<?php

/**
 * 阶梯运费插件的配置字段和服务端基础校验规则。
 */

return [
    [
        'name'     => 'calculation_mode',
        'label'    => '计费方式',
        'type'     => 'select',
        'options'  => [
            ['value' => 'amount_free', 'label' => '订单金额满额免运费'],
            ['value' => 'tiered_amount', 'label' => '按订单金额阶梯运费'],
            ['value' => 'quantity_free', 'label' => 'n件商品（包含n）以上免运费'],
            ['value' => 'quantity_additional_fee', 'label' => '固定运费加商品件数运费'],
        ],
        'required' => true,
        'rules'    => 'required|in:amount_free,tiered_amount,quantity_free,quantity_additional_fee',
    ],
    [
        'name'     => 'standard_fee',
        'label'    => '基础运费',
        'type'     => 'string',
        'required' => true,
        'rules'    => 'required|numeric|min:0|max:99999999.99',
    ],
    [
        'name'     => 'amount_free_threshold',
        'label'    => '金额免运费门槛',
        'type'     => 'string',
        'required' => false,
        'rules'    => 'nullable|required_if:calculation_mode,amount_free|numeric|min:0|max:99999999.99',
    ],
    [
        'name'     => 'quantity_free_threshold',
        'label'    => '免运费商品件数（包含该件数）',
        'type'     => 'string',
        'required' => false,
        'rules'    => 'nullable|required_if:calculation_mode,quantity_free,quantity_additional_fee|integer|min:1|max:1000000',
    ],
    [
        'name'     => 'additional_item_fee',
        'label'    => '每增加一件商品运费',
        'type'     => 'string',
        'required' => false,
        'rules'    => 'nullable|required_if:calculation_mode,quantity_additional_fee|numeric|min:0|max:99999999.99',
    ],
    [
        'name'     => 'tiered_rules',
        'label'    => '金额阶梯规则',
        'type'     => 'textarea',
        'required' => false,
        'rules'    => 'nullable|required_if:calculation_mode,tiered_amount|array|min:1|max:100',
    ],
];
