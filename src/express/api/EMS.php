<?php

namespace iboxs\deliver\express\api;

use iboxs\deliver\express\lib\BaseExpress;
use iboxs\deliver\express\lib\ExpressApiImplement;
use iboxs\basic\Basic;
use iboxs\redis\Redis;

class EMS extends BaseExpress implements ExpressApiImplement {
    protected $protoUserCode = ''; // 协议用户编码
    protected $authCode = ''; // 授权码
    protected $signKey = ''; // 签名钥匙

    const RETCODE_SUCCESS = '00000'; // 返回参数

    public function setConfig($config) {
        // 用户有配置自己的协议用户编码等
        if (isset($config['protoUserCode'])) {
            $this->protoUserCode = $config['protoUserCode'];
            $this->authCode = $config['authCode'];
            $this->signKey = $config['signKey'];
        } else {
            $youzhengConfig = Config::GetConfig('exp_youzheng');
            $this->protoUserCode = $youzhengConfig['protoUserCode'];
            $this->authCode = $youzhengConfig['authCode'];
            $this->signKey = $youzhengConfig['signKey'];
        }
        if ($this->isDebug) {
            $this->protoUserCode = '';
            $this->authCode = '';
            $this->signKey = '';
        }
        $this->host = $this->isDebug ?
            "https://api.ems.com.cn/amp-prod-api/f/amp/api/test" : // 沙箱环境
            "https://api.ems.com.cn/amp-prod-api/f/amp/api/open"; // 正式环境
    }

    // 下单
    /**
     * @param int $order_id 订单ID
     * @return bool
     *
     */
    public function sendOrder($order_id,&$reson) { // 订单接入 接口代码：020003
        $order = LineOrder::query()->with(['shop_company','express_company'])->find($order_id);
        if (!$order) {
            $reson='订单不存在';
            return false;
        }

        $moreGoods = $this->getMoreGoods($this->key, $order);
        $expressInfo = $order['expressinfo'];
        $ecoUserId = $this->create_uuid();
        $data = [[
            'ecommerceUserId' => $ecoUserId, // 电商客户标识
            'logisticsOrderNo' => $order['no'], // 物流订单号（客户内部订单号）
            'createdTime' => date('Y-m-d H:i:s'),
            'contentsAttribute' => 3, // 内件性质，1：文件、3：物品
            'bizProductNo' => '10', // 业务产品分类 10：电商标快
            'paymentMode' => ExpressInfo::$EMSPayMode[$expressInfo['payMethod'] ?? 0],
            'remarks' => $order['remark'],
            'sender' => [
                'name' => $order['shipper_name'],
                'mobile' => $order['shipper_mobile'],
                'prov' => $order['shipper_province'],
                'city' => $order['shipper_city'],
                'county' => $order['shipper_area'],
                'address' => $order['shipper_address']
            ],
            'receiver' => [
                'name' => $order['consignee_name'],
                'mobile' => $order['consignee_mobile'],
                'prov' => $order['consignee_province'],
                'city' => $order['consignee_city'],
                'county' => $order['consignee_area'],
                'address' => $order['consignee_address']
            ],
            'cargos' => [[
                'cargoName' => $moreGoods,
                'cargoWeight' => intval($order['predictive_weight'])
            ]],
        ]];
        $result = $this->EMSPost('020003', $data);
        WriteLog('邮政快递下单回复：' . json_encode($data, 256) . '|' . json_encode($result, JSON_UNESCAPED_UNICODE));
        if ($result['retCode'] !== self::RETCODE_SUCCESS) {
            $reson=$result['retMsg'];
            LineOrder::query()->where('id',$order_id)->update([
                'express_return'=>serialize([
                    'errorMsg'=>$reson
                ]),
                'express_api_time'=>nowDate()
            ]);
            return false;
        }
        $results=json_decode($result['retBody'],true);
        $waybill=$results['waybillNo'];
        LineOrder::query()->where('id', $order_id)->update([
            'express_order' => serialize([
                'company' => 'youzheng',
                'order' => $waybill
            ]),
            'other_num' => $waybill,
            'express_return' => serialize($result),
            'express_api' => 2,
            'express_api_time' => nowDate()
        ]);
        RabbitMQ::add('express.exprintcard',[
            'key'=>$this->key,
            'no'=>$waybill
        ]);
        OrderRouteLog::insertLog('express', $order['id'], '已推送到邮政快递', null, null, 0, 0, 1);
        return true;
    }

