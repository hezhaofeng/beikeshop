<?php

namespace Plugin\AdTracking\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class ConsentController
{
    /**
     * 接收前端隐私同意状态，供服务端 Conversion API 判断是否允许发送事件。
     */
    public function update(Request $request): JsonResponse
    {
        $granted = $request->boolean('granted');
        $value   = $granted ? 'granted' : 'denied';

        session()->put('ad_tracking.consent', $value);

        return response()
            ->json(['status' => 'success', 'granted' => $granted])
            ->withCookie(new Cookie(
                'beike_ad_consent',
                $value,
                now()->addDays(180),
                '/',
                null,
                request()->isSecure(),
                true,
                false,
                Cookie::SAMESITE_LAX
            ));
    }
}
