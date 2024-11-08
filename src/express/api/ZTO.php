<?php

namespace iboxs\deliver\express\api;

use iboxs\deliver\express\lib\BaseExpress;
use iboxs\deliver\express\lib\ExpressApiImplement;
use iboxs\basic\Basic;
use iboxs\redis\Redis;

class ZTO extends BaseExpress implements ExpressApiImplement
{
    protected $appKey='';
    protected $appSecret='';

    public function setConfig($config)
    {
        if (isset($config['appKey'])){ //用户有配置自己的顾客编码等
            $this->appKey=$config['appKey'];
            $this->appSecret=$config['appSecret'];
        } else{
            $ztoConfig=Config::GetConfig('exp_zhongtong');
            $this->appKey=$ztoConfig['appKey'];
            $this->appSecret=$ztoConfig['appSecret'];
        }
        if($this->isDebug){ //沙箱
            $this->appKey='';
            $this->appSecret='';
        }
        $this->host=$this->isDebug?
            "https://japi-test.zto.com/": //沙箱
            "https://japi.zto.com/"; //生产
    }

    public function sendOrder($order_id,&$reson)
    {
        $order=LineOrder::query()->with(['shop_company','express_company'])->find($order_id);
        if(!$order){
            $reson='订单不存在';
            return false;
        }
        if($order['express_api']>=2){
            return true;
        }
        $moreGoods=$this->getMoreGoods($this->key,$order);
        $remark=$order['remark']??'';
        if($remark==''){
            $remark=$order['expire_info'];
        } else{
            $remark.="|".$order['expire_info'];
        }
        $data=[
            'partnerType'=>'2',
            'orderType'=>'3',
            'partnerOrderCode'=>$order['no'],
            'senderInfo'=>[
                'senderName'=>$order['shipper_name'],
                'senderPhone'=>$order['shipper_mobile'],
                'senderMobile'=>$order['shipper_mobile'],
                'senderProvince'=>$order['shipper_province'],
                'senderCity'=>$order['shipper_city'],
                'senderDistrict'=>$order['shipper_area'],
                'senderAddress'=>$order['shipper_address']
            ],
            'receiveInfo'=>[
                'receiverName'=>$order['consignee_name'],
                'receiverMobile'=>$order['consignee_mobile'],
                'receiverProvince'=>$order['consignee_province'],
                'receiverCity'=>$order['consignee_city'],
                'receiverDistrict'=>$order['consignee_area'],
                'receiverAddress'=>$order['consignee_address']
            ],
            'quantity'=>1,
            'remark'=>$remark,
            'orderItems'=>[
                'name'=>$moreGoods,
                'remark'=>$order['expire_info']
            ]
        ];
        $cardUserNo=$this->getCardUserNo($this->key,$order['shop_company_id'],$order['expressinfo']['expressGoods']??false); //电子面单账号
        if($cardUserNo!=false){
            $data['orderType']='1';
            $data['accountInfo']=[
                'accountId'=>$cardUserNo[0],
                'accountPassword'=>$cardUserNo[1]
            ];
            if(count($cardUserNo)>2){
                $data['orderVasList']=[
                    'vasType'=>$cardUserNo[2]
                ];
            }
        }
        $monthCard=$this->getMonthCard($order['express_company_id'],$order['shop_company_id']); //集团客户编码
        if($monthCard!=false){
            $data['partnerType']=1;
            if(isset($data['accountInfo'])){
                $data['accountInfo']['customerId']=$monthCard;
            } else{
                $data['accountInfo']=[
                    'customerId'=>$monthCard
                ];
            }
        }
        $result=$this->ZTOPost('zto.open.createOrder',$data);
        WriteLog('中通下单：'.json_encode($data,256)."|".json_encode($result));
        if($result['status']!=true){
            $reson=$result['message'];
            LineOrder::query()->where('id',$order_id)->update([
                'express_return'=>serialize([
                    'errorMsg'=>$reson
                ]),
                'express_api_time'=>nowDate()
            ]);
            return false;
        }
        LineOrder::query()->where('id',$order_id)->update([
            'express_order'=>serialize([
                'company'=>'zhongtong'
            ]),
            'express_return'=>serialize($result),
            'express_api'=>2,
            'other_num'=>$result['result']['billCode']??null,
            'express_api_time'=>nowDate(),
            'express_no'=>$result['result']['orderCode']??''
        ]);
        $sresult=$this->ZTOPost('zto.merchant.preorder.subsrcibe',[
            'orderCode'=>$result['result']['orderCode']??''
        ]);
        $sresult=$this->ZTOPost('zto.merchant.waybill.track.subsrcibe',[
            'billCode'=>$result['result']['billCode']??''
        ]);
        if(isset($result['result']['billCode'])){
            $this->getOrderPDF($result['result']['billCode']);
        }
        return true;
    }