    // 时效及价格查询
    /**
     * @param \App\Models\User $user 下单用户
     * @param Address $sendAddress 发货地址
     * @param Address $consignAddress 收货地址
     * @param double $weight 重量
     * @param double $volume 体积
     * @param string $reson 失败原因
     * @return false|double
     */
    public function GetPrice(Address $sendAddress, Address $consignAddress, $weight, $volume, &$reson,$local=true) { // 预估邮费 接口代码：050005
        return '0';
        $params = [
            'productCode' => '115104300000691', // 15位代码，电商标快物品
            'weight' => strval($weight),
            'senderInfo' => $sendAddress->province . $sendAddress->city . $sendAddress->area . $sendAddress->address ?? '',
            'receiveInfo' => $consignAddress->province . $consignAddress->city . $consignAddress->area . $consignAddress->address,
        ];

        $result = $this->EMSPost('050005', $params);
        WriteLog('邮政快递预估邮费回复：' . json_encode($params, 256) . '|' . json_encode($result, 256));
        if ($result['retCode'] !== self::RETCODE_SUCCESS) {
            $reson = $result['retBody'];
            $cacheInfo = [
                'reson' => $reson,
                'fee' => false
            ];
            $this->setCacheKey($this->key, 'expresspriceinfo:api:youzheng', $sendAddress, $consignAddress, $cacheInfo, 86400);
            return false;
        }
        $data = json_decode($result['retBody'], true);
        if (!isset($data['totalFee']) || $data['totalFee'] == 0) {
            $reson = '预估邮费失败';
            $cacheInfo = [
                'reson' => $reson,
                'fee' => false
            ];
            $this->setCacheKey($this->key, 'expresspriceinfo:api:youzheng', $sendAddress, $consignAddress, $cacheInfo, 86400);
            return false;
        }
        $fee = $data['totalFee'];
        $reson = '预估邮费获取成功';
        $cacheInfo = [
            'reson' => $reson,
            'fee' => $fee
        ];
        $this->setCacheKey($this->key, 'expresspriceinfo:api:youzheng', $sendAddress, $consignAddress, $cacheInfo, 86400);
        return $fee;
    }

    // 取消订单
    /**
     * @param \App\Models\LineOrder $order
     * @return bool
     */
    public function cancelOrder($order) { // 订单取消 接口代码：020006
        $mailNo=$order['other_num'];
        if($mailNo==''){
            return true;
        }
        $params = [
            'logisticsOrderNo' => $order['no'],
            'waybillNo' => $order['other_num'],
            'cancelReason' => '用户取消',
        ];
        $result = $this->EMSPost('020006', $params);
        WriteLog('邮政取消订单：'.$order['no']."|".json_encode($result,256));
        if ($result['retCode'] !== self::RETCODE_SUCCESS) {
            return false;
        }
        return true;
    }

