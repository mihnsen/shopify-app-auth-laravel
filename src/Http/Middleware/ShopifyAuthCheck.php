<?php

namespace CultureKings\ShopifyAuth\Http\Middleware;

use CultureKings\ShopifyAuth\Models\ShopifyUser;
use CultureKings\ShopifyAuth\ShopifyApi;
use CultureKings\ShopifyAuth\Services\ShopifyAuthService;
use Closure;

class ShopifyAuthCheck
{
    protected $shopify;
    protected $shopifyAuthService;

    public function __construct(ShopifyApi $shopify, ShopifyAuthService $shopifyAuthService)
    {
        $this->shopify = $shopify;
        $this->shopifyAuthService = $shopifyAuthService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // If no shop key or session, return 401
        if (!$request->has('shop') && !$request->session()->has('shopifyapp')) {
            return abort(401, 'Shop key missing and no active session!');
        }

        // Set appName based on whatever is around
        if (!empty($request->get('appName'))) {
            $appName = $request->get('appName');
        } elseif (!empty($request->route('appName'))) {
            $appName = $request->route('appName');
        } else {
            $appName = $request->segment(2);
        }

        $reSetSession = false;

        // recheck session if set
        if ($request->session()->has('shopifyapp')) {
            $appSession = $request->session()->get('shopifyapp');
            $shopifyUser = $this->shopifyAuthService->getUserForApp($appSession['shop_url'], $appName);
            $shopifyApp = $shopifyUser->shopifyAppUsers()->latest()->first();

            if ($shopifyApp->access_token !== $appSession['access_token']) {
                $reSetSession = true;
            }
        }

        // If no session, get user & set one
        if (!$request->session()->has('shopifyapp') || $reSetSession) {

            // TODO redirect to login or handle url request to
            // Should be revised
            // http://shopego.com/app_name?shop=lego-dev.myshopify.com
            $shopUrl = $request->get('shop');
            $shopifyUser = $this->shopifyAuthService->getUserForApp($shopUrl, $appName);

            if (!$shopifyUser) {
                return abort(403, 'No shopify user found and no active sessions');
            }

            // FIXME Change method select last user
            // Should be revised
            //$shopifyApp = $shopifyUser->shopifyAppUsers->last();
            $shopifyApp = $shopifyUser->shopifyAppUsers()->latest()->first();
            $request->session()->put('shopifyapp', [
                'shop_url' => $shopUrl,
                'access_token' => $shopifyApp->access_token,
                'app_name' => $appName,
            ]);

            \Log::info('hmac', [
                'hmac' => $request->query('hmac'),
                'verify' => $this->shopify->verifyRequest($request->query->all(), $request->getQueryString()),
                'query-all' => $request->query->all(),
                'query-str' => $request->getQueryString(),
            ]);

            // set secret for hmac check
            $appConfig = config('shopify_apps.' . $appName);
            $this->shopify->setSecret($appConfig['secret']);

            // check hmac
            if (null !== $request->query('hmac') && !$this->shopify->verifyRequest($request->query->all(), $request->getQueryString())) {
                return response('Verification of HMAC Failed. Unauthorised.', 401);
            }
        }

        return $next($request);
    }
}
