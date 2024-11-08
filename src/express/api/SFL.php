<?php

namespace iboxs\deliver\express\api;


use iboxs\deliver\express\lib\BaseExpress;
use iboxs\deliver\express\lib\ExpressApiImplement;

class SFL extends BaseExpress implements ExpressApiImplement
{
    protected $partnerID = ''; //顾客编码
    protected $checkword = ''; //校验码

    public function setConfig($config)
    {
        if (isset($config['partnerID'])) { //用户有配置自己的顾客编码等
            $this->partnerID = $config['partnerID'];
            $this->checkword = $config['checkword'];
        } else {
            $shunfengConfig = Config::GetConfig('exp_shunfeng');
            $this->partnerID = $shunfengConfig['partnerID'];
            $this->checkword = $shunfengConfig['checkword'];
        }
        if ($this->isDebug) { //沙箱
            $this->partnerID = '';
            $this->checkword = '';
        }
        $this->host = $this->isDebug ?
            "https://sfapi-sbox.sf-express.com/std/service" : //沙箱
            "https://sfapi.sf-express.com/std/service"; //生产
    }

    /**
     * sendOrder 向冷运下发运输订单
     * @param mixed $order_id 订单ID
     * @param mixed $reson 订单返回信息
     * @return bool 成功或失败
     */
    public function sendOrder($order_id, &$reason)
    {
        // 查询给定订单ID的订单，并检查其是否存在
        $order = LineOrder::query()->with(["shop_company", "express_company"])->find($order_id);
        if (!$order) {
            $reason = "冷运订单不存在";
            return false;
        }

        // 获取月结卡号
        $moreGoods = $this->getMoreGoods($this->key, $order, $order['express_company_id']);
        $monthCard = $this->getMonthCard($order["express_company_id"], $order["shop_company_id"]);
        // 快递附加信息
        $expressInfo = $order["expressinfo"];
        // 下单时间格式转换
        $timestamp = strtotime($order["created_at"]);
        $orderTime = date("Y-m-d H:i:s", $timestamp);

        // 构造请求参数
        $requestData = [
            "erpOrder" => $order["no"], // 客户订单号
            "productCode" => $expressInfo['expressGoods'] ?? "SE003003", // 产品类型代码，冷运专线
            "monthlyAccount" => $monthCard, // 月结账户，到付、寄付现结不填
            "orderTime" => $orderTime, // 客户下单时间
            "paymentTypeCode" => ExpressInfo::$SFLYPayMode[$expressInfo['payMethod'] ?? 0], // 付款方式
            "remark" => $order["remark"], // 备注
            "shipperName" => $order["shipper_company"], // 发货公司
            "shipperContactName" => $order["shipper_name"], // 发货联系人姓名
            "shipperContactTel" => $order["shipper_mobile"], // 发货联系人电话
            "shipperProvinceName" => $order["shipper_province"], // 提货省份
            "shipperCityName" => $order["shipper_city"], // 提货城市
            "shipperDistrictName" => $order["shipper_area"], // 提货区县
            "shipperLocationName" => $order["shipper_address"], // 提货地点
            "consigneeContactName" => $order["consignee_name"], // 收货联系人姓名
            "consigneeContactTel" => $order["consignee_mobile"], // 收货联系人电话
            "consigneeProvinceName" => $order["consignee_province"], // 收货省份
            "consigneeCityName" => $order["consignee_city"], // 收货城市
            "consigneeDistrictName" => $order["consignee_area"], // 收货区县
            "consigneeLocationName" => $order["consignee_address"], // 收货地点
            "transportType" => "LAND", // 运输类型代码，默认值
            "temperatureLevelCode" => "2", // 温层代码:2 温层名称：0-10 温区：冷藏
            "orderItems" => [ // 商品明细
                "skuCode" => $moreGoods, // 商品类别
                "skuName" => $moreGoods, // 商品名称
                "quantity" => 1, // 件数，数量
                "grossWeight" => $order["predictive_weight"], // 毛重
                "volume" => $order["predictive_volume"] ?? 1, // 体积
            ],
        ];
        // dd($requestData);
        // 沙箱联调使用月结账号
        if ($this->isDebug) {
            $requestData["monthlyAccount"] = "7551234567";
        }

        // 接口服务代码
        $serviceCode = "SCS_RECE_CREATE_ORDER";
        // 向冷运下发运输订单
        $result = $this->SFColdCargoPost($serviceCode, $requestData);
        // 写入日志
        $log = "顺丰冷运专线下单回复：" . json_encode($requestData, 256) . "|" . json_encode($result, JSON_UNESCAPED_UNICODE);
        WriteLog($log);
        // 判断下单状态
        if (!$result["success"]) {
            if ($result["message"] == "ERP单号重复") {
                return true;
            }
            // 根据给定订单ID更新订单状态
            LineOrder::query()->where("id", $order_id)->update([
                "express_return" => serialize($result),
                "express_api_time" => nowDate(),
            ]);
            $reason = $result["message"];
            return false;
        }

        // SF生成订单号
        $sfOrderNo = $result["data"]["sfOrderNo"];
        $wayNo=$this->getWayNo($order['no'],$sfOrderNo);
        if($wayNo==null){
            $reason='冷运订单运单号生成失败';
            return false;
        }

        // 根据给定订单ID更新订单状态
        LineOrder::query()->where("id", $order_id)->update([
            "express_order" => serialize([
                "company" => "shunfengly",
                "order" => $wayNo,
            ]),
            'express_no'=>$sfOrderNo,
            "other_num" =>$wayNo,
            "express_return" => serialize($result["data"]),
            "express_api_time" => nowDate(),
        ]);
        // 写入队列
        RabbitMQ::add('express.exprintcard',[
            "key" => $this->key,
            "no" => $sfOrderNo,
        ]);

        // 写入订单路由日志
        OrderRouteLog::insertLog("express", $order["id"], "已推送到顺丰冷运", null, null, 0, 0, 1);

        return true;
    }

