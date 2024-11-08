<?php

namespace iboxs\deliver\express\api;

use iboxs\basic\Basic;
use iboxs\deliver\express\lib\BaseExpress;
use iboxs\deliver\express\lib\ExpressApiImplement;
use iboxs\redis\Redis;

class DBK extends BaseExpress implements ExpressApiImplement
{
    protected $appkey='';
    protected $bianma='';
    protected $sign='';

    public function setConfig($config)
    {
        if (isset($config['appkey'])){ //用户有配置自己的顾客编码等
            $this->appkey=$config['appkey'];
            $this->bianma=$config['bianma'];
            $this->sign=$config['sign'];
        } else{
            $debangConfig=Config::GetConfig('exp_debang');
            $this->appkey=$debangConfig['appkey'];
            $this->bianma=$debangConfig['bianma'];
            $this->sign=$debangConfig['sign'];
        }
        $this->host=$this->isDebug?
            "http://dpapi.deppon.com/dop-interface-sync/": //沙箱
            "http://dpapi.deppon.com/dop-interface-sync/"; //生产
    }

    public function sendOrder($order_id,&$reson)
    {
        $order=LineOrder::query()->find($order_id);
        if(!$order){
            $reson='订单不存在';
            return false;
        }
        $expressinfo=$order['expressinfo'];
        $moreGoods=$this->getMoreGoods($this->key,$order);
        $monthCard=$this->getMonthCard($order['express_company_id'],$order['shop_company_id']);
        $parmas = [
            'logisticID' => $this->sign.$order['no'],//订单ID
            'companyCode'=>$this->bianma,
            'orderType'=>2,
            'needTraceInfo'=>1,
            'sender' => [
                'name' => $order['shipper_name'],
                'mobile' => $order['shipper_mobile'],
                'province' => $order['shipper_province'],
                'city' => $order['shipper_city'],
                'county' => $order['shipper_area'],
                'address' => $order['shipper_address'],
            ],
            'receiver' => [
                'name' => $order['consignee_name'],
                'phone' => $order['consignee_mobile'],
                'province' => $order['consignee_province'],
                'city' => $order['consignee_city'],
                'county' => $order['consignee_area'],
                'address' => $order['consignee_address'],
            ],
            'gmtCommit' => date('Y-m-d H:i:s'),
            'payType'=>2,
            'packageInfo'=>[
                'cargoName'=>$moreGoods,
                'totalNumber'=>1,
                'totalWeight'=>$order['predictive_weight'],
                'totalVolume'=>$order['predictive_volume'],
                'deliveryType'=>$expressinfo['deliveryType']??4
            ],
            'transportType'=>$expressinfo['expressGoods']??'HKDJC'
        ];
        $moreCard=$this->getMonthCard($order['express_company_id'],0);
        /* if($moreCard==$monthCard){ //散客
            $parmas['orderType']=1;
        } */
        $parmas['customerCode']=$monthCard;
        $parmas['payType']=ExpressInfo::$DBKPayMode[$order['pay_mode']]??2;
        /* if($parmas['payType']==2 && $moreCard==$monthCard){ //散客寄付
            $parmas['payType']=0;
        } */
        if(isset($order['remark'])){
            $parmas['remark']=$order['remark'];
        } else{
            $parmas['remark']='';
        }
        if(!Basic::isEmpty($order['expire_info']??'')){
            $parmas['remark'].="|".($order['expire_info']??'');
        }
        if($this->isDebug){
            $parmas['customerCode']='219401';
        }
        $result=$this->DBPost('http://gwapi.deppon.com/dop-interface-async/standard-order/createOrderNotify.action',$parmas);
        WriteLog('德邦快递下单回复：'.json_encode($parmas,256).'|'.json_encode($result,JSON_UNESCAPED_UNICODE));
        if($result['result']!=true||$result['result']=='false'){
            if($result['reason']==' 道单号或运单号重复！'){
                return true;
            }
            LineOrder::query()->where('id',$order_id)->update([
                'express_return'=>serialize($result),
                'express_api_time'=>nowDate()
            ]);
            $reson=$result['reason'];
            return false;
        }
        $billno=$result['mailNo'];
        $billArr=explode(',',$billno);
        $waybill=$billArr[0];
        $waysonbill=[];
        if(count($billArr)>1){
            foreach($billArr as $b){
                $waysonbill[]=$b;
            }
        }
        LineOrder::query()->where('id',$order_id)->update([
            'express_order'=>serialize([
                'company'=>'debang',
                'order'=>$waybill,
                'son_order'=>$waysonbill
            ]),
            'other_num'=>$waybill,
            'express_return'=>serialize($result),
            'express_api'=>2,
            'express_api_time'=>nowDate()
        ]);
        $this->traceSubscribe($waybill);
        RabbitMQ::add('express.exprintcard',[
            'key'=>$this->key,
            'no'=>$waybill
        ]);
        OrderRouteLog::insertLog('express',$order['id'],'已推送到德邦快递',null,null,0,0,1);
        return true;
    }