    // 筛单
    /**
     * @param \App\Models\User $user 下单用户
     * @param Address $sendAddress 发货地址
     * @param Address $consignAddress 收货地址
     * @param string $reson 原因
     * @return boolean 筛单结果
     */
    public function filterOrder($user, Address $sendAddress, Address $consignAddress, &$reson) { // 揽收范围判断 接口代码：030005
        $json = [
            'address' => $sendAddress->province . $sendAddress->city . $sendAddress->area . $sendAddress->address,
            'prov' => $sendAddress->province,
            'city' => $sendAddress->city,
            'county' => $sendAddress->area,
            'bizProductNo' => '10', // 业务产品分类 1:特快专递 10：电商标快
            'type' => '2', // 1-散户实时 2-协议客户实时，第三方平台 3-协议客户定时
            'senderNo' => $this->protoUserCode
        ];
        $result = $this->EMSPost('030005', $json);

        if ($result['retCode'] !== self::RETCODE_SUCCESS) {
            $reson = '揽收范围判断接口失败';
            $cacheInfo = [
                'reson' => $reson,
                'result' => false
            ];
            $this->setCacheKey($this->key, 'filterorder:express:youzheng', $sendAddress, $consignAddress, $cacheInfo, 86400);
            return false;
        }

        $data = json_decode($result['retBody'], true);
        if ($data['status'] == '0') {
            $reson = '成功';
            $cacheInfo = [
                'reson' => $reson,
                'result' => true
            ];
            $this->setCacheKey($this->key, 'filterorder:express:youzheng', $sendAddress, $consignAddress, $cacheInfo, 3600);
            return true;
        } else if ($data['status'] == '1') {
            $reson = '超范围（业务要求，超范围不能下单）';
        } else if ($data['status'] == '2') {
            $reson = '解析参数失败';
        } else if ($data['stauts'] == '3') {
            $reson = '产品代码为空';
        } else if ($data['status'] == '4') {
            $reson = '不合法的产品代码';
        } else if ($data['status'] == '6') {
            $reson = '服务异常';
        } else if ($data['status'] == '7') {
            $reson = '读取参数失败';
        } else if ($data['status'] == '8') {
            $reson = '查询无结果';
        } else if ($data['status'] == '9') {
            $reson = '精度未达到赋值要求';
        } else if ($data['status'] == '10') {
            $reson = '未维护频次或者工作时间';
        }
        $cacheInfo = [
            'reson' => $reson,
            'result' => false
        ];
        $this->setCacheKey($this->key, 'filterorder:express:youzheng', $sendAddress, $consignAddress, $cacheInfo, 3600);
        return false;
    }

    // 查询路由信息
    /**
     * @param string $expressNo 快递单号
     * @param string $phone 收件人/发件人手机号
     * @return array|boolean 结果
     */
    public function queryRoute($expressNo, $phone) { // 运单轨迹信息获取 接口代码：040001
        if (Basic::isEmpty($expressNo)) {
            return [];
        }
        $expressResultTmp = Redis::basic()->get("yzexpressroute:{$expressNo}");
        if ($expressResultTmp !== null) {
            return $expressResultTmp;
        }
        $json = [
            'waybillNo' => $expressNo
        ];
        $result = $this->EMSPost('040001', $json);
        if ($result['retCode'] !== self::RETCODE_SUCCESS) {
            return [];
        }
        $data = json_decode($result['retBody'], true);
        $list = $data['responseItems'] ?? [];
        $expressResult = [];
        foreach ($list as $item) {
            $expressResult[] = [
                'waybillNo' => $item['waybillNo'],
                'opOrgCity' => $item['opOrgCity'],
                'opOrgCode' => $item['opOrgCode'],
                'opOrgName' => $item['opOrgName'],
                'opCode' => $item['opCode'],
                'opTime' => $item['opTime'],
                'express_order' => $expressNo,
                'opDesc' => $item['opDesc'],
                'operatorName' => $item['operatorName'],
                'phone' => '',
                'status' => '',
                'opName' => $item['opName'],
                'display' => ''
            ];
        }
        Redis::basic()->set('yzexpressroute:{$expressNo}', $expressResult, 1200);
        return $expressResult;
    }