    public function getWayNo($no,$sfOrderNo){
        $serviceCode = "SCS_RECE_QUERY_WAYBILL_NO";
        $data=[
            'erpOrder'=>$no
        ];
        $sfResult = $this->SFColdCargoPost($serviceCode, $data);
        if($sfResult['code']!='0'){
            return false;
        }
        $wayNo=$sfResult['data']['waybillNo']??null;
        if($wayNo==null){
            return false;
        }
        return $wayNo;
    }

    /**
     * GetPrice 客户按指定“流向+产品(多)”，查询预估费用明细
     * @param \ExpressApi\Info\Address $sendAddress 发货消息
     * @param \ExpressApi\Info\Address $consignAddress 收货信息
     * @param mixed $weight 预估重量
     * @param mixed $volume 预估体积
     * @param mixed $reson 订单返回信息
     * @param mixed $local 
     * @return mixed
     */
    public function GetPrice(Address $sendAddress, Address $consignAddress, $weight, $volume, &$reson, $local = true)
    {
        if($local){
            // 获取昆明顺丰冷链的冷运专线产品预估费用
            $fee = $this->getPriceLocal(6530, "SE003003", 0, $sendAddress, $consignAddress, $weight,'0');
            if ($fee) {
                return $fee;
            }
        }
        // 获取缓存
        $cachedData = $this->getCacheKey($this->key, "express:priceinfo:sfly" . $weight, $sendAddress, $consignAddress, $key);
        if ($cachedData != null) {
            $reson = $cachedData["reson"];
            return $cachedData["fee"];
        }

        // 随机客户订单号
        $dateString = date('Ymd');
        $randomString = random_int(10000000, 99999999);
        $erpOrder = $dateString . $randomString;

        // 构造请求参数
        $requestData = [
            "erpOrder" => $erpOrder, // 客户订单号
            "productCode" => "SE003003", // 产品类型代码
            "orderTime" => nowDate(), // 客户下单时间
            "shipperProvinceName" => $sendAddress->province, // 提货省份
            "shipperCityName" => $sendAddress->city, // 提货城市
            "shipperDistrictName" => $sendAddress->area, // 提货县区
            "shipperLocationName" => $sendAddress->address, // 提货详细地址
            "consigneeProvinceName" => $consignAddress->province, // 送达省份
            "consigneeCityName" => $consignAddress->city, // 送达城市
            "consigneeDistrictName" => $consignAddress->area, // 送达县区
            "consigneeLocationName" => $consignAddress->address, // 送达详细地址
            "orderItems" => [
                "grossWeight" => $weight, // 毛重
                "volume" => $volume, // 体积
            ],
        ];

        // 接口服务代码
        $serviceCode = "SCS_RECE_CALC_TRANSPORT_FEE";
        // 客户按指定“流向+产品(多)”，查询预估费用明细
        $result = $this->SFColdCargoPost($serviceCode, $requestData);
        // 写入日志
        $log = "顺丰冷运专线价格数据请求：" . json_encode($requestData, 256) . "|" . json_encode($result, 256);
        WriteLog($log);
        // 判断查询预估费用明细状态
        if (!$result["success"]) {
            // 更新订单返回信息
            $reson = "请求失败【冷运专线价格数据请求失败】";
            // 设置缓存
            $cacheInfo = [
                "fee" => false,
                "reson" => $reson,
            ];
            $this->setCacheKey($this->key, "express:priceinfo:sfly" . $weight, $sendAddress, $consignAddress, $cacheInfo, 86400);
            return false;
        }
        if(!isset($result["data"]["SE003003"])){
            return 0;
        }
        // 返回费用详细信息
        $list = $result["data"]["SE003003"]["model"]??[];
        foreach($list as $value) {
            if (!isset($value["totalAmount"]) || $value["totalAmount"] == null) {
                continue;
            }

            $fee = $value["totalAmount"];
            $reson = "获取成功";
            $cacheInfo = [
                "fee" => $fee,
                "reson" => $reson,
            ];
            $this->setCacheKey($this->key, "express:priceinfo:sfly" . $weight, $sendAddress, $consignAddress, $cacheInfo, 86400);
            return $fee;
        }

        $reson = "顺丰冷运专线价格获取失败，请稍后重试";
        $cacheInfo = [
            "fee" => 0,
            "reson" => $reson,
        ];
        $this->setCacheKey($this->key, "express:priceinfo:sfly" . $weight, $sendAddress, $consignAddress, $cacheInfo, 86400);
        return false;
    }

    /**
     * cancelOrder 取消已下发顺丰的订单
     * @param mixed $order 订单
     * @return bool 成功或失败
     */
    public function cancelOrder($order)
    {
        // 判断快递单号是否为空
        if ($order["other_num"] == "") {
            return true;
        }
        // 构造请求参数
        $requestData = [
            "erpOrder" => $order["no"], // 运输订单号
        ];

        // 接口服务代码
        $serviceCode = "SCS_RECE_CANCEL_ORDER";
        // 取消已下发顺丰的订单
        $result = $this->SFColdCargoPost($serviceCode, $requestData);
        // 写入日志
        writeInfoLog("顺丰冷运取消订单: ", $requestData, $result);
        // 判断取消订单状态
        return $result['code']=='0';
    }

    /**
     * filterOrder 查询顺丰冷运可收派温层、网点营业时间（筛单）
     * @param mixed $user
     * @param \ExpressApi\Info\Address $sendAddress
     * @param \ExpressApi\Info\Address $consignAddress
     * @param mixed $reson
     * @return bool
     */
    public function filterOrder($user, Address $sendAddress, Address $consignAddress, &$reson)
    {
        return true; //无法排查，待与顺丰交流后确定原因，本接口暂时无法上线
        // 构造请求参数
        $requestData = [
            "productCode" => "SE003003", // 产品代码，冷运专线
            "senderProvinceName" => $sendAddress->province, // 始发省
            "senderCityName" => $sendAddress->city, // 始发市
            "senderCountryName" => $sendAddress->area, // 始发区县
            "senderCityAreaNumber" => Map::getCityNum($sendAddress->city).'', // 始发城市编码
            "senderAddress" => $sendAddress->address, // 始发详细地址
            "receiverProvinceName" => $consignAddress->province, // 目的省
            "receiverCityName" => $consignAddress->city, // 目的市
            "receiverCountryName" => $consignAddress->area, // 目的区县
            "receiverCityAreaNumber" => Map::getCityNum($consignAddress->city).'', // 目的城市编码
            "receiverAddress" => $consignAddress->address, // 目的详细地址
        ];
        // 接口服务代码
        $serviceCode = "SCS_RECE_CHECK_TRANSPORT_FLOW";
        // 客户按指定“流向+产品”，查询顺丰冷运可收派温层、网点营业时间
        $result = $this->SFColdCargoPost($serviceCode, $requestData);
        // 写入日志
        $log = "顺丰冷运专线筛单：" . json_encode($requestData, 256) . "|" . json_encode($result, 256);
        WriteLog($log);
        // 判断筛单状态
        if (!$result["success"]) {
            // 更新订单返回信息
            $reson = "请求冷运专线筛单接口失败【筛单失败】";
            // 设置缓存
            $cacheInfo = [
                "reason" => $reson,
                "result" => false,
            ];
            $this->setCacheKey($this->key, 'fiterorder:result:shunfengly', $sendAddress, $consignAddress, $cacheInfo, 3600);
            return true;
        }

        $data = $result["data"];
        $senderInfo = $data["senderInfo"];
        // 根据计件服务网点信息判断是否可收派，更新订单返回信息并设置缓存
        if ($senderInfo) {
            $reson = "可以收派";
            $cacheInfo = [
                "reason" => $reson,
                "result" => true,
            ];
            $this->setCacheKey($this->key, 'fiterorder:result:shunfengly', $sendAddress, $consignAddress, $cacheInfo, 3600);
            return true;
        }
        $reson = "顺丰冷运专线反馈不可以收派，请联系顺丰检查地址是否异常";
        $cacheInfo = [
            "reason" => $reson,
            "result" => false,
        ];
        $this->setCacheKey($this->key, 'fiterorder:result:shunfengly', $sendAddress, $consignAddress, $cacheInfo, 3600);
        return false;
    }

