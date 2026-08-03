<?php

namespace Plugin\CyberCloak\Services;

interface IpProviderInterface
{
    /**
     * 从供应商获取原始 IP 数据；该方法只在后台测试或同步任务中调用。
     *
     * @return IpProviderResult
     */
    public function fetch(array $config): IpProviderResult;
}
