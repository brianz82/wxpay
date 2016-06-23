<?php

namespace Homer\Payment\Wxpay;

use Carbon\Carbon;

class JsService extends AbstractService
{
    /**
     * place an unified order and get payment argument
     *
     * https://pay.weixin.qq.com/wiki/doc/api/jsapi.php?chapter=7_7&index=6
     *
     * @param string $openId        user openid in weixin
     * @param string $orderNo       order number of merchant
     * @param integer $fee          payment total fee, unit: fen
     * @param string $description   order's description
     * @param string $clientIp      ip of user who request a payment. format as: 8.8.8.8
     * @param integer $expireAfter  order will be expired after $expireAfter seconds.
     *                              a expired order can not be payed
     * @param string $detail        order's products detail
     * @param string $attach        a transparent value, wxpay will transfer back this value
     *                              without any changes
     *
     * @return \stdClass            return of this trade. with following fields set:
     *                              - responseCode  'SUCCESS' for trade success,
     *                                              'FAIL'  for trade failure,
     *                              - errCode       indicate the error code if responseCode equals 'FAIL'
     *                              - errMsg        description for errCode
     *                              the following fields are available if responseCode equals 'SUCCESS'
     *                              - tradeType     available values are: APP, NATIVE
     *                              - appid
     *                              - jsApiParams   params will be call in Weixin jsapi, properties as below
     *                                  - appId     appid
     *                                  - timeStamp
     *                                  - nonceStr
     *                                  - package   prepay_id=xxxx
     *                                  - signType  MD5
     *                                  - paySign
     *
     * @throw \Exception            exception will be throw if query request failed
     *                                  or field return_code missed in response
     */
    public function placeOrder($openId, $orderNo, $fee, $description, $clientIp,
                               $expireAfter = 3600,
                               $detail = '',
                               $attach = '')
    {
        $params = array_filter([
            'openid'            => $openId,
            'out_trade_no'      => $orderNo,
            'total_fee'         => $fee,
            'body'              => $description,
            'spbill_create_ip'  => $clientIp,
            'time_start'        => Carbon::now()->format('YmdHis'),
            'time_expire'       => $expireAfter ? Carbon::now()->addSeconds($expireAfter) : '',
            'detail'            => $detail,
            'attach'            => $attach,
            'trade_type'        => 'JSAPI',
        ]);

        $order = parent::prepareTrade($params);
        if ($order->code == 'SUCCESS') {
            $order->jsApiParams = $this->composeJsApiParams($order);
        }

        return $order;
    }

    /**
     * called wehn a trade's status changes in asynchronous manner.
     *
     * @param array $notification   notification (typically the whole $_POST) from Wxpay
     * @param callable $callback    callback will be passed, the parsed trade as its param
     *                                  callback receive a trade object, which properities lists as below:
     *                                  1). orderNo         -- the order# related to the trade
     *                                  2). openid          -- user unique identifier in merchant appid
     *                                  3). tradeType       -- trade type, such as APP, NATIVE, JSAPI etc.
     *                                  4). bankType        -- bank type. such as CMC
     *                                  5). fee             -- total fee user payed
     *                                  6). transactionId   -- Wxpay payment order#
     *                                  7). attach          -- transparent value, Wxpay not change it
     *                                  8). paymentTime    -- payment end time. format: yyyyMMddHHmmss
     *
     * @return string               SUCCESS or FAIL
     *
     * @throws \Exception exception will thrown in case of invalid signature
     *                              or bad trade status
     */
    public function tradeUpdated(array $notification, callable $callback)
    {
        if ($notification['return_code'] != 'SUCCESS') {
            return 'FAIL';
        }
        
        $this->ensureResponseNotForged($notification);
        $trade = $this->parseTradeUpdateNotification($notification);

        if ('SUCCESS' == $trade->code) {
            if (call_user_func($callback, $trade)) {
                return 'SUCCESS';
            } else {
                return 'FAIL';
            }
        }

        return 'FAIL';
    }


    /**
     * find trade's related order#, status, etc. from given text
     *
     * @param array $notification       notification from Wxpay
     *
     * @return object   an object with the following fields set
     *                  1). orderNo         -- the order# related to the trade
     *                  2). returnCode      -- 'SUCCESS' for communication success.
     *                  3). resultCode      -- 'SUCCESS' for trade success.
     *                  4). openid          -- user unique identifier in merchant appid
     *                  5). tradeType       -- trade type, such as APP, NATIVE, JSAPI etc.
     *                  6). bankType        -- bank type. such as CMC
     *                  7). fee             -- total fee user payed
     *                  8). transactionId   -- Wxpay payment order#
     *                  9). attach          -- transparent value, Wxpay not change it
     *                  10). paymentTime    -- payment end time. format: yyyyMMddHHmmss
     */
    private function parseTradeUpdateNotification(array $notification)
    {
        $trade = new \stdClass();
        $trade->orderNo   = $notification['out_trade_no'];
        $trade->code      = $notification['result_code'];
        $trade->openId    = $notification['openid'];
        $trade->tradeType = $notification['trade_type'];
        $trade->bank      = $notification['bank_type'];
        $trade->fee       = $notification['total_fee'];
        $trade->transId   = $notification['transaction_id'];
        $trade->attach    = array_get($notification, 'attach');
        $trade->payAt     = $notification['time_end'];

        return $trade;
    }

    private function composeJsApiParams($order)
    {
        $params = [
            'appId'     => $this->appId,
            'timeStamp' => Carbon::now()->getTimestamp(),
            'nonceStr'  => $this->genNonceStr(),
            'package'   => 'prepay_id=' . $order->prepayId,
            'signType'  => 'MD5',
        ];
        $params['paySign'] = $this->signRequest($params);

        return (object)$params;
    }
}