    public function GetPrice(Address $sendAddress, Address $consignAddress, $weight, $volume, &$reson,$local=true)
    {
        $parmas=[
            'originalsStreet'=>$consignAddress->province.'-'.$consignAddress->city.'-'.$consignAddress->area.','.$consignAddress->address,
            'originalsaddress'=>$sendAddress->province.'-'.$sendAddress->city.'-'.$sendAddress->area.','.$sendAddress->address??'',
            'sendDateTime'=>date('Y-m-d H:i:s',time()+7200),
            'totalVolume'=>$volume,
            'totalWeight'=>floatval($weight),
            'logisticCompanyID'=>'DERPON'
        ];
        $cacheInfo=$this->getCacheKey($this->key,'expresspriceinfo:express:debang'.$weight,$sendAddress,$consignAddress,$key);
        if($cacheInfo!=null){
            $reson=$cacheInfo['reson'];
            return $cacheInfo['fee'];
        }
        $result=$this->DBPost('standard-query/queryPriceTime.action',$parmas);
        if($result==false){
            $reson='请求失败[德邦接口异常]';
            return false;
        }
        WriteLog('德邦价格数据请求：'.json_encode($parmas,256).'|'.json_encode($result,256));
        if($result['result']!='true'){
            $reson='请求失败[价格请求失败]';
            $cacheInfo=[
                'reson'=>$reson,
                'fee'=>false
            ];
            $this->setCacheKey($this->key,'expresspriceinfo:express:debang'.$weight,$sendAddress,$consignAddress,$cacheInfo,86400);
            return false;
        }
        $data=$result['responseParam'];

        if((!isset($data[0]['totalfee']))||$data[0]['totalfee']==0){
            if(count($data)<1){
                $reson='订单价格获取失败:'.($data[0]['message']??'');
                $cacheInfo=[
                    'reson'=>$reson,
                    'fee'=>false
                ];
                $this->setCacheKey($this->key,'expresspriceinfo:express:debang'.$weight,$sendAddress,$consignAddress,$cacheInfo,86400);
                return false;
            }
            $weightInt=intval($weight);
            $weightFloat=$weight-$weightInt;
            if($weightFloat<0.3){
                $weight=$weightInt;
            } else if($weightFloat>=0.3 && $weight<0.7){
                $weight=$weightInt+0.5;
            } else{
                $weight=$weightInt+1;
            }
            $price=$data[0];
            if((!isset($price['groundPrice']))||(!isset($price['rateOfStage1']))){
                $reson='订单价格获取失败:自计费首运费及续重运费为空';
                $cacheInfo=[
                    'reson'=>$reson,
                    'fee'=>false
                ];
                $this->setCacheKey($this->key,'expresspriceinfo:express:debang'.$weight,$sendAddress,$consignAddress,$cacheInfo,86400);
                return false;
            }
            $firstWeight=$price['upperGround']??1;
            if($weight<=$firstWeight){
                return $price['groundPrice'];
            }
            $totalPrice=$price['groundPrice']+($weight-$firstWeight)*$price['rateOfStage1'];
            $cacheInfo=[
                'reson'=>'获取成功',
                'fee'=>$totalPrice
            ];
            $this->setCacheKey($this->key,'expresspriceinfo:express:debang'.$weight,$sendAddress,$consignAddress,$cacheInfo,86400);
            return $totalPrice;
        }
        $totalPrice=$data[0]['totalfee']??0;
        $cacheInfo=[
            'reson'=>'获取成功',
            'fee'=>$totalPrice
        ];
        $this->setCacheKey($this->key,'expresspriceinfo:express:debang'.$weight,$sendAddress,$consignAddress,$cacheInfo,86400);
        return $data[0]['totalfee']??0;
    }