    // 获取清单运费
    /**
     * @param \App\Models\LineOrder $order
     * @return false|double 运费信息，失败为false
     */
    public function getOrderPrice($order) { // 运费查询接口 接口代码：050001
        $params = [
            'logisticsOrderNo' => $order['no'],
            'waybillNo' => $order['other_num']
        ];
        $result = $this->EMSPost('050001', $params);
        WriteLog('邮政运费数据获取：'.json_encode($params,256)."|".json_encode($result,256));
        if ($result['retCode'] !== self::RETCODE_SUCCESS) {
            return false;
        }

        $expressinfo=$order['expressinfo'];
        $data = json_decode($result['retBody'], true);
        $fee = $data['postageTotal'];
        $weight = $data['realWeight'];
        $sendAddress = new Address($order['shipper_province'], $order['shipper_city'], $order['shipper_area'], $order['shipper_address'], $order['shipper_name'], $order['shipper_mobile']);
        $consignAddress = new Address($order['consignee_province'], $order['consignee_city'], $order['consignee_area'], $order['consignee_address'], $order['consignee_name'], $order['consignee_mobile']);
        $price = $this->getPriceLocal($order['express_company_id'],$expressinfo['expressGoods'],$order['shop_company_id'], $sendAddress, $consignAddress, $weight,$order['route_id']);
        if ($price != false) {
            $fee=$price;
        }
        $weightArr=$this->getWeightArr($order,$weight);
        $line=LineOrder::query()->where('id',$order['id'])->update([
            'weight'=>$weight,
            'volume'=>0,
            'receive_at'=>nowDate(),
            'price'=>$fee,
            'pricelog'=>serialize($data),
            'newprice'=>1,
            'fee_time'=>getFeeTime('expressorder'),
            'weight_arr'=>json_encode($weightArr,256)
        ]);
        LineOrder::query()->where('id',$order['id'])->where('status','<',2)->where('status','>=',0)->update([
            'status'=>5
        ]);
        WriteLog('邮政收到价格：'.$order['no']."|".getFeeTime('expressorder')."|".$fee);
        OrderBill::writeBill($order['id'],'line');
        ApiInfo::BillChange('line',$order['id'], $fee);
        OrderLog::InsertLog($order['id'],'收到计费：'.($fee),1,OrderLog::TYPE_PRICE,'line');
        return array($fee, $weight, $data);
    }

    // 云打印面单文件
    /**
     * @param string $expressNo 快递单号
     * @return false|string 文件地址，失败为false
     */
    public function getOrderPDF($expressNo) { // 面单查询 接口代码：010004
        if (Basic::isEmpty($expressNo)) {
            return false;
        }
        // $lineorder = LineOrder::query()->where('other_num', $expressNo)->first();
        // if (!$lineorder) {
        //     return false;
        // }
        $type = '129'; // 面单类型 129：总部模板
        $getURL = '1'; // 提供获取面单地址，可通过地址下载 pdf面单
        $data = [
            'waybillNo' => $expressNo,
            'type' => $type,
            'getURL' => $getURL
        ];
        $result = $this->EMSPost('010004', $data);
        if ($result['retCode'] !== self::RETCODE_SUCCESS) {
            return false;
        }

        $url = $result['retBody'];
        $path = public_path()."/storage/line-orders/".date('ymd')."/";
        if(!is_dir($path)){
            mkdir($path,0777,true);
        }
        $filename=$path.$expressNo.".pdf";
        Basic::downLoadFile($url,$filename);
        if(!file_exists($filename)){
            return false;
        }
        if(file_exists($filename)){
            $order=LineOrder::query()->where('other_num',$expressNo)->first();
            $imgfile2=public_path('storage').'/line-orders/'. substr($order['date'],2,6)."/";
            $result=pdf2png($filename,$imgfile2,210.4,212,5);
            $info=$result[0]??'';
            if($info!=''){
                rename($info,$imgfile2.$order['id'].".png");
            }
        }
        return $filename;
    }