    /**
     * queryRoute 查询冷运运单路由信息
     * @param mixed $expressNo 运单号
     * @param mixed $phone 手机号
     * @return array
     */
    public function queryRoute($expressNo, $phone)
    {
        // 判断运单号是否存在
        if (Basic::isEmpty($expressNo)) {
            return [];
        }

        // 判断是否存在缓存数据，有则返回
        $expressCachedData = Redis::basic()->get("sflyexpressroute:" . $expressNo);
        if ($expressCachedData) {
            return $expressCachedData;
        }

        // 构造请求参数
        $requestData = [
            "waybillNo" => $expressNo, // 运单号
        ];

        // 接口服务代码
        $serviceCode = "SCS_RECE_QUERY_ROUTE";
        // 查询冷运运单路由信息
        $result = $this->SFColdCargoPost($serviceCode, $requestData);
        // dd($result);
        // 判断查询路由信息状态
        if (!$result || $result['code']!='0') {
            return [];
        }

        // 遍历路由信息
        $routeInfo = [];
        $list = $result["data"];
        foreach($list as $iteminfo){
            $statusMap=OrderRouteLog::$statusMap;
            $statusMap=array_flip($statusMap);
            $opName=$this->opName($iteminfo['opCode']);
            if(!isset($statusMap[$opName])){
                WriteLog("收到顺丰路由无法识别操作码：".json_encode($iteminfo,256),'error');
            }
            $status=$statusMap[$opName]??41;
            $routeInfo[]=[
                'city'=>$iteminfo['distName'],
                'city_code'=>Map::GetCityCode2($iteminfo['distName']),
                'time'=>$iteminfo['barScanTm'],
                'express_order'=>$expressNo,
                'content'=>$iteminfo['owsRemark'],
                'contact'=>'',
                'phone'=>'',
                'opname'=>$opName,
                'display'=>'',
                'status'=> $status
            ];
        }
        // 写入缓存
        Redis::basic()->set("sflyexpressroute:" . $expressNo, $routeInfo, 1200);
        // 返回路由信息
        return $routeInfo;
    }