    public function cancelOrder($order)
    {
        $mailNo=$order['other_num'];
        if($mailNo==''){
            return true;
        }
        $parmas=[
            'logisticCompanyID'=>'DEPPON',
            'mailNo'=>$mailNo,
            'cancelTime'=>nowDate(),
            'remark'=>'客户取消订单'
        ];
        // $result=$this->DBPost('standard-order/cancelOrder.action',$parmas);
        return true;
    }

    public function filterOrder($user, Address $sendAddress, Address $consignAddress, &$reson)
    {
        $order=date('Ymd').time().random_int(100,999);
        $cacheInfo=$this->getCacheKey($this->key,'filterorder:api',$sendAddress,$consignAddress,$cacheKey);
        $cacheInfo=null;
        if($cacheInfo!=null){
            $reson=$cacheInfo['reson'];
            return $cacheInfo['result'];
        }
        $card=$this->getMonthCard(1483,$user->company_id);
        if($card==false){
            $reson='德邦订单必须有月结账号才可以使用接口下单';
            $cacheInfo=[
                'reson'=>$reson,
                'result'=>false
            ];
            return false;
        }
        $moreGoods=$this->getMoreGoods($this->key,['shop_company_id'=>$user->company_id,'express_company_id'=>1483,'goods'=>null]);

        $parmas=[
            'logisticCompanyID'=>'DERPON',
            'logisticID'=>$this->sign.$order,
            'custOrderNo'=>$order,
            'orderSource'=>$this->bianma,
            'serviceType'=>2,
            'customerCode'=>$card,
            'companyCode'=>$this->bianma,
            'sender'=>[
                'name'=>$sendAddress->name,
                'mobile'=>$sendAddress->phone,
                "phone"=>"",
                'province'=>$sendAddress->province,
                'city'=>$sendAddress->city,
                'county'=>$sendAddress->area,
                'address'=>$sendAddress->address
            ],
            'receiver'=>[
                'name'=>$consignAddress->name,
                'mobile'=>$consignAddress->phone,
                "phone"=>"",
                'province'=>$consignAddress->province,
                'city'=>$consignAddress->city,
                'county'=>$consignAddress->area,
                'address'=>$consignAddress->address
            ],
            'gmtCommit'=>date('Y-m-d H:i:s',time()+7200),
            'cargoName'=>$moreGoods,
            'payType'=>2,
            'transportType'=>'HKDJC',
            'deliveryType'=>3,
            'orderType'=>2
        ];
        try{
            $result=$this->DBPost('dop-standard-ewborder/expressSyncSieveOrder.action',$parmas);
            if($result==false){
                $reson='请求失败[德邦接口异常]';
                return false;
            }
        }catch(\Exception $ex){
            $reson='德邦接口网络异常';
            WriteLog('德邦筛单接口网络异常：'.$ex->getMessage(),'error');
            return false;
        }
        $reson='';
        if($result['result']=='true'){
            $cacheInfo=[
                'reson'=>'可以收派',
                'result'=>true
            ];
            $this->setCacheKey($this->key,'filterorder:api',$sendAddress,$consignAddress,$cacheInfo,86400);
            return true;
        }
        if($result['result']=='false') $reson=$result['reason']??'不可以收派';
        $cacheInfo=[
            'result'=>false,
            'reson'=>$reson
        ];
        $this->setCacheKey($this->key,'filterorder:api',$sendAddress,$consignAddress,$cacheInfo,86400);
        return false;
    }

