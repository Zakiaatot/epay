<?php
// +----------------------------------------------------------------------
// | ShopXO 国内领先企业级B2C免费开源电商系统
// +----------------------------------------------------------------------
// | Copyright (c) 2011~2099 http://shopxo.net All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( https://opensource.org/licenses/mit-license.php )
// +----------------------------------------------------------------------
// | Author: Zakiaatot
// +----------------------------------------------------------------------
namespace payment;

/**
 * EPayAlipay - 易支付-支付宝
 * @author  Zakiaatot
 * @version 1.0.0
 * @date    2024-09-03
 * @desc    description
 */
class EPayAlipay
{
    // 插件配置参数
    private $config;

    /**
     * 构造方法
     */
    public function __construct($params = [])
    {
        $this->config = $params;
    }

    /**
     * 配置信息
     */
    public function Config()
    {
        // 基础信息
        $base = [
            'name' => '易支付-支付宝',  // 插件名称
            'version' => '1.0.0',  // 插件版本
            'apply_version' => '不限',  // 适用系统版本描述
            'apply_terminal' => ['pc', 'h5'], // 适用终端 默认全部 ['pc', 'h5', 'app', 'alipay', 'weixin', 'baidu']
            'desc' => '易支付对接',  // 插件描述（支持html）
            'author' => 'Zakiaatot',  // 开发者
            'author_url' => 'https://github.com/zakiaatot',  // 开发者主页
        ];

        // 配置信息
        $element = [
            [
                'element' => 'input',
                'type' => 'text',
                'default' => '',
                'name' => 'url',
                'placeholder' => '对接API',
                'title' => '对接API',
                'is_required' => 0,
                'message' => '请填写对接API URL地址',
            ],
            [
                'element' => 'input',
                'type' => 'text',
                'default' => '',
                'name' => 'mch_no',
                'placeholder' => '商户号',
                'title' => '商户号',
                'is_required' => 0,
                'message' => '请填写商户号',
            ],
            [
                'element' => 'input',
                'type' => 'text',
                'default' => '',
                'name' => 'key',
                'placeholder' => '密钥',
                'title' => '密钥',
                'is_required' => 0,
                'message' => '请填写密钥',
            ],
        ];

        return [
            'base' => $base,
            'element' => $element,
        ];
    }

    /**
     * 支付入口
     */
    public function Pay($params = [])
    {
        // 参数
        if (empty($params)) {
            return DataReturn('参数不能为空', -1);
        }
        // 配置信息
        if (empty($this->config) || empty($this->config['mch_no']) || empty($this->config['url']) || empty($this->config['key'])) {
            return DataReturn('支付缺少配置', -1);
        }

        // if (!$this->client_type == 'h5' && !$this->client_type == 'pc') {
        // {
        //     return DataReturn('当前环境暂不支持易支付支付宝支付', -1);
        // } 

        // 支付参数
        $request_params = [
            'pid' => $this->config['mch_no'],
            'type' => 'alipay',
            'out_trade_no' => $params['order_no'],
            'notify_url' => $params['notify_url'],
            'return_url' => $params['call_back_url'],
            'name' => $params['name'],
            'money' => $params['total_price'],
            'clientip' => GetClientIP(),
            'sign_type' => 'MD5',
        ];

        // 构造参数
        $request_params = $this->buildRequestPara($request_params);

        // 执行请求
        $ret = $this->HttpRequest($this->config['url'] . 'mapi.php', $request_params);
        if ($ret['code'] != 1) {
            return DataReturn($ret['msg'], -1);
        }
        if (!empty($ret['payurl'])) {
            return DataReturn('success', 0, $ret['payurl']);
        }
        return DataReturn('返回支付url地址为空', -1);
    }

    /**
     * 支付回调处理
     */
    public function Respond($params = [])
    {
        if ($params['trade_status'] == 'TRADE_SUCCESS') {
            if (!$this->getSignVeryfy($params, $params['sign'])) {
                return DataReturn('验签失败', -1);
            }
        }

        if (!empty($params['out_trade_no'])) {
            return DataReturn('支付成功', 0, $this->ReturnData($params));
        }
        return DataReturn('处理异常错误', -1);
    }

    /**
     * 返回数据统一格式
     */
    private function ReturnData($data)
    {
        // 返回数据固定基础参数
        $data['trade_no'] = $data['trade_no'];  // 支付平台 - 订单号
        $data['buyer_user'] = $data['pid'];          // 支付平台 - 用户
        $data['out_trade_no'] = $data['out_trade_no'];    // 本系统发起支付的 - 订单号
        $data['subject'] = $data['name'];
        $data['pay_price'] = $data['money'];
        //$data['type']      = $data['alipay'];// 本系统发起支付的 - 总价
        return $data;
    }

    /**
     * 网络请求
     */
    private function HttpRequest($url, $data, $second = 30)
    {
        $ch = curl_init();
        $header = ['content-type: application/x-www-form-urlencoded;charset=UTF-8'];
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url . '?' . http_build_query($data),
            CURLOPT_HTTPHEADER => $header,
            CURLOPT_POST => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => $second,
            CURLOPT_HEADER => false
        ));
        $result = curl_exec($ch);

        //返回结果
        if ($result) {
            curl_close($ch);
            return json_decode($result, true);
        } else {
            $error = curl_errno($ch);
            curl_close($ch);
            return "curl出错，错误码:$error";
        }
    }

    public function getSignVeryfy($para_temp, $sign)
    {
        //除去待签名参数数组中的空值和签名参数
        $para_filter = $this->paraFilter($para_temp);

        //对待签名参数数组排序
        $para_sort = $this->argSort($para_filter);

        //把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
        $prestr = $this->createLinkstring($para_sort);

        $isSgin = false;
        $isSgin = $this->md5Verify($prestr, $sign, $this->config['key']);

        return $isSgin;
    }

    public function md5Verify($prestr, $sign, $key)
    {
        $prestr = $prestr . $key;
        $mysgin = md5($prestr);

        if ($mysgin == $sign) {
            return true;
        } else {
            return false;
        }
    }

    public function buildRequestPara($para_temp)
    {
        //除去待签名参数数组中的空值和签名参数
        $para_filter = $this->paraFilter($para_temp);

        //对待签名参数数组排序
        $para_sort = $this->argSort($para_filter);

        //生成签名结果
        $mysign = $this->buildRequestMysign($para_sort);

        //签名结果与签名方式加入请求提交参数组中
        $para_sort['sign'] = $mysign;

        return $para_sort;
    }

    public function buildRequestMysign($para_sort)
    {
        //把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
        $prestr = $this->createLinkstring($para_sort);

        $mysign = $this->md5Sign($prestr, $this->config['key']);

        return $mysign;
    }

    public function md5Sign($prestr, $key)
    {
        $prestr = $prestr . $key;
        return md5($prestr);
    }


    public function createLinkstring($para)
    {
        $arg = "";

        foreach ($para as $key => $val) {
            $arg .= $key . "=" . $val . "&";
        }
        //去掉最后一个&字符
        $arg = rtrim($arg, '&');

        return $arg;
    }

    public function paraFilter($para)
    {
        $para_filter = array();
        foreach ($para as $key => $val) {
            if ($key == "sign" || $key == "sign_type" || $val == "")
                continue;
            else
                $para_filter[$key] = $para[$key];
        }
        return $para_filter;
    }

    /**
     * 对数组排序
     * @param $para 排序前的数组
     * return 排序后的数组
     */
    public function argSort($para)
    {
        ksort($para);
        reset($para);
        return $para;
    }


}
?>