<?php

namespace Homer\Payment\Wxpay;


use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

abstract class AbstractService
{
    const UNIFIED_ORDER_URL = 'https://api.mch.weixin.qq.com/pay/unifiedorder';
    const ORDER_QUERY_URL   = 'https://api.mch.weixin.qq.com/pay/orderquery';
    const REFUND_URL        = 'https://api.mch.weixin.qq.com/secapi/pay/refund';
    const REFUND_QUERY_URL  = 'https://api.mch.weixin.qq.com/pay/refundquery';

    // default trade state
    const DEFAULT_TRADE_STATE    = 'UNKNOWN';
    // default refund status
    const DEFAULT_REFUND_STATUS  = 'UNKNOWN';


    /**
     * unique identification in weixin open platform or weixin public account.
     * @var string
     */
    protected $appId;

    /**
     * commercial tenant (商户) id
     *
     * @var string
     */
    protected $merchantId;

    /**
     * used for generating signature for transaction
     *
     * @var string
     */
    protected $key;

    /**
     * url to be notified when trade status changes
     *
     * @var string
     */
    protected $notifyUrl;

    /**
     * file path to merchant's certificate (商户证书), it's
     * - combined the cert and corresponding private key
     * - in PEM format
     * - named 'apiclient_cert.pem' typically
     *
     * @var string
     */
    private $cert;

    /**
     * http client
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected $client;

    public function __construct(array $config, ClientInterface $client = null)
    {
        $this->appId      = array_get($config, 'app_id');
        $this->merchantId = array_get($config, 'mch_id');

        $this->key        = array_get($config, 'key');
        $this->notifyUrl  = array_get($config, 'notify_url');
        $this->cert       = array_get($config, 'mch_cert');

        $this->client = $client ?: $this->createDefaultHttpClient();
    }

    /**
     * prepare the trade by placing an unified order
     *
     * @param array $params    params for the unified order
     *
     * @return \stdClass       the trade info. with following fields set:
     *                              - code        error code. null for no error
     *                              - message     description for error message
     *                         the following fields are available if it succeeded
     *                              - tradeType   available values are: JSAPI, APP and NATIVE
     *                              - prepayId    id of the prepare order created by wxpay
     *                              - nonceStr    nonce string
     *                              - qrLink      QR link, valid when tradeType is NATIVE, please see constant TRADE_TYPE_XXXX defined in this class
     */
    protected function prepareTrade(array $params)
    {
        $params['notify_url'] = $this->notifyUrl;
        $this->padCommonParams($params);
        $params['sign'] = $this->signRequest($params);

        return $this->postUnifiedOrderRequestAndParse($params);
    }

    /**
     * query a order and fetch order's detail
     * https://pay.weixin.qq.com/wiki/doc/api/jsapi.php?chapter=9_2
     *
     * @param string $orderNo        order# in merchant's system
     * @param string $transId  (optional) order# in wx's system
     *
     * @return \stdClass    return of order detail. with following fields set:
     *                      - code         indicate the error code
     *                      - message      description for errCode
     *                      the following fields are available if responseCode equals 'SUCCESS'
     *                      - deviceInfo   device# which invoke a payment
     *                      - openid       user unique identifier in current merchant
     *                      - subscribed  whether a user subscribe merchant's wx open account
     *                      - tradeType    trade type, such as APP, NATIVE ets.
     *                      - tradeState   available value as below:
     *                                      1) 'SUCCESS';         // 支付成功
     *                                      2) 'REFUND';          // 转入退款
     *                                      3) 'NOTPAY';          // 未支付
     *                                      4) 'CLOSED';          // 已关闭
     *                                      5) 'REVOKED';         // 已撤销
     *                                      6) 'USERPAYING';      // 用户支付中
     *                                      7) 'PAYERROR';        // 支付失败
     *                                      8) 'UNKNOWN';         // 未知错误
     *                      - tradeStateDesc description for tradeState
     *                      - bank         扣款银行, such as CMC
     *                      - fee          total payment fee, unit: fen
     *                      - feeType      payment fee type, such as CNY
     *                      - cashFee      total fee of cash payment order
     *                      - cashFeeType  cash payment fee type, such as CNY
     *                      - couponFee    代金券或立减优惠金额
     *                      - couponCount  代金券或立减优惠使用数量
     *                      - transId      payment order# in Wxpay system
     *                      - orderNo      order# in merchant system
     *                      - attach       transparent value supplied by user,
     *                                     Wxpay return this value without any changes
     *                      - paidAt       order pay time, format: yyyyMMddHHmmss

     * @throw \Exception    exception will be throw if query request failed
     *                          or field return_code missed in response
     */
    public function queryOrder($orderNo, $transId = null)
    {
        $params = array_filter([
            'transaction_id' => $transId,
            'out_trade_no'   => $orderNo,
        ]);
        $this->padCommonParams($params);
        $params['sign'] = $this->signRequest($params);

        return $this->postQueryOrderRequestAndParse($params);
    }

