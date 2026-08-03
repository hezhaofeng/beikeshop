<?php

namespace Beike\Admin\View\Components\Form;

use Illuminate\View\Component;

class Select extends Component
{
    public string $name;

    public mixed $value;

    public string $title;

    public array $options;

    public string $width;

    public string $key;

    public string $label;

    public string $class;

    public string $format;

    public bool $multiple;

    public function __construct(
        string $name,
        mixed $value,
        array $options,
        ?string $title = '',
        string $width = '',
        ?string $key = 'value',
        ?string $label = 'label',
        ?string $class = '',
        ?int $format = 1,
        bool $multiple = false,
    ) {
        $this->name    = $name;
        $this->title   = $title;
        $this->value   = $value;
        $this->options = $options;
        $this->width   = $width;
        $this->key     = $key;
        $this->label   = $label;
        $this->class   = $class ?: 'form-select me-3 wp-' . $width;
        $this->format  = $format;
        // 多选字段由组件统一追加 []，保证请求保存为 JSON 数组。
        $this->multiple = $multiple;
    }

    public function render()
    {
        return view('admin::components.form.select');
    }
}