    public function GetPrice(Address $sendAddress, Address $consignAddress, $weight, $volume, &$reson, $local = true)
    {
        return 0;
        $cacheInfo=$this->getCacheKey($this->key,'express:api:getpriceinfo'.$weight,$sendAddress,$consignAddress,$key);
        if($cacheInfo!=null){
            $reson=$cacheInfo['reson'];
            $fee=$cacheInfo['fee'];
            return $fee;
        }
        $data=[
            'transportType'=>0,
            'sender'=>[
                'address'=>$sendAddress->address,
                'province'=>$sendAddress->province,
                'city'=>$sendAddress->city,
                'district'=>$sendAddress->area
            ],
            'addresser'=>[
                'address'=>$consignAddress->address,
                'province'=>$consignAddress->province,
                'city'=>$consignAddress->city,
                'district'=>$consignAddress->area
            ],
            'weight'=>$weight
        ];
        $result=$this->ZTOPost('zto.open.obtainPicePrescription',$data);
        if($result['status']==false){
            $reson=$result['message'];
            $cacheInfo=[
                'reson'=>$reson,
                'fee'=>false
            ];
            $this->setCacheKey($this->key,'express:api:getpriceinfo',$sendAddress,$consignAddress,$cacheInfo,3600);
            return false;
        }
        $reson=$result['message'];
        $result=$result['result'];
        if($weight<=1){
            $fee=$result['price']??$result['firstWeightPrice'];
        } else{
            $fee=$result['price'];
        }
        $cacheInfo=[
            'reson'=>$reson,
            'fee'=>$fee
        ];
        $this->setCacheKey($this->key,'express:api:getpriceinfo',$sendAddress,$consignAddress,$cacheInfo,3600);
        return $fee;
    }

    public function cancelOrder($order)
    {
        $mailNo=$order['other_num'];
        if($mailNo==''||$mailNo==null){
            $data=[
                'cancelType'=>'2',
                'orderCode'=>$order['express_no']
            ];
        } else{
            $data=[
                'cancelType'=>'2',
                'billCode'=>$mailNo
            ];
        }
        $result=$this->ZTOPost('zto.open.cancelPreOrder',$data);
        writeInfoLog('中通取消订单',$data,$result);
        if($result['message']=='查询不到该订单'){
            return true;
        }
        return $result['status'];
    }

    public function filterOrder($user, Address $sendAddress, Address $consignAddress, &$reson)
    {
        $data=[
            'address'=>$consignAddress->address,
            'province'=>$consignAddress->province,
            'city'=>$consignAddress->city,
            'district'=>$consignAddress->area
        ];
        $result=$this->ZTOPost('zto.open.areaUnobstructed',$data);
        WriteLog('中通筛单：'.json_encode($data,true)."|".json_encode($result,true));
        if($result['result']['forbidType']!='B04'){
            $reson=$result['result']['forbidReason'];
            return false;
        }
        $reson=$result['result']['forbidReason']??'通过校验';
        return true;
    }

    public function queryRoute($expressNo, $phone)
    {
        if(Basic::isEmpty($expressNo)){
            return [];
        }
        $expressResultTmp=Redis::basic()->get("ztoexpressroute:{$expressNo}:{$phone}");
        if($expressResultTmp!=null){
            return $expressResultTmp;
        }
        $data=[
            'billCode'=>$expressNo,
            'mobilePhone'=>substr($phone,strlen($phone)-4,4)
        ];
        $result=$this->ZTOPost('zto.merchant.waybill.track.query',$data);
        if(!$result['status']){
            return false;
        }
        foreach($result['result'] as $iteminfo){
            $expressResult[]=[
                'city'=>$iteminfo['scanSite']['city'],
                'city_code'=>Map::GetCityCode2($iteminfo['scanSite']['city']),
                'time'=>$iteminfo['scanDate']/1000,
                'express_order'=>$expressNo,
                'content'=>$iteminfo['desc'],
                'contact'=>$iteminfo['operateUser'],
                'phone'=>$iteminfo['operateUserPhone'],
                'status'=>'',
                'opname'=>$iteminfo['scanType'],
                'display'=>''
            ];
        }
        Redis::basic()->set("ztoexpressroute:{$expressNo}:{$phone}",$expressResult,1200);
        return $expressResult;
    }

    public function getOrderPrice($order)
    {
        return false;
        $data=[
            'type'=>1,
            'billCode'=>$order['other_num']
        ];
        $result=$this->ZTOPost('zto.open.getOrderInfo',$data);
        writeInfoLog('中通获取详情',$data,$result);
        if(!$result['status']){
            return false;
        }
        $fee=($result['result'][0]['parcelFreight']??0)+($result['result'][0]['parcelOtherFee']??0)+($result['result'][0]['parcelPackingFee']??0);
        $weight=($result['result'][0]['parcelWeight']??0)/1000;
        $sendAddress=new Address($order['shipper_province'],$order['shipper_city'],$order['shipper_area'],$order['shipper_address'],$order['shipper_name'],$order['shipper_mobile']);
        $consignAddress=new Address($order['consignee_province'],$order['consignee_city'],$order['consignee_area'],$order['consignee_address'],$order['consignee_name'],$order['consignee_mobile']);

        $expressinfo=$order['expressinfo'];
        $price=$this->getPriceLocal($order['express_company_id'],$expressinfo['expressGoods'],$order['shop_company_id'],$sendAddress,$consignAddress,$weight,$order['route_id']);
        if($price!=false){
            $fee=$price*100;
        }
        $data=$result['result'][0];

        $weightArr=$this->getWeightArr($order,($data['parcelWeight']/1000)??0);
        $line=LineOrder::query()->where('id',$order['id'])->update([
            'weight'=>($data['parcelWeight']/1000)??0,
            'volume'=>$data['totalVolume']??0,
            'receive_at'=>nowDate(),
            'price'=>$fee,
            'pricelog'=>serialize($data),
            'newprice'=>1,
            'fee_time'=>getFeeTime('expressorder'),
            'weight_arr'=>json_encode($weightArr,256)
        ]);

        OrderLog::InsertLog($order['id'],'收到计费：'.($fee/100),1,OrderLog::TYPE_PRICE,'line');
        
        LineOrder::query()->where('id',$order['id'])->where('status','<',2)->where('status','>=',0)->update([
            'status'=>5
        ]);
        return array($fee/100,($result['result'][0]['parcelWeight']??0)/1000,$result);
    }

    public function getOrderPDF($expressNo)
    {
        if(Basic::isEmpty($expressNo)){
            return false;
        }
        $lineorder=LineOrder::query()->where('other_num',$expressNo)->first();
        if(!$lineorder){
            return false;
        }
        $path=public_path().'/storage/expressfiles/'.date('ymd',strtotime($lineorder['created_at']))."/";
        if(!is_dir($path)){
            $this->printFile($expressNo);
            return false;
        }
        $file=$path.'/'.$expressNo.'.pdf';
        if(!file_exists($file)){
            $this->printFile($expressNo);
            return false;
        }
        return $file;
    }

    private function printFile($expressNo){
        $pdfData=[
            'billCode'=>$expressNo,
            'printLogo'=>true
        ];
        $result=$this->ZTOPost('zto.open.order.print',$pdfData);
        writeInfoLog('中通打单',$expressNo,json_encode($result));
    }