    public function getOrderPrice($order)
    {
        $json=[
            'erpOrder'=>$order['no']
        ];
        // 接口服务代码
        $serviceCode = "SCS_RECE_QUERY_ORDER_INFO";
        // 查询冷运运单路由信息
        $result = $this->SFColdCargoPost($serviceCode, $json);
        WriteLog('获取顺丰冷运清单运费：'.json_encode($result));
        if($result==false){
            return false;
        }
        if($result['code']!='0'){
            return false;
        }
        $data=$result['data'];
        $weight=$data['order']['meterageWeight'];
        $expressinfo=$order['expressinfo'];
        $sendAddress=new Address($order['shipper_province'],$order['shipper_city'],$order['shipper_area'],$order['shipper_address'],$order['shipper_name'],$order['shipper_mobile']);
        $consignAddress=new Address($order['consignee_province'],$order['consignee_city'],$order['consignee_area'],$order['consignee_address'],$order['consignee_name'],$order['consignee_mobile']);
        $price=$this->getPriceLocal($order['express_company_id'],$expressinfo['expressGoods'],$order['shop_company_id'],$sendAddress,$consignAddress,$weight,$order['route_id']);
        if($price==false){
            return false;
        }
        LineOrder::query()->where('id',$order['id'])->where('status','<',2)->where('status','>=',0)->update([
            'status'=>5
        ]);
        OrderLog::InsertLog($order['id'],'收到计费：'.($price),1,OrderLog::TYPE_PRICE,'line');
        return array($price,$weight,$result);
    }

    /**
     * getOrderPDF 获取订单面单PDF
     * @param mixed $expressNo 运单号
     * @return mixed
     */
    public function getOrderPDF($expressNo)
    {
        // 面单模板编码
        $templateCode = "fm_150_standard_HYBKJmHF79QJ";
        // 更具运单号查询一条干线订单
        $lineOrder = LineOrder::query()->where("other_num", $expressNo)->first();
        // 面单文件
        $pdf = public_path("storage") . "/line-orders/" . substr($lineOrder["date"],2,6) . "/" . $lineOrder["other_num"] . ".pdf";
        // 判断文件是否存在
        if (file_exists($pdf)) {
            return $pdf;
        }

        $customData = [
            "orderno" => "",
            "goods_num" => "",
            "remark" => "",
        ];
        if($lineOrder){
            $customData = [
                "orderno" => $lineOrder["no"],
                "goods_num" => $lineOrder["expire_info"],
                "remark" => $lineOrder["remark"],
            ];
        }
        // 构造请求参数
        $requestData = [
            "templateCode" => $templateCode,
            "documents" => [
                "masterWaybillNo" => $expressNo,
                "customData" => $customData,
                "isPrintLogo" => "true",
            ],
            "version" => "2.0",
            "fileType" => "pdf",
            "sync" => true,
            "customTemplateCode" => "fm_150_standard_custom_10008135507_1"
        ];

        // 接口服务代码
        $url='https://bspgw.sf-express.com/std/service';
        if($this->isDebug){
            $url='https://sfapi-sbox.sf-express.com/std/service';
        }
        $result=$this->SFPost2($url,'COM_RECE_CLOUD_PRINT_WAYBILLS',$requestData);
        // 获取订单面单
        if(!$result["success"]){
            return false;
        }
        // 遍历面单
        foreach($result['obj']['files'] as $v){
            return $this->SFDownloadFile($expressNo,$v['url'],$v['token'],$lineOrder);
        }
        return false;
    }