    public function getOrderPrice($order)
    {
        LineOrder::query()->where('id',$order['id'])->update([
            'receive_at'=>nowDate()
        ]);
        $monthCard=$this->getMonthCard($order['express_company_id'],$order['shop_company_id']);
        $parmas=[
            'customerCode'=>$monthCard,
            'mailNo'=>$order['other_num']
        ];
        $url='http://dpapi.deppon.com.cn/dop-interface-sync/standard-query/sscWayBillQueryMsg.action';
        $result=$this->DBPost($url,$parmas);
        if($result==false){
            $reson='请求失败[德邦接口异常]';
            return false;
        }
        WriteLog('德邦价格数据获取：'.$url."|".json_encode($parmas,256)."|".json_encode($result,256));
        $id=$order['id'];
        LineOrder::query()->where('id',$id)->update([
            'pricelog'=>serialize($result)
        ]);
        if($result['result']!='true'){
            return false;
        }
        if(!isset($result['responseParam'])){
            return false;
        }
        $data=$result['responseParam'];
        $fee=$data['totalPrice']??0;
        $expressinfo=$order['expressinfo'];
        $sendAddress = new Address($order['shipper_province'], $order['shipper_city'], $order['shipper_area'], $order['shipper_address'], $order['shipper_name'], $order['shipper_mobile']);
        $consignAddress = new Address($order['consignee_province'], $order['consignee_city'], $order['consignee_area'], $order['consignee_address'], $order['consignee_name'], $order['consignee_mobile']);
        $price = $this->getPriceLocal($order['express_company_id'],$expressinfo['expressGoods'],$order['shop_company_id'], $sendAddress, $consignAddress, $data['chargedWeight']??0,$order['route_id']);
        // WriteLog('德邦本地计费：'.$price."|".$order['shop_company_id']);
        if ($price != false) {
            $fee=$price;
        }
        $weightArr=$this->getWeightArr($order,$data['chargedWeight']??0);
        $line=LineOrder::query()->where('id',$id)->update([
            'weight'=>$data['chargedWeight']??0,
            'volume'=>$data['totalVolume']??0,
            'receive_at'=>nowDate(),
            'price'=>$fee,
            'pricelog'=>serialize($data),
            'newprice'=>1,
            'fee_time'=>getFeeTime('expressorder'),
            'weight_arr'=>json_encode($weightArr,256),
        ]);
        LineOrder::query()->where('id',$id)->where('status','<',2)->where('status','>=',0)->update([
            'status'=>5
        ]);
        // WriteLog('德邦收到价格：'.$order['no']."|".getFeeTime('expressorder')."|".$line."|".$fee);
        OrderBill::writeBill($order['id'],'line');
        OrderLog::InsertLog($order['id'],'收到计费：'.($fee),1,OrderLog::TYPE_PRICE,'line');
        ApiInfo::BillChange('line',$id,$fee);
        LineOrder::query()->where('id',$id)->where('from_admin','express')->update([
            'fee_time'=>getFeeTime('expressorder')
        ]);
        return array($fee,$data['chargedWeight']??0,$data);
    }

    public function traceSubscribe($expressNo){
        $parmas=[
            'tracking_number'=>$expressNo
        ];
        // $result=$this->DBPost('standard-order/standTraceSubscribe.action',$parmas);
        // if($result==false){
        //     return false;
        // }
        return true;
    }