    public function getProduct()
    {
        $result=Redis::basic()->get('zto:productlist');
        if($result!=null){
            return $result;
        }
        $product=ExpressProduct::query()->where('express',$this->key)->orderBy('sort')->get();
        $result=[];
        foreach($product as $k=>$v){
            $result[$v['product']]=$v['name'];
        }
        Redis::basic()->set('zto:productlist',$result,1200);
        return $result;
    }

    private function getSign($data){
        $str='';
        foreach ($data as $k => $v) {
            if(is_array($v)){
                $v=json_encode($v,256);
            }
            $str .= $k ."=" .$v ."&";
        }
        $str=substr($str,0,strlen($str)-1);
//        dd($str);
        $sign=base64_encode(md5($str.$this->appSecret,true));
        return $sign;
    }

    private function ZTOPost($service,$data){
        $body=json_encode($data,256);
        $sign=base64_encode(md5($body.$this->appSecret,true));
        $header=[
            "x-appKey:{$this->appKey}",
            "x-dataDigest:{$sign}"
        ];
        $url=$this->host.$service;
        $result=$this->httpPostJson($url,$data,$header);
        WriteLog("请求中通接口：{$url}:".json_encode($data,256)."|".json_encode($result,256));
        return $result;
    }

    public function expRoute($data, $cache = false)
    {
        if($cache==true){
            return [
                'message'=>'调用成功',
                'status'=>true,
                'result'=>'成功',
                'statusCode'=>'200'
            ];
        }
        // $data=request()->post();
        WriteLog('收到中通订单轨迹：'.json_encode($data,256));
        $vdata=$data['data'];
        $data=json_decode($vdata,true);
        $order=LineOrder::query()->where('other_num',$data['billCode'])->first();
        if(!$order){
            return;
        }
        if($order['newprice']==0){ //未更新价格
            RabbitMQ::add('express.zhongtong',$order['id']);
        }
        if($data['action']=='PROBLEM'){

        }
        $logStatus=41;
        $orderStatus=0;
        $opCode=$data['action'];
        switch($opCode){
            case 'GOT':
                $logStatus=41;
                $orderStatus=4;
                break;
            case 'DEPARTURE':
                $logStatus=41;
                $orderStatus=5;
                break;
            case 'ARRIVAL':
                $orderStatus=6;
                $logStatus=45;break;
            case 'DISPATCH':
                $orderStatus=6;
                $logStatus=42;break;
            case 'RETURN_SCAN':
                $orderStatus=-1;
                $logStatus=43;break;
            case 'RETURN_SIGNED':
            case 'DEPARTURE_SIGNED':
            case 'SIGNED':
                $orderStatus=20;
                LineOrder::query()->where('no',$order['no'])->update([
                    'finish_at'=>$data['actionTime']
                ]);
                $logStatus=100;break;
            case 'INBOUND':
            case 'HANDOVERSCAN_SIGNED':
                $orderStatus=6;
                $logStatus=45;break;
            case 'PROBLEM':
                $orderStatus=-1;
                $logStatus=-1;break;
        }
        if($orderStatus!=0){
            LineOrder::query()->where('no',$order['no'])->update([
                'status'=>$orderStatus
            ]);
        }
        OrderLog::InsertLog($order['id'],'中通变更状态为:'.(LineOrder::$statusMap[$orderStatus]??''),1,OrderLog::TYPE_OTHER,'line');
        OrderRouteLog::insertLog('express',$order['id'],$data['desc']??'',null,null,0,0,$logStatus,$data['city']??'',strtotime($data['actionTime']));
    }