    private function SFPost2($url,$serviceCode,$msgData){
        $msgData=json_encode($msgData,JSON_UNESCAPED_UNICODE);
        $timestamp=time();
        $partnerID=$this->partnerID;
        $checkword=$this->checkword;
        $msgDigest = base64_encode(md5((urlencode($msgData .$timestamp. $checkword)), TRUE));
        //发送参数
        $requestID = $this->create_uuid();
        $post_data = array(
            'partnerID' => $partnerID,
            'requestID' => $requestID,
            'serviceCode' => $serviceCode,
            'timestamp' => $timestamp,
            'msgDigest' => $msgDigest,
            'msgData' => $msgData
        );
        $result=$this->httpPost($url,$post_data);
        if($result['apiResultCode']!='A1000'){
            return false;
        }
        return json_decode($result['apiResultData'],true);
    }

    public function getProduct()
    {
        $result=Redis::basic()->get('sfly:productlist');
        if($result!=null){
            return $result;
        }
        $product=ExpressProduct::query()->where('express',$this->key)->orderBy('sort')->get();
        $result=[];
        foreach($product as $k=>$v){
            $result[$v['product']]=$v['name'];
        }
        Redis::basic()->set('sfly:productlist',$result,1200);
        return $result;
    }

    public function expRoute($data, $cache = false)
    {
        writeInfoLog('收到顺丰冷运路由信息',$data);
        return $data;
        // TODO: Implement expRoute() method.
    }

    public function expState($data, $cache = false)
    {
        if($cache){
            return array(
                'apiErrorMsg'=>"",
                'apiResponseID'=>$this->create_uuid(),
                'apiResultCode'=>'A1000',
                'apiResultData'=>'{"msg":"接收成功","success":true}',
                'success'=>true,
                'msg'=>'接收成功'
            );
        }
        $data=$data['msgData'];
        if(is_string($data)){
            $data=json_decode($data,true);
        }
        $ordernum=$data['erpOrder']??'';
        if($ordernum==''){
            return;
        }
        $orderInfo=LineOrder::query()->where('no',$ordernum)->first();
        if(!$orderInfo){
            return;
        }
        OrderRouteLog::insertLog('express',$orderInfo['id'],$data['orderStatus'],"",0,0,40,null,strtotime($data['time']));
    }