    /**
     * send a request for refunding money for a trade
     * https://pay.weixin.qq.com/wiki/doc/api/jsapi.php?chapter=9_5
     *
     * @params string $orderNo      order# in merchant's system
     * @params integer $fee         total money of order, unit: fen
     * @params integer $refundFee   refund money, unit: fee
     * @param string $transId       (optional) order# in wx's system
     *
     * @return \stdClass            result of this refund. with following fields set:
     *                              - code      response code. 'SUCCESS' on success
     *                              - message   additional message
     *                              - refundNo  refund#
     *
     */
    public function refundTrade($orderNo, $fee, $refundFee, $transId = null)
    {
        $params = array_filter([
            'transaction_id'    => $transId,
            'out_trade_no'      => $orderNo,
            'out_refund_no'     => $this->genRefundTradeNo(),
            'total_fee'         => $fee,
            'refund_fee'        => $refundFee,
            'refund_fee_type'   => 'CNY',
            'op_user_id'        => $this->merchantId,
        ]);
        $this->padCommonParams($params);
        $params['sign'] = $this->signRequest($params);

        return $this->postRefundRequestAndParse($params);
    }

    /**
     * query refund status after requesting a refund
     *
     * https://pay.weixin.qq.com/wiki/doc/api/jsapi.php?chapter=9_5
     *
     * @params string $refundNo     unique identifier to a trade application
     * @params string $orderNo      order# in merchant's system
     * @param string $transId       (optional) order# in wx's system
     *
     * @return \stdClass            result of this refund. with following fields set:
     *                              - code           'SUCCESS' for success
     *                              - message        error message
     *                              the following fields available if succeeds
     *                              - items       items of refund
     *                                 *) id        微信退款单号
     *                                 *) refundNo  商户退款单号
     *                                 *) fee       refund money, unit: fen (申请退款金额)
     *                                 *) status    available value as belows:
     *                                      1) SUCCESS     退款成功
     *                                      2) FAIL        退款失败
     *                                      3) PROCESSING  退款处理中
     *                                      4) NOTSURE     未确定，需商户原退款单号重新发起
     *                                      5) CHANGE      转入代发
     */
    public function queryRefund($refundNo, $orderNo, $transId = '')
    {
        $params = array_filter([
            'transaction_id' => $transId,
            'out_trade_no'   => $orderNo,
            'out_refund_no'  => $refundNo,
        ]);
        $this->padCommonParams($params);
        $params['sign'] = $this->signRequest($params);

        return $this->postQueryRefundRequestAndParse($params);
    }


    private function postQueryRefundRequestAndParse($params)
    {
        $xml = $this->postRequest(self::REFUND_QUERY_URL, $params);

        $result = new \stdClass();
        $result->message = $this->parseResponseForMessage($xml);
        if ((string) $xml->return_code == 'SUCCESS' && (string) $xml->result_code == 'SUCCESS') {
            $result->code = 'SUCCESS';
            $result->transId = (string) $xml->transaction_id;

            $refundCount = $xml->refund_count;
            $result->items = [];
            for ($i = 0; $i < $refundCount; $i++) {
                $result->items[] = $item = new \stdClass();
                $item->id = (string)$xml->{"refund_id_$i"};
                $item->refundNo = (string)$xml->{"out_refund_no_$i"};
                $item->fee = (string)$xml->{"refund_fee_$i"};
                $item->status = (string)$xml->{"refund_status_$i"};
            }
        } else {
            $result->code = $this->parseResponseForCode($xml);
        }

        return $result;
    }