    public function expState($data, $cache = false)
    {
        if($cache==true){
            return [
                'message'=>'调用成功',
                'status'=>true,
                'result'=>'成功',
                'statusCode'=>'200'
            ];
        }
        // $data=request()->post();
        WriteLog('收到中通订单状态：'.json_encode($data,256));
        $vdata=$data['data'];
        $tsign=$data['data_digest'];
        unset($data['data_digest']);
        $sign=$this->getSign($data['data']);
        // if($sign!=$tsign){
        //     return false;
        // }
        $data=json_decode($vdata,true);
        $no=$data['order_code'];
        $lineorder=LineOrder::query()->where('express_no',$data['bill_code'])->first();
        if(!$lineorder){
            return;
        }
        if(isset($data['bill_code'])&&$data['bill_code']!=''){
            $lineorder->other_num=$data['bill_code'];
            $lineorder->save();
            $this->printFile($data['bill_code']);
        }
        switch($data['pre_order_status']){
            case 1:
            case 2:
            case 3:
                $lineorder->status=2;
                break;
            case 4:
                $lineorder->status=4;
                break;
            case 5:
                $lineorder->status=20;
                break;
            case 6:
                $lineorder->status=-12;
                break;
            case 15:
                $lineorder->other_num=$data['bill_code'];
                break;
        }
        $lineorder->save();
        OrderLog::InsertLog($lineorder->id,'中通变更状态为:'.(LineOrder::$statusMap[$lineorder->status]??'').",原因：".( $data['error_msg']??''),1,OrderLog::TYPE_OTHER,'line');
        return true;
    }

    public function expPrice($data, $cache = false)
    {
        if($cache==true){
            return [
                'message'=>'调用成功',
                'status'=>true,
                'result'=>'成功',
                'statusCode'=>'200'
            ];
        }
        WriteLog('收到中通清单运费：'.json_encode($data,256));
        return;
        // dd($data);
        $data=json_decode($data['result'],true);
        $order=LineOrder::query()->where('other_num',$data['billCode'])->first();
        if(!$order){
            return;
        }
        $weightArr=$this->getWeightArr($order,$data['settlementWeight']/1000);
        $line=LineOrder::query()->where('no',$order['no'])->where('from_admin','express')->update([
            'weight'=>$data['settlementWeight']/1000,
            'receive_at'=>nowDate(),
            'price'=>$data['settlementAmount']/100,
            'pricelog'=>serialize($data),
            'newprice'=>1,
            'volume'=>$data['volume']??0,
            'fee_time'=>getFeeTime('expressorder'),
            'weight_arr'=>json_encode($weightArr,256)
        ]);
        LineOrder::query()->where('no',$order['no'])->where('status','<',2)->where('status','>=',0)->update([
            'status'=>5
        ]);
        WriteLog('中通收到价格：'.$order."|".getFeeTime('expressorder')."|".$line);
        if($order){
            OrderLog::InsertLog($order['id'],'收到计费：'.($data['settlementAmount']/100),1,OrderLog::TYPE_PRICE,'line');
            OrderBill::writeBill($order['id'],'line');
            ApiInfo::BillChange('line',$order['id'],$data['settlementAmount']/100);
        }
    }

    public function regRoute($line_order_id)
    {
        $order=LineOrder::query()->find($line_order_id);
        if(!$order){
            return '订单不存在';
        }
        if($order['other_num']==''){
            return '快递单号为空';
        }
        $phone=$order['consignee_mobile'];
        if($phone==null||$phone==''||(!Basic::isPhone($phone))){
            $phone=$order['shipper_mobile'];
            if($phone==null||$phone==''||(!Basic::isPhone($phone))){
                return '收件人或发件人手机号异常，不可注册';
            }
        }
        $expressNo=$order['other_num'];
        if(Basic::isEmpty($expressNo)){
            return '运单号为空，暂不支持订阅';
        }
        $data=[
            'billCode'=>$expressNo,
            'mobilePhone'=>substr($phone,mb_strlen($phone)-4,4)
        ];
        $result=$this->ZTOPost('zto.merchant.waybill.track.subsrcibe',$data);
        if($result['status']==true){
            return true;
        }
        return $result['message'];
    }
    
    private function getCardUserNo($key,$shop_company_id,$product){
        if(env('APP_DEBUG')){
            return array('test','ZTO123');
        }
        if($shop_company_id==93){
            if($product=='zhongtong'||$product==false||$product=='LEN'){
                return array('KDGJ221000063466','6ELCXR1S','standardExpress');
            } else{
                return array('KDGJ221000082432','496WAH9S','standardExpress');
            }
        } else{
            return array('KDGJ221000064958','JNZRTTSZ','standardExpress');
            // return array('KDGJ221000066895','T00PGB7E','standardExpress');
        }
    }
}