    public function queryRoute($expressNo, $phone)
    {
        return [];
    }

    public function getOrderPDF($expressNo)
    {
        if($expressNo==null||$expressNo==''){
            return false;
        }
        $order=LineOrder::query()->where('other_num',$expressNo)->first();
        return (new OrdercardDBK())->Main($order);
    }

    private function DBPost($url,$parmas){
        if(substr_count($url,'http://')==false){
            $url=$this->host.$url;
        }
        $parmas = json_encode($parmas);
        $timestamp=intval(microtime(true)*1000);
        $digest = base64_encode(md5($parmas . $this->appkey . $timestamp));
        $data = array (
            'companyCode'=> $this->bianma,
            'params'=> $parmas,
            'digest'=> $digest,
            'timestamp'=> $timestamp
        );
        $result=$this->curlPost($url,$data);
        return json_decode($result,true);
    }

    public function getProduct()
    {
        $result=Redis::basic()->get('dbk:productlist');
        if($result!=null){
            return $result;
        }
        $product=ExpressProduct::query()->where('express',$this->key)->orderBy('sort')->get();
        $result=[];
        foreach($product as $k=>$v){
            $result[$v['product']]=$v['name'];
        }
        Redis::basic()->set('dbk:productlist',$result,1200);
        return $result;
    }

    public function expRoute($data, $cache = false)
    {
        if($cache){ // 刚刚推送过来，已进队列，按开发文档返回数据
            return array(
                'success'=>true,
                'error_code'=>'1000',
                'error_msg'=>'成功',
                'result'=>true
            );
        }
        $data=json_decode($data['params'],true);
        $info=$data['track_list'][0];
        $list=$info['trace_list'];
        $order=$info['tracking_number'];
        foreach($list as $k=>$v){
            $orderInfo=LineOrder::query()->where('other_num',$order)->first();
            if(!$orderInfo){
                writeLogInfo('德邦异常推送:'. json_encode($data,256),'DEBUG');
                continue;
            }
            if($orderInfo['newprice']==0){ //未更新价格
                RabbitMQ::add('express.priceDebang',$order);
            }
            $logStatus=41;
            switch($v['status']){
                case 'GOT':$logStatus=40;
                    LineOrder::query()->where('other_num',$order)->update([
                        'status'=>4
                    ]);
                    RabbitMQ::add('express.priceDebang',$order,600);
                    break;
                case 'DEPARTURE':$logStatus=41;
                    LineOrder::query()->where('other_num',$order)->update([
                        'status'=>5
                    ]);
                    break;
                case 'ARRIVAL':$logStatus=41;break;
                case 'SENT_SCAN':$logStatus=42;
                    LineOrder::query()->where('other_num',$order)->update([
                        'status'=>6
                    ]);
                    break;
                case 'ERROR':$logStatus=42;
                    LineOrder::query()->where('other_num',$order)->update([
                        'status'=>30
                    ]);
                    break;
                case 'FAILED':$logStatus=43;
                    LineOrder::query()->where('other_num',$order)->update([
                        'status'=>-1
                    ]);
                    break;
                case 'SIGNED':$logStatus=100;
                    LineOrder::query()->where('other_num',$order)->update([
                        'status'=>20,
                        'finish_at'=>$v['time']
                    ]);
                    break;
            }
            OrderRouteLog::insertLog('express',$orderInfo['id'],$v['description'],null,null,0,0,$logStatus,$v['city']??'',strtotime($v['time']));
        }
    }

    public function expState($data, $cache = false)
    {
        WriteLog('收到德邦订单状态回调：'.(is_string($data)?$data:json_encode($data,JSON_UNESCAPED_UNICODE)));
        return array(
            'success'=>true,
            'error_code'=>'1000',
            'error_msg'=>'成功',
            'result'=>true
        );
    }

    public function expPrice($data, $cache = false)
    {

    }

    public function regRoute($line_order_id)
    {
        return true;
    }
}
