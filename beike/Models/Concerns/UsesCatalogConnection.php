<?php

namespace Beike\Models\Concerns;

use Plugin\CyberCloak\Services\StoreContext;

trait UsesCatalogConnection
{
    /**
     * 仅在前台商品上下文激活期间切换商品库，后台和队列继续使用默认连接。
     * 显式设置连接的模型用于购物车跨模式读取，优先级高于当前请求上下文。
     */
    public function getConnectionName(): ?string
    {
        if ($this->connection !== null) {
            return parent::getConnectionName();
        }

        $context = app(StoreContext::class);
        if ($context->isActive()) {
            return $context->connectionName();
        }

        return parent::getConnectionName();
    }
}