    // 获取快递产品列表
    /**
     * @return array
     */
    public function getProduct() {
        $result=Redis::basic()->get('ems:productlist');
        if($result!=null){
            return $result;
        }
        $product=ExpressProduct::query()->where('express',$this->key)->orderBy('sort')->get();
        $result=[];
        foreach($product as $k=>$v){
            $result[$v['product']]=$v['name'];
        }
        Redis::basic()->set('ems:productlist',$result,1200);
        return $result;
    }

    private function EMSDecode($data){
        $authorization = $this->authCode;
        $sk = $this->signKey;
        $key=$authorization;
        $key = base64_decode($sk);
        $sm4 = new SM4();
        $a = $sm4->decrypt($key,str_replace('|$4|','',$data));
        return json_decode($a,true);
    }

    private function EMSPost($apiCode, $messageBody) {
        $senderNo = $this->protoUserCode;
        $authorization = $this->authCode;
        $timestamp = (new DateTime())->format('Y-m-d H:i:s');
        $messageBody = json_encode($messageBody, JSON_UNESCAPED_UNICODE);
        $sk = $this->signKey;
        $key=$authorization;
        $key = base64_decode($sk);
        $sm4 = new SM4();
        $a = $sm4->encrypt($key, $messageBody . $sk);
        $logitcsInterface = '|$4|' . $a;
        $post_data = array(
            'apiCode' => $apiCode, // 接口代码
            'senderNo' => $senderNo, // 客户代码
            'authorization' => $authorization, // 授权码（区分测试和生产）
            'timeStamp' => $timestamp, // 请求时间
            'logitcsInterface' => $logitcsInterface // 请求消息体
        );
        $result = $this->httpPost($this->host, $post_data);
        WriteLog('请求邮政接口：'.$this->host."|". json_encode($post_data,256)."|".json_encode($result,256));
        return $result;
    }

    /**
     * 路由信息被推处理
     */
    public function expRoute($data, $cache = false)
    {
        if ($cache) {
            return [
                'serialNo'=>$this->create_uuid(),
                'code' => self::RETCODE_SUCCESS,
                'codeMessage' => '成功',
                'senderNo'=>$this->protoUserCode
            ];
        }
        WriteLog('收到邮政订单路由：' . json_encode($data, 256));
        $data=$data['logitcsInterface']??[];
        $order=null;
        $item=$this->EMSDecode($data);
        $order = LineOrder::query()->where('other_num', $item['waybillNo'])->first();
        if (!$order) {
            return;
        }
        // 日志状态
        $logStatus = 41;
        // 订单状态
        $orderStatus = 0;
        // 邮件轨迹操作码
        $opCode = $item['opCode'];
        switch ($opCode) {
            case '305':
                $logStatus = 41;
                $orderStatus = 4;
                break;
            case '405':
            case '457':
                $logStatus = 41;
                $orderStatus = 5;
                break;
            case '516':
                $logStatus = 45;
                $orderStatus = 6;
                break;
            case '702':
                $logStatus = 42;
                $orderStatus = 6;
                break;
            case '704':
                $logStatus = 45;
                $orderStatus = 6;
                break;
            case '711':
            case '747':
            case '748':
                LineOrder::query()->where('no',$order['no'])->update([
                    'finish_at'=>$item['opTime']
                ]);
                $logStatus = 100;
                $orderStatus = 20;
                break;
            case 'O_011':
                $logStatus = -1;
                $orderStatus = 43;
                break;
        }

        if ($orderStatus != 0) {
            LineOrder::query()->where('no', $order['no'])->update(['status' => $orderStatus]);
        }
        OrderRouteLog::insertLog('express', $order['id'], $item['opDesc'], null, null, 0, 0, $logStatus, $item['opOrgCity'], strtotime($item['opTime']));
        
        if($order!=null){
            if($order->newprice==0){
                $this->getOrderPrice($order);
            }
        }
    }

    public function expState($data, $cache = false)
    {

    }

    public function expPrice($data, $cache = false)
    {

    }

    public function regRoute($line_order_id)
    {
        return true;
    }
}
