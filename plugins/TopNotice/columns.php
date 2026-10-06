<?php

// 使用原生插件设置表单；插件状态字段由核心自动添加。
return [
    ['name' => 'content', 'label' => '公告内容（多语言，留空时使用默认内容）', 'type' => 'string-multiple', 'required' => false, 'rules' => 'nullable|array'],
    ['name' => 'content.*', 'rules' => 'nullable|string|max:5000', 'type' => 'validation', 'label' => '', 'required' => false],
    ['name' => 'fallback_content', 'label' => '默认公告内容', 'type' => 'textarea', 'required' => false, 'rules' => 'nullable|string|max:5000', 'description' => '当前语言未填写时使用。可只填写这里，让所有语言显示同一段话；内容为纯文本。'],
    ['name' => 'scope', 'label' => '显示范围', 'type' => 'select', 'required' => false, 'rules' => 'nullable|in:home,all', 'options' => [['value' => 'home', 'label' => '仅首页（默认）'], ['value' => 'all', 'label' => '全站公共头部']]],
    ['name' => 'overflow', 'label' => '超长展示方式', 'type' => 'select', 'required' => false, 'rules' => 'nullable|in:scroll,ellipsis', 'options' => [['value' => 'scroll', 'label' => '单行自动滚动（默认）'], ['value' => 'ellipsis', 'label' => '单行省略']]],
    ['name' => 'color', 'label' => '文字颜色', 'type' => 'string', 'required' => false, 'rules' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'description' => '六位颜色值，例如 #333333；留空默认 #333333。'],
    ['name' => 'background', 'label' => '公告背景色', 'type' => 'string', 'required' => false, 'rules' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'description' => '仅影响公告区域，例如 #FFF3CD；留空透明。'],
    ['name' => 'font_size', 'label' => '字号（px）', 'type' => 'string', 'required' => false, 'rules' => 'nullable|integer|min:10|max:20', 'description' => '10–20，留空默认 12。'],
    ['name' => 'link', 'label' => '点击跳转链接', 'type' => 'string', 'required' => false, 'rules' => ['nullable', 'url:http,https', 'max:2000'], 'description' => '选填完整的 http:// 或 https:// 地址，例如联系页面；留空不跳转。'],
    ['name' => 'starts_at', 'label' => '开始时间', 'type' => 'string', 'required' => false, 'rules' => 'nullable|date_format:Y-m-d H:i:s', 'description' => '格式：2026-10-01 00:00:00。使用网站时区（config/app.php 的 timezone），留空立即生效。'],
    ['name' => 'ends_at', 'label' => '结束时间', 'type' => 'string', 'required' => false, 'rules' => ['nullable', 'date_format:Y-m-d H:i:s', \Illuminate\Validation\Rule::when(fn ($input) => ! empty($input->starts_at), 'after:starts_at')], 'description' => '格式同上；留空不限结束时间。到达此时间隐藏，已打开页面需刷新；请晚于开始时间。'],
];
