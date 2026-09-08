<?php

namespace Plugin\PaypalB\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PaypalBAccount extends Model
{
    public const TYPE_BUSINESS_API = 'business_api';

    public const TYPE_PERSONAL_API = 'personal_api';

    /**
     * PayPal 个人卖家应用凭据与企业账号使用同一套 Orders API。
     * `personal_api` 保留用于兼容已保存的旧账号类型。
     */
    public const TYPE_PERSONAL_SELLER_API = 'personal_seller_api';

    public const TYPE_PERSONAL_MANUAL = 'personal_manual';

    protected $table = 'paypal_b_accounts';

    protected $attributes = [
        'wallet_enabled' => true,
        'card_enabled'   => false,
    ];

    protected $fillable = [
        'name',
        'account_type',
        'client_id',
        'client_secret',
        'webhook_id',
        'recipient_name',
        'recipient_email',
        'paypal_me_url',
        'supported_currencies',
        'wallet_enabled',
        'card_enabled',
        'priority',
        'active',
        'last_selected_at',
        'last_success_at',
        'failure_count',
        'failure_cooldown_until',
        'last_error',
    ];

    protected $hidden = ['client_secret'];

    protected $casts = [
        'active'                 => 'boolean',
        'wallet_enabled'         => 'boolean',
        'card_enabled'           => 'boolean',
        'priority'               => 'integer',
        'failure_count'          => 'integer',
        'last_selected_at'       => 'datetime',
        'last_success_at'        => 'datetime',
        'failure_cooldown_until' => 'datetime',
    ];

    public static function accountTypes(): array
    {
        return [
            self::TYPE_BUSINESS_API,
            self::TYPE_PERSONAL_SELLER_API,
            self::TYPE_PERSONAL_API,
            self::TYPE_PERSONAL_MANUAL,
        ];
    }

    /**
     * 可加入自动收款轮询池的账号类型。旧类型只为兼容历史记录保留，不能重新启用收款。
     */
    public static function automaticApiAccountTypes(): array
    {
        return [
            self::TYPE_BUSINESS_API,
            self::TYPE_PERSONAL_SELLER_API,
        ];
    }

    public static function isAutomaticApiAccountType(string $accountType): bool
    {
        return in_array($accountType, self::automaticApiAccountTypes(), true);
    }

    public function usesApi(): bool
    {
        return self::isAutomaticApiAccountType((string) $this->account_type);
    }

    public function supportsCurrency(string $currency): bool
    {
        $supported = trim((string) $this->supported_currencies);
        if ($supported === '' || $supported === '*') {
            return true;
        }

        $currencies = preg_split('/[\s,;]+/', strtoupper($supported), -1, PREG_SPLIT_NO_EMPTY);

        return in_array(strtoupper($currency), $currencies ?: [], true);
    }

    public function supportsPaymentMethods(array $methods): bool
    {
        foreach ($methods as $method) {
            if ($method === 'wallet' && ! $this->wallet_enabled) {
                return false;
            }
            if ($method === 'card' && (! $this->card_enabled || ! $this->usesApi())) {
                return false;
            }
        }

        return true;
    }

    /**
     * 账号密钥只以应用加密格式写入 B 站数据库，管理页不会回显。
     */
    public function setClientSecretAttribute(?string $value): void
    {
        $value = trim((string) $value);
        if ($value !== '') {
            $this->attributes['client_secret'] = Crypt::encryptString($value);
        }
    }

    public function decryptedClientSecret(): string
    {
        $value = (string) ($this->attributes['client_secret'] ?? '');
        if ($value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            throw new \RuntimeException('PayPal 账号密钥无法解密，请在后台重新保存该账号凭据。');
        }
    }
}