    public function expPrice($data, $cache = false)
    {
        if($cache){
            return array(
                'apiErrorMsg'=>"",
                'apiResponseID'=>$this->create_uuid(),
                'apiResultCode'=>'A1000',
                'apiResultData'=>'{"msg":"接收成功","success":true}',
                'success'=>true,
                'msg'=>'接收成功'
            );
        }
        $data=$data['msgData'];
        if(is_string($data)){
            $data=json_decode($data,true);
        }
        $orderList=$data['orderGoodsList'];
        $data=$data['order'];
        $order=$data['erpNo'];
        $weight=$data['meterageWeight'];
        $total=0;
        $lineOrder=LineOrder::query()->where('no',$order)->first();
        $price=$total;
        if($lineOrder){
            $city_code=Map::GetCityCode2($lineOrder['consignee_city']);
            if($city_code!=''&&$city_code!=false){
                $price=$this->getPriceLocalCode($lineOrder['express_company_id'],$lineOrder['expressinfo']['expressGoods']??'SE003003',$lineOrder['shop_company_id'],$city_code,$weight,$lineOrder['route_id']);
            }
            if($price==false){
                $price=$total;
            }
        }
        $volume=0;
        foreach($orderList as $o){
            $volume+=$o['volume']??0;
        }
        $weightArr=$this->getWeightArr($lineOrder,$weight);
        if($price<=0){
            return;
        }
        $line=LineOrder::query()->where('no',$order)->where('from_admin','express')->update([
            'weight'=>$data['meterageWeight'],
            'price'=>$price,
            'pricelog'=>serialize($data),
            'newprice'=>1,
            'volume'=>$volume,
            'fee_time'=>getFeeTime('expressorder'),
            'weight_arr'=>json_encode($weightArr,256)
        ]);
        LineOrder::query()->where('no',$order)->where('status','<',2)->where('status','>=',0)->update([
            'status'=>5
        ]);
        LineOrder::query()->where('no',$order)->whereNull('receive_at')->update([
            'receive_at'=>nowDate()
        ]);
        WriteLog('顺丰冷运收到价格：'.json_encode($data,256)."|".getFeeTime('expressorder')."|".$line);
        if($lineOrder){
            OrderLog::InsertLog($lineOrder['id'],'收到计费：'.($price),1,OrderLog::TYPE_PRICE,'line');
            OrderBill::writeBill($lineOrder['id'],'line');
            ApiInfo::BillChange('line',$lineOrder['id'],$price);
        }
        LineOrder::query()->where('no',$order)->where('from_admin','express')->update([
            'fee_time'=>getFeeTime('expressorder')
        ]);
    }

    public function regRoute($line_order_id)
    {
        // TODO: Implement regRoute() method.
        return false;
    }

    /**
     * 冷运专线发送请求
     * @param string $serviceCode 接口服务代码
     * @param array $msgData 业务数据报文
     * @return mixed
     */
    private function SFColdCargoPost($serviceCode, $msgData) {
        $msgData=json_encode($msgData,JSON_UNESCAPED_UNICODE);
        $timestamp=time();
        $partnerID=$this->partnerID;
        $checkword=$this->checkword;
        $requestID = $this->create_uuid();
        $msgDigest = base64_encode(md5((urlencode($msgData .$timestamp. $checkword)), TRUE));

        // 构造公共请求参数
        $publicRequestData = [
            "partnerID" => $partnerID,
            "requestID" => $requestID,
            "serviceCode" => $serviceCode,
            "timestamp" => $timestamp,
            "msgDigest" => $msgDigest,
            "msgData" => $msgData,
        ];
        // 发送请求，并判断接口调用是否正常
        $result = $this->httpPost($this->host, $publicRequestData);
        if ($result["apiResultCode"] != "A1000") {
            return false;
        }

        // 返回反序列化后的数据
        return json_decode($result["apiResultData"], true);
    }

    /**
     * SFDownloadFile 下载面单文件
     * @param mixed $billno 
     * @param mixed $url URL
     * @param mixed $token 
     * @param mixed $order 订单
     * @return string
     */
    private function SFDownloadFile($billno, $url, $token, $order){
        $path = public_path("storage") . "/line-orders/" . substr($order["date"], 2, 6) . "/";
        if(!is_dir($path)){
            mkdir($path,0777,true);
            @chmod($path,0777);
        }
        $saveFile = $path . $billno . ".pdf";
        Basic::downLoadFile($url, $saveFile, [
            "X-Auth-token:" . $token
        ]);
        if(file_exists($saveFile)){
            $imgfile2 = public_path("storage") . "/line-orders/". substr($order["date"], 2, 6) . "/";
            $result = pdf2png($saveFile, $imgfile2, 210.4, 212, 5);
            $info = $result[0] ?? '';
            if($info != ""){
                rename($info, $imgfile2.$order["id"] . ".png");
            }
        }
        return $saveFile;
    }
}
