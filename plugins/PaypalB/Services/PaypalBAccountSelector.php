<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Plugin\PaypalB\Models\PaypalBAccount;
use Plugin\PaypalB\Models\PaypalBRotationState;

class PaypalBAccountSelector
{
    public function __construct(private readonly int $cooldownMinutes)
    {
    }

    /**
     * 在锁住轮询指针和候选账号的同一事务中选择下一账号，避免并发请求重复命中同一账号。
     */
    public function select(string $currency, array $requiredMethods): PaypalBAccount
    {
        return DB::transaction(function () use ($currency, $requiredMethods): PaypalBAccount {
            $state = PaypalBRotationState::query()
                ->where('pool_key', 'automatic_api')
                ->lockForUpdate()
                ->firstOrFail();
            $accounts = PaypalBAccount::query()
                ->where('active', true)
                ->whereIn('account_type', PaypalBAccount::automaticApiAccountTypes())
                ->orderBy('priority')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $account = $this->chooseNextEligibleAccount($accounts->all(), $currency, $requiredMethods, $state->last_account_id);

            if (! $account) {
                throw ValidationException::withMessages(['payment' => '当前没有可用于该币种的 PayPal 收款账号。']);
            }

            $state->forceFill(['last_account_id' => $account->id])->saveOrFail();
            $account->forceFill(['last_selected_at' => now()])->saveOrFail();

            return $account;
        });
    }

    /**
     * 轮询顺序由优先级和账号 ID 组成环；禁用、冷却或能力不匹配的账号不会推进轮询指针。
     *
     * @param array<int, PaypalBAccount> $accounts
     */
    private function chooseNextEligibleAccount(array $accounts, string $currency, array $requiredMethods, ?int $lastAccountId): ?PaypalBAccount
    {
        $eligibleAccounts = array_values(array_filter($accounts, static function (PaypalBAccount $account) use ($currency, $requiredMethods): bool {
            return $account->usesApi()
                && (! $account->failure_cooldown_until || ! $account->failure_cooldown_until->isFuture())
                && $account->supportsCurrency($currency)
                && $account->supportsPaymentMethods($requiredMethods);
        }));

        if ($eligibleAccounts === []) {
            return null;
        }

        foreach ($eligibleAccounts as $index => $account) {
            if ((int) $account->id === $lastAccountId) {
                return $eligibleAccounts[($index + 1) % count($eligibleAccounts)];
            }
        }

        return $eligibleAccounts[0];
    }

    public function markSuccess(PaypalBAccount $account): void
    {
        $account->forceFill([
            'failure_count'          => 0,
            'failure_cooldown_until' => null,
            'last_success_at'        => now(),
            'last_error'             => null,
        ])->saveOrFail();
    }

    public function markFailure(PaypalBAccount $account, string $message): void
    {
        $account->forceFill([
            'failure_count'          => (int) $account->failure_count + 1,
            'failure_cooldown_until' => now()->addMinutes($this->cooldownMinutes),
            'last_error'             => mb_substr($message, 0, 2000),
        ])->saveOrFail();
    }

    public function recordRejectedRequest(PaypalBAccount $account, string $message): void
    {
        $account->forceFill([
            'last_error' => mb_substr($message, 0, 2000),
        ])->saveOrFail();
    }
}