    private function postRefundRequestAndParse($params)
    {
        $xml = $this->postRequest(self::REFUND_URL, $params, true); // NOTE: verify peer is enabled

        $result = new \stdClass();
        $result->refundNo = $params['out_refund_no'];
        $result->message = $this->parseResponseForMessage($xml);

        if ((string) $xml->return_code == 'SUCCESS' && (string) $xml->result_code == 'SUCCESS') {
            $result->code = 'SUCCESS';
        } else {
            $result->code = $this->parseResponseForCode($xml);
        }

        return $result;
    }


    private function genRefundTradeNo()
    {
        // constraint: 退款日期(8位当天日期)+流水号(24位)
        //
        // Our implementation:
        // - 14 chars     current date(8) and time (6)
        // - 13 chars     generate with uniqid()
        // - 5  chars     random number between [1, 99999]. if not 5 in length, left pad with '0'
        //
        return date('YmdHis') . uniqid() . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    private function postUnifiedOrderRequestAndParse($params)
    {
        $xml = $this->postRequest(self::UNIFIED_ORDER_URL, $params);

        $result = new \stdClass();
        $result->message = $this->parseResponseForMessage($xml);
        if ((string) $xml->return_code == 'SUCCESS' && (string) $xml->result_code == 'SUCCESS') {
            // verify the response signature
            $this->ensureResponseNotForged(xml_to_array($xml));

            $result->code = 'SUCCESS';
            $result->tradeType = (string) $xml->trade_type;
            $result->prepayId = (string) $xml->prepay_id;
            $result->nonceStr = (string) $xml->nonce_str;
            $result->qrLink = isset($xml->code_url) ? (string) $xml->code_url : null;
        } else {
            $result->code = $this->parseResponseForCode($xml);
        }

        return $result;
    }


    private function postQueryOrderRequestAndParse($params)
    {
        $xml = $this->postRequest(self::ORDER_QUERY_URL, $params);

        $result = new \stdClass();
        $result->message = $this->parseResponseForMessage($xml);
        if ((string) $xml->return_code == 'SUCCESS' && (string) $xml->result_code == 'SUCCESS') {
            $this->ensureResponseNotForged(xml_to_array($xml));

            $result->code = 'SUCCESS';

            $result->deviceInfo = isset($xml->device_info) ? (string) $xml->device_info : null;
            $result->openId = (string) $xml->openid;
            $result->subscribed = ((string) $xml->is_subscribe) == 'Y' ? true : false;
            $result->tradeType = (string) $xml->trade_type;
            $result->tradeState = (string) $xml->trade_state;
            $result->tradeStateDesc = (string) $xml->trade_state_desc;
            $result->bank = (string) $xml->bank_type;
            $result->fee = (int) $xml->total_fee;
            $result->feeType = isset($xml->fee_type) ? (string) $xml->fee_type : 'CNY';
            $result->cashFee = (int) $xml->cash_fee;
            $result->cashFeeType = isset($xml->cash_fee_type) ? (string) $xml->cash_fee_type : 'CNY';
            $result->couponFee = isset($xml->coupon_fee) ? (int) $xml->coupon_fee : null;
            $result->couponCount = isset($xml->coupon_count) ? (int) $xml->coupon_count : null;
            $result->transId = (string) $xml->transaction_id;
            $result->orderNo = (string) $xml->out_trade_no;
            $result->attach = isset($xml->attach) ? (string) $xml->attach : null;
            $result->paidAt = (string) $xml->time_end;      // yyyyMMddHHmmss
        } else {
            $result->code = $this->parseResponseForCode($xml);
        }

        return $result;
    }

    // helper method to parse response for code
    private function parseResponseForCode($xml, $default = 'FAIL')
    {
        if (isset($xml->err_code)) {
            return (string)$xml->err_code;
        }

        if (isset($xml->return_code)) {
            return (string)$xml->return_code;
        }

        return $default;
    }

    private function parseResponseForMessage($xml, $default = null)
    {
        if (isset($xml->return_msg)) {
            return (string) $xml->return_msg;
        }

        if (isset($xml->err_code_des)) {
            return (string) $xml->err_code_des;
        }

        return $default;
    }

    /**
     * post xml request
     *
     * @params string $url
     * @params array $params
     * @params bool $dualAuth   SSL dual auth - both server and client enables peer verification
     */
    private function postRequest($url, $params, $dualAuth = false)
    {
        $options = [
            RequestOptions::BODY => $this->xmlize($params)
        ];
        if ($dualAuth) {
            $options[RequestOptions::CERT] = $this->cert;
//            $options[RequestOptions::SSL_KEY];  // in case $this->cert does not contain private key, use this option
                                                  // to specify the file path to private key (in PEM format)
        }

        $response = $this->client->request('POST', $url, $options);
        if ($response->getStatusCode() != 200) {
            throw new \Exception('bad response from wxpay: ' . (string)$response->getBody());
        }

        $xml = simplexml_load_string((string) $response->getBody());
        if (empty($xml->return_code)) {
            throw new \Exception('bad response from wxpay: ' . (string)$response->getBody());
        }

        return $xml;
    }


    private function xmlize(array $data)
    {
        $xml = '<xml>';
        foreach ($data as $k => $v) {
            if (is_numeric($v)) {
                $xml .= sprintf('<%s>%s</%s>', $k, $v, $k);
            } else {
                $xml .= sprintf('<%s><![CDATA[%s]]></%s>', $k, $v, $k);
            }
        }
        $xml .= '</xml>';

        return $xml;
    }


    // generate nonce string
    protected function genNonceStr($length = 32)
    {
        return quick_random($length);
    }


    /**
     * Generate request params signature
     *
     * @param array $request
     *
     * @return string signed text
     */
    protected function signRequest(array $request)
    {
        // sort $data by key and convert to string
        ksort($request);
        reset($request);

        $raw = self::implode($request) . '&key=' . $this->key;

        // md5 and uppercase the result
        return strtoupper(md5($raw));
    }


    /**
     * ensure that response comes from Wxpay.
     *
     * @param array $response    the notification to verify
     * @param string $signParam  the sign key
     * @param bool $keepSign     false to eliminate sign in the response before comparing
     *
     * @throws \Exception
     */
    protected function ensureResponseNotForged(array $response,
                                               $signParam = 'sign',
                                               $keepSign = false)
    {
        $sign = isset($response[$signParam]) ? $response[$signParam] : null;
        if (empty($sign)) {
            throw new \Exception('Forged trade notification');
        }

        if (!$keepSign) {
            unset($response[$signParam]);
        }

        if ($sign != $this->signRequest($response)) {
            throw new \Exception('Signature verification failed');
        }
    }


    /*
     * implode associated array while keeping both its keys and values intact, and
     * this is the reason why http_build_query is not used
     */
    protected static function implode(array $assoc, $inGlue = '=', $outGlue = '&')
    {
        $imploded = '';
        foreach ($assoc as $name => $value) {
            $imploded .=  $name . $inGlue . $value . $outGlue;
        }

        return substr($imploded, 0, -strlen($outGlue));
    }


    /**
     * pad common params for request
     *
     * @param array $params
     * @return mixed
     */
    private function padCommonParams(&$params)
    {
        $params['appid'] = $this->appId;
        $params['mch_id'] = $this->merchantId;
        $params['nonce_str'] = $this->genNonceStr();
    }

    /*
     * create default http client
     *
     * @return Client
     */
    private function createDefaultHttpClient()
    {
        return new Client();
    }
}