<?php

namespace spec\Homer\Payment\Wxpay;

use Carbon\Carbon;
use GuzzleHttp\RequestOptions;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;

class JsServiceSpec extends ObjectBehavior
{
    function let(ClientInterface $client)
    {
        $config = [
            'app_id'     => 'wx2421b1c4370ec43b',
            'mch_id'     => '10000100',
            'key'        => 'c6d725f7ff5b80c0a95f',
            'mch_cert'   => '/path/to/merchant/cert',
            'notify_url' => 'http://localhost/trade.php',
        ];

        $this->beAnInstanceOf(\Homer\Payment\Wxpay\JsService::class, [$config, $client]);
    }

    //=========================================
    //          place unified order
    //=========================================
    public function it_places_order_successfully(ClientInterface $client)
    {
        Carbon::setTestNow(Carbon::createFromFormat('Y-m-d H:i:s', '2016-07-23 00:55:22'));
        $client->request('POST', 'https://api.mch.weixin.qq.com/pay/unifiedorder', Argument::that(function ($request) {
            $request = simplexml_load_string($request[RequestOptions::BODY]);

            if ($request->openid != 'oUpF8uMuAJO_M2pxb1Q9zNjWeS6o' ||
                $request->out_trade_no != '201506072227000001' ||
                $request->total_fee != '1' ||
                $request->time_start != '20160723005522' ||
                $request->time_expire != '20160723015522' ||
                $request->trade_type != 'JSAPI' ||
                $request->notify_url != 'http://localhost/trade.php' ||
                $request->appid != 'wx2421b1c4370ec43b' ||
                $request->mch_id != '10000100') {
                return false;
            }

            return isset($request->nonce_str) && isset($request->sign);
        }))->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/place_unified_order_jsapi_success.xml')));

        $result = $this->placeOrder('oUpF8uMuAJO_M2pxb1Q9zNjWeS6o', '201506072227000001', 1, '报名费', '8.8.8.8')
            ->getWrappedObject();

        assert_equals('SUCCESS', $result->code);
        assert_equals('JSAPI', $result->tradeType);
        assert_equals('wx201411101639507cbf6ffd8b0779950874', $result->prepayId);

        assert_not_empty(($jsApiParams = $result->jsApiParams));
        assert_equals('wx2421b1c4370ec43b', $jsApiParams->appId);
        assert_not_empty($jsApiParams->timeStamp);
        assert_equals('prepay_id=wx201411101639507cbf6ffd8b0779950874', $jsApiParams->package);
        assert_equals('MD5', $jsApiParams->signType);
        assert_not_empty($jsApiParams->paySign);
    }

    //=========================================
    //          payed async notification
    //=========================================
    function it_trade_update_successfully()
    {
        parse_str(file_get_contents(__DIR__ . '/data/trade_update_success.txt'), $notification);
        $this->tradeUpdated($notification, function($trade) {
            assert_equals('201506072227000001', $trade->orderNo);
            assert_equals('SUCCESS', $trade->code);
            assert_equals('wxd930ea5d5a258f4f', $trade->openId);
            assert_equals('APP', $trade->tradeType);
            assert_equals('CMC', $trade->bank);
            assert_equals('1', $trade->fee);
            assert_equals('1217752501201407033233368018', $trade->transId);
            assert_equals('', $trade->attach);
            assert_equals('20150707195723', $trade->payAt);
            
            return true;
        })->shouldEqual('SUCCESS');
    }
}
