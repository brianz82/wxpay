<?php
namespace spec\Homer\Payment\Wxpay;

use Carbon\Carbon;
use GuzzleHttp\RequestOptions;
use PhpSpec\ObjectBehavior;
use GuzzleHttp\ClientInterface;
use Prophecy\Argument;
use GuzzleHttp\Psr7\Response;

class ServiceSpec extends ObjectBehavior
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

        $this->beAnInstanceOf(\Homer\Payment\Wxpay\Service::class, [$config, $client]);
    }

    //=========================================
    //          place unified order
    //=========================================
    function it_places_order(ClientInterface $client)
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1470919875));
        $client->request('POST', 'https://api.mch.weixin.qq.com/pay/unifiedorder', Argument::that(function ($options) {
            $request = simplexml_load_string($options[RequestOptions::BODY]);

            assert_equals('201506072227000001', $request->out_trade_no);
            assert_equals('wx2421b1c4370ec43b', $request->appid);
            assert_equals('10000100', $request->mch_id);
            assert_equals('APP', $request->trade_type);
            assert_equals('1', $request->total_fee);
            assert_equals('报名费', $request->body);
            assert_equals('8.8.8.8', $request->spbill_create_ip);
            assert_not_empty($request->sign);

            return true;
        }))->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/place_unified_order_success.xml')));

        $params = $this->placeOrder('201506072227000001', 1, '报名费', '8.8.8.8')->getWrappedObject();

        assert_equals('wx2421b1c4370ec43b', $params['appid']);
        assert_equals('10000100', $params['partnerid']);
        assert_equals('wx201411101639507cbf6ffd8b0779950874', $params['prepayid']);
        assert_equals('Sign=WXPay', $params['package']);
        assert_equals('IITRi8Iabbblz1Jc', $params['noncestr']);
        assert_equals('1470919875', $params['timestamp']);
        assert_equals('911C47AE9657533E426108EDDBFB2C20', $params['sign']);
    }

    function it_rejects_duplicated_order_when_placing_order(ClientInterface $client)
    {
        $client->request('POST', 'https://api.mch.weixin.qq.com/pay/unifiedorder', Argument::that(function ($options) {
            $request = simplexml_load_string($options[RequestOptions::BODY]);

            assert_equals('201506072227000001', $request->out_trade_no);
            assert_equals('wx2421b1c4370ec43b', $request->appid);
            assert_equals('10000100', $request->mch_id);
            assert_equals('APP', $request->trade_type);
            assert_equals('1', $request->total_fee);
            assert_equals('报名费', $request->body);
            assert_equals('8.8.8.8', $request->spbill_create_ip);
            assert_not_empty($request->sign);

            return true;
        }))->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/place_unified_order_duplicated.xml')));

        $this->shouldThrow(new \Exception('订单重复(OUT_TRADE_NO_USED)'))
            ->duringPlaceOrder('201506072227000001', 1, '报名费', '8.8.8.8');

    }

    function it_rejects_on_bad_signature_when_placing_order(ClientInterface $client)
    {
        $client->request('POST', 'https://api.mch.weixin.qq.com/pay/unifiedorder', Argument::that(function ($options) {
            $request = simplexml_load_string($options[RequestOptions::BODY]);

            assert_equals('201506072227000001', $request->out_trade_no);
            assert_equals('wx2421b1c4370ec43b', $request->appid);
            assert_equals('10000100', $request->mch_id);
            assert_equals('APP', $request->trade_type);
            assert_equals('1', $request->total_fee);
            assert_equals('报名费', $request->body);
            assert_equals('8.8.8.8', $request->spbill_create_ip);
            assert_not_empty($request->sign);

            return true;
        }))->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/place_unified_order_bad_sign.xml')));

        $this->shouldThrow(new \Exception('签名错误(SIGNERROR)'))
            ->duringPlaceOrder('201506072227000001', 1, '报名费', '8.8.8.8');
    }

    function it_rejects_bad_response_when_placing_order(ClientInterface $client)
    {
        $client->request('POST', 'https://api.mch.weixin.qq.com/pay/unifiedorder', Argument::cetera())
            ->willReturn(new Response(500, [], 'bad response'));

        $this->shouldThrow(new \Exception('bad response from wxpay: bad response'))
            ->duringPlaceOrder('201506072227000001', 1, '报名费', '8.8.8.8');
    }

    //=========================================
    //          async notification
    //=========================================
    function it_works_on_trade_updated()
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
            assert_equals('20150707195723', $trade->paidAt);

            return true;
        })->shouldEqual('<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>');
    }

    function it_works_on_trade_updated_with_xml()
    {
        $this->tradeUpdated(file_get_contents(__DIR__ . '/data/trade_update_success.xml'), function($trade) {
            assert_equals('SSUROU160812000649R6A5LD', $trade->orderNo);
            assert_equals('SUCCESS', $trade->code);
            assert_equals('ohdWoxI_LhQYmr-FxsIBwyLB4HUE"', $trade->openId);
            assert_equals('APP', $trade->tradeType);
            assert_equals('CFT', $trade->bank);
            assert_equals('1', $trade->fee);
            assert_equals('4003772001201608121136066568', $trade->transId);
            assert_equals(null, $trade->attach);
            assert_equals('20160812000659', $trade->paidAt);

            return true;
        })->shouldEqual('<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>');
    }

    function it_works_on_trade_updated_with_qs()
    {
        $this->tradeUpdated(file_get_contents(__DIR__ . '/data/trade_update_success.txt'), function($trade) {
            assert_equals('201506072227000001', $trade->orderNo);
            assert_equals('SUCCESS', $trade->code);
            assert_equals('wxd930ea5d5a258f4f', $trade->openId);
            assert_equals('APP', $trade->tradeType);
            assert_equals('CMC', $trade->bank);
            assert_equals('1', $trade->fee);
            assert_equals('1217752501201407033233368018', $trade->transId);
            assert_equals('', $trade->attach);
            assert_equals('20150707195723', $trade->paidAt);

            return true;
        })->shouldEqual('<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>');
    }

    function it_detects_forged_notification_on_trade_update()
    {
        parse_str(file_get_contents(__DIR__ . '/data/trade_update_forged.txt'), $notification);

        $this->shouldThrow(new \Exception('Forged trade notification'))
            ->duringTradeUpdated($notification, function () {
                throw new \Exception('Should not reach here');
            });
    }

    function it_rejects_notification_with_bad_sign_on_trade_update()
    {
        parse_str(file_get_contents(__DIR__ . '/data/trade_update_bad_sign.txt'), $notification);

        $this->shouldThrow(new \Exception('Signature verification failed'))
            ->duringTradeUpdated($notification, function() {
                throw new \Exception('Should not reach here');
            });
    }

    //=========================================
    //         query order
    //=========================================
    function it_queries_order_successfully(ClientInterface $client)
    {
        $client->request('POST', 'https://api.mch.weixin.qq.com/pay/orderquery', Argument::that(function ($options) {
            $request = simplexml_load_string($options[RequestOptions::BODY]);

            assert_equals('201506072227000001', $request->out_trade_no);
            assert_not_empty($request->sign);

            return true;
        }))->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/query_order_success.xml')));

        $result = $this->queryOrder('201506072227000001')->getWrappedObject();

        assert_equals('SUCCESS', $result->code);
        assert_equals('SUCCESS', $result->tradeState);
        assert_equals('CCB_DEBIT', $result->bank);
        assert_equals('MICROPAY', $result->tradeType);
        assert_equals('oUpF8uN95-Ptaags6E_roPHg7AG0', $result->openId);
        assert_equals('1', $result->fee);
        assert_equals('CNY', $result->feeType);
        assert_equals('1008450740201411110005820873', $result->transId);
        assert_equals('1415757673', $result->orderNo);
        assert_equals('20141111170043', $result->paidAt);
    }

    function it_fails_on_bad_response_when_quering_order(ClientInterface $client)
    {
        $client->request('POST', 'https://api.mch.weixin.qq.com/pay/orderquery', Argument::that(function ($options) {
            $request = simplexml_load_string($options[RequestOptions::BODY]);

            assert_equals('201506072227000001', $request->out_trade_no);
            assert_not_empty($request->sign);

            return true;
        }))->willReturn(new Response(200, [], '<xml></xml>'));

        $this->shouldThrow(new \Exception('bad response from wxpay: <xml></xml>'))
            ->duringQueryOrder('201506072227000001');
    }

    //=========================================
    //         refund trade request
    //=========================================
    function it_requests_refund_successfully(ClientInterface $client)
    {
        $client->request('POST', 'https://api.mch.weixin.qq.com/secapi/pay/refund', Argument::that(function ($options) {
            $request = simplexml_load_string($options[RequestOptions::BODY]);
            assert_equals('201506072227000001', $request->out_trade_no);
            assert_not_empty($request->out_refund_no);
            assert_equals(10, $request->total_fee);
            assert_equals(1, $request->refund_fee);
            assert_equals('10000100', $request->op_user_id);
            assert_equals('wx2421b1c4370ec43b', $request->appid);
            assert_equals('10000100', $request->mch_id);
            assert_not_empty($request->nonce_str);
            assert_not_empty($request->sign);

            assert_not_empty($options[RequestOptions::CERT]);

            return true;
        }))->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/refund_trade_success.xml')));

        $result = $this->refundTrade('201506072227000001', 10, 1)->getWrappedObject();

        assert_equals('SUCCESS', $result->code);
        assert_not_empty($result->refundNo);
    }

    function it_failed_to_refund_on_bad_request(ClientInterface $client)
    {
        $client->request('POST', 'https://api.mch.weixin.qq.com/secapi/pay/refund', Argument::that(function ($options) {
            $request = simplexml_load_string($options[RequestOptions::BODY]);

            assert_equals('201506072227000001', $request->out_trade_no);
            assert_not_empty($request->out_refund_no);
            assert_equals(10, $request->total_fee);
            assert_equals(1, $request->refund_fee);
            assert_equals('10000100', $request->op_user_id);
            assert_equals('wx2421b1c4370ec43b', $request->appid);
            assert_equals('10000100', $request->mch_id);
            assert_not_empty($request->nonce_str);
            assert_not_empty($request->sign);

            assert_not_empty($options[RequestOptions::CERT]);

            return true;
        }))->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/refund_trade_failure.xml')));

        $result = $this->refundTrade('201506072227000001', 10, 1)->getWrappedObject();

        assert_equals('APPID_MCHID_NOT_MATCH', $result->code);
        assert_equals('appid和mch_id不匹配', $result->message);
        assert_not_empty($result->refundNo);
    }

    //=========================================
    //         refund query
    //=========================================
    function it_queries_refund_successfully(ClientInterface $client)
    {
        $client->request('POST', 'https://api.mch.weixin.qq.com/pay/refundquery', Argument::cetera())
            ->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/query_refund_success.xml')));

        $result = $this->queryRefund('20150607222756abcdef123456700001', '201506072227000001')->getWrappedObject();

        assert_equals('SUCCESS', $result->code);
        assert_equals('1008450740201411110005820873', $result->transId);
        assert_equals(1, count($result->items));

        $item = $result->items[0];
        assert_equals('2008450740201411110000174436', $item->id);
        assert_equals('1415701182', $item->refundNo);
        assert_equals(1, $item->fee);
        assert_equals('PROCESSING', $item->status);
    }
}
