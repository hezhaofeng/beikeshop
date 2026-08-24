<?php

/**
 * 保险费插件配置字段与服务端校验规则。
 */

return [
    [
        'name'            => 'insurance_fee_usd',
        'label_key'       => 'common.insurance_fee_usd',
        'description_key' => 'common.insurance_fee_usd_help',
        'type'            => 'string',
        'required'        => true,
        'rules'           => 'required|numeric|decimal:0,2|gt:0|max:99999999.99',
    ],
];
