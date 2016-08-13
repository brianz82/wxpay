<?php

namespace Homer\Payment\Wxpay;

use Carbon\Carbon;

class Service extends AbstractService
{
    /**
     * place a unified order and get payment argument
     * https://pay.weixin.qq.com/wiki/doc/api/jsapi.php?chapter=9_1
     *
     * @param string $orderNo       order number of merchant
     * @param integer $fee          payment total fee, measured in 'fen'
     * @param string $description   order's description
     * @param string $clientIp      ip of user who request this payment. format as: 8.8.8.8
     * @param integer $expireAfter  order will be expired after $expireAfter seconds.
     *                              a expired order can not be paid
     * @param string $detail        order's products detail
     * @param string $attach        a transparent value, wxpay will transfer back this value
     *                              without any changes
     *
     * @return array                fields defined in https://pay.weixin.qq.com/wiki/doc/api/app/app.php?chapter=9_12
     *
     * @throws \Exception
     */
    public function placeOrder($orderNo, $fee, $description, $clientIp,
                               $expireAfter = 3600, $detail = '', $attach = '')
    {
        $trade = parent::prepareTrade([
            'out_trade_no'      => $orderNo,
            'total_fee'         => $fee,
            'body'              => $description,
            'spbill_create_ip'  => $clientIp,
            'time_start'        => Carbon::now()->format('YmdHis'),
            'time_expire'       => $expireAfter ? Carbon::now()->addSeconds($expireAfter)->format('YmdHis') : '',
            'detail'            => $detail,
            'attach'            => $attach,
            'trade_type'        => 'APP',
        ]);

        if ($trade->code != 'SUCCESS') {
            throw new \Exception($trade->message . '(' . $trade->code . ')');
        }

        return $this->createTradeParams($trade);
    }

    /**
     * after prepay order is placed, create params for client to invoke client wxpay
     *
     * https://pay.weixin.qq.com/wiki/doc/api/app/app.php?chapter=9_12
     * @param object $order   the prepay order
     * @return array          params for client to invoke wxpay
     */
    private function createTradeParams($order)
    {
        $params = [
            'appid'     => $this->appId,
            'partnerid' => $this->merchantId,
            'prepayid'  => $order->prepayId,
            'package'   => 'Sign=WXPay',
            'noncestr'  => $order->nonceStr,
            'timestamp' => Carbon::now()->getTimestamp(),
        ];
        $params['sign'] = $this->signRequest($params);

        return $params;
    }

    /**
     * called when a trade's status changes (asynchronously)
     * https://pay.weixin.qq.com/wiki/doc/api/jsapi.php?chapter=9_7
     *
     * @param array|string $notification   notification (typically the whole $_POST) from Wxpay
     * @param callable $callback    callback will be passed, the parsed trade as its first param with following
     *                              attributes:
     *                              - code         'SUCCESS' for trade success.
     *                              - orderNo      the order# related to the trade
     *                              - openId       user unique identifier in merchant appid
     *                              - tradeType    trade type, such as APP, NATIVE, JSAPI etc.
     *                              - bank         bank type (e.g, such as CMC)
     *                                             https://pay.weixin.qq.com/wiki/doc/api/jsapi.php?chapter=4_2
     *                              - fee          total fee user paid
     *                              - transId      the order# in wx's system
     *                              - attach       the additional params that sent previously when placing this order
     *                              - paidAt       payment end time. format: yyyyMMddHHmmss
     *
     *                              on error, the second param will be passed in with following attributes:
     *                              - code         error code
     *                              - message      error message
     *
     * @return string               xml text to indicate the notification is successfully or failed to be handled
     *
     * @throws \Exception exception will thrown in case of invalid signature
     *                              or bad trade status
     */
    public function tradeUpdated($notification, callable $callback)
    {
        if (is_string($notification)) { // for string notification
            $notification = $this->morphNotification($notification);
        }

        if ($notification['return_code'] != 'SUCCESS') {
            // callback with an error
            try {
                call_user_func($callback, null, (object)[
                    'code'    => $notification['return_code'],
                    'message' => $notification['return_msg']
                ]);
            } catch (\Throwable $ex) {
                // ignore any exceptions
            }

            return self::respondFailureOnTradeUpdated();
        }

        $this->ensureResponseNotForged($notification);
        $trade = $this->parseTradeUpdateNotification($notification);

        try {
            if ('SUCCESS' == $trade->code && call_user_func($callback, $trade, null)) {
                return '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
            }
        } catch (\Throwable $ex) {
            // ignore any exceptions, and let the rest of the code to respond error
        }

        return self::respondFailureOnTradeUpdated();
    }

    private static function respondFailureOnTradeUpdated()
    {
        return '<xml><return_code><![CDATA[FAILURE]]></return_code><return_msg><![CDATA[NO]]></return_msg></xml>';
    }

    /**
     * morph the notification (as array)
     *
     * @param string $notification
     * @return array
     */
    private function morphNotification($notification)
    {
        // the notification can be xml string
        $morphed = $this->xml2array($notification);
        if ($morphed !== false) {
            return $morphed;
        }

        // or it can be http query string
        parse_str($notification, $morphed);
        return $morphed;
    }

    /**
     * convert xml to array
     *
     * @param string $xml
     * @return array|bool   false if it's not valid xml
     */
    private function xml2array($xml)
    {
        $xmlEl = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA|LIBXML_NOERROR|LIBXML_NOWARNING);
        if ($xmlEl === false) {
            return false;
        }

        return json_decode(json_encode($xmlEl), TRUE);
    }

    /**
     * find trade's related order#, status, etc. from given text
     *
     * @param array $notification       notification from Wxpay
     *
     * @return object   an object with the following fields set
     *                  - code         'SUCCESS' for trade success.
     *                  - orderNo      the order# related to the trade
     *                  - openId       user unique identifier in merchant appid
     *                  - tradeType    trade type, such as APP, NATIVE, JSAPI etc.
     *                  - bank         bank type (e.g, such as CMC)
     *                                 https://pay.weixin.qq.com/wiki/doc/api/jsapi.php?chapter=4_2
     *                  - fee          total fee user paid
     *                  - transId      the order# in wx's system
     *                  - attach       the additional params that sent previously when placing this order
     *                  - paidAt       payment end time. format: yyyyMMddHHmmss
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
        $trade->paidAt    = $notification['time_end'];

        return $trade;
    }
}
