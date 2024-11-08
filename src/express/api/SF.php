<?php

namespace iboxs\deliver\express\api;

use iboxs\deliver\express\lib\BaseExpress;
use iboxs\deliver\express\lib\ExpressApiImplement;

class SF extends BaseExpress implements ExpressApiImplement
{
    protected $partnerID='';//顾客编码
    protected $checkword='';//校验码

    public function setConfig($config)
    {
        if (isset($config['partnerID'])){ //用户有配置自己的顾客编码等
            $this->partnerID=$config['partnerID'];
            $this->checkword=$config['checkword'];
        } else{
            $shunfengConfig=Config::GetConfig('exp_shunfeng');
            $this->partnerID=$shunfengConfig['partnerID'];
            $this->checkword=$shunfengConfig['checkword'];
        }
        if($this->isDebug){ //沙箱
            $this->partnerID='';
            $this->checkword='';
        }
        $this->host=$this->isDebug?
            "https://sfapi-sbox.sf-express.com/std/service": //沙箱
            "https://sfapi.sf-express.com/std/service"; //生产
    }

    public function getOrderView($no){
        $json=[
            'orderId'=>$no,
            'searchType'=>1
        ];
        $result=$this->SFPost2('https://bspgw.sf-express.com/std/service','EXP_RECE_SEARCH_ORDER_RESP',$json);
        return $result;
    }

    public function sendOrder($order_id,&$reson){
        $order=LineOrder::query()->with(['shop_company','express_company'])->find($order_id);
        if(!$order){
            $reson='订单不存在';
            return false;
        }
        $moreGoods=$this->getMoreGoods($this->key,$order,$order['express_company_id']);
        $monthCard=$this->getMonthCard($order['express_company_id'],$order['shop_company_id']);
        $expressinfo=$order['expressinfo'];
        $data=[
            'language'=>'zh-CN',
            'orderId'=>$order['no'],
            'cargoDetails'=>[
                'name'=>$moreGoods,
                'count'=>1,
                'weight'=>$order['predictive_weight'],
                'unit'=>'千克',
                'volume'=>$order['predictive_volume']
            ],
            'cargoDesc'=>$moreGoods,
            'monthlyCard'=>$monthCard,
            'contactInfoList'=>[
                [ //寄件方信息
                    'contactType'=>1,
                    'contact'=>$order['shipper_name'],
                    'tel'=>$order['shipper_mobile'],
                    'country'=>'CN',
                    'province'=>$order['shipper_province'],
                    'city'=>$order['shipper_city'],
                    'county'=>$order['shipper_area'],
                    'address'=>$order['shipper_address']
                ],
                [
                    'contactType'=>2,
                    'contact'=>$order['consignee_name'],
                    'tel'=>$order['consignee_mobile'],
                    'country'=>'CN',
                    'province'=>$order['consignee_province'],
                    'city'=>$order['consignee_city'],
                    'county'=>$order['consignee_area'],
                    'address'=>$order['consignee_address']
                ]
            ],
            'payMethod'=>ExpressInfo::$SFPayMode[$expressinfo['payMethod']??0],
            'parcelQty'=>1,
            'totalWeight'=>$order['predictive_weight'],
            'custReferenceNo'=>$order['no'],
            'isGenWaybillNo'=>1,
            'expressTypeId'=>$expressinfo['expressGoods']??266
        ];
        if($data['expressTypeId']==112){
            $data['expressTypeId']=266;
        }
        if(!is_numeric($data['expressTypeId'])){
            $reson='快递产品编码错误，请检查';
            return false;
        }
        // if($order['shop_company_id']==93){
        //     $data['expressTypeId']=266;
        // }
        // if($this->isDebug){ //沙箱环境不支持新空配
        //     $data['expressTypeId']=112;
        // }
        if(Basic::isEmpty($order['remark'])){
            $data['remark']=$order['remark'];
        }
        if(isset($expressinfo['pickTime'])){
            $data['sendStartTm']=$expressinfo['pickTime'];
        }
        if($this->isDebug){
            $data['monthlyCard']='7551234567';
        }
        // dd($data);
       $data['remark']=$order['remark'];
        $serviceCode='EXP_RECE_CREATE_ORDER';
        // dd($data);
        $result=$this->SFPost($serviceCode,$data);
        WriteLog('顺丰快递下单回复：'.json_encode($order,256)."|".json_encode($data,256).'|'.json_encode($result,JSON_UNESCAPED_UNICODE));
        if($result['success']!=true){
            if($result['errorMsg']=='重复下单'){
                return true;
            }
            LineOrder::query()->where('id',$order_id)->update([
                'express_return'=>serialize($result)
            ]);
            $reson=$result['errorMsg'];
            if($reson=='尊敬的客户，您好。因您下单流向顺丰空配（新）产品尚未开通，您可联系您的客户经理解决或选择我司其他产品下单，谢谢。'){
                sleep(2);
                $result=$this->sendOrder($order_id,$reson);
                if($result==false){
                    return false;
                }
                return true;
            }
            return false;
        }
        if($result['msgData']['filterResult']==3){  //不可以收派
            LineOrder::query()->where('id',$order_id)->update([
                'express_order'=>serialize([
                    'company'=>'shunfeng',
                    'order'=>$result['msgData']['waybillNoInfoList'][0]['waybillNo']??'',
                    'reson'=>$result['msgData']['remark']
                ]),
                'express_return'=>serialize($result['msgData']),
                'express_api_time'=>nowDate()
            ]);
            $reson=$result['msgData']['remark'];
            return false;
        }
        // $order
        $waybill='';
        $waysonbill=[];
        foreach($result['msgData']['waybillNoInfoList'] as $k=>$v){
            if($v['waybillType']==1){  //母单
                $waybill=$v['waybillNo'];
            } else{
                $waysonbill[]=$v['waybillNo'];
            }
        }
        LineOrder::query()->where('id',$order_id)->update([
            'express_order'=>serialize([
                'company'=>'shunfeng',
                'order'=>$waybill,
                'son_order'=>$waysonbill
            ]),
            'other_num'=>$waybill,
            'express_return'=>serialize($result['msgData']),
            'express_api'=>2,
            'express_api_time'=>nowDate()
        ]);
        RabbitMQ::add('express.exprintcard',[
            'key'=>$this->key,
            'no'=>$waybill
        ]);
        OrderRouteLog::insertLog('express',$order['id'],'已推送到顺丰快递',null,null,0,0,1);
        return true;
    }

    public function GetPrice(Address $sendAddress,Address $consignAddress,$weight,$volume,&$reson,$local=true){
        $fee=$this->getPriceLocal(26,266,0,$sendAddress,$consignAddress,$weight,'0');
        if($fee!==false){
            return $fee;
        }
        $cacheInfo=$this->getCacheKey($this->key,'express:api:getpriceinfo'.$weight,$sendAddress,$consignAddress,$key);
        if($cacheInfo!=null){
            $reson=$cacheInfo['reson'];
            $fee=$cacheInfo['fee'];
            return $fee;
        }
        $msgData = [
            'businessType'=>1,
            'weight'=>$weight,
            'volume'=>$volume,
            'searchPrice'=>'1',
            'consignedTime'=>nowDate(),
            'destAddress'=>[
                'province'=>$sendAddress->province,
                'city'=>$sendAddress->city,
                'district'=>$sendAddress->area,
                'address'=>$sendAddress->address
            ],
            'srcAddress'=>[
                'province'=>$consignAddress->province,
                'city'=>$consignAddress->city,
                'district'=>$consignAddress->area,
                'address'=>$consignAddress->address
            ]
        ];
        $serviceCode = "EXP_RECE_QUERY_DELIVERTM";
        $result=$this->SFPost($serviceCode,$msgData);
        WriteLog('顺丰价格数据请求：'. json_encode($msgData,256) ."|".json_encode($result,256));
        if($result['success']!=true){
            $reson='请求失败[价格数据请求失败]';
            $cacheInfo=[
                'fee'=>false,
                'reson'=>$reson
            ];
            $this->setCacheKey($this->key,'express:api:getpriceinfo'.$weight,$sendAddress,$consignAddress,$cacheInfo,86400);
            return false;
        }
        $list=$result['msgData']['deliverTmDto'];
        foreach($list as $data){
            if(!isset($data['fee'])){
                continue;
            }
            if($data['fee']==null){
                continue;
            }
            $fee=$data['fee'];
            $reson='获取成功';
            $cacheInfo=[
                'fee'=>$fee,
                'reson'=>$reson
            ];
            $this->setCacheKey($this->key,'express:api:getpriceinfo'.$weight,$sendAddress,$consignAddress,$cacheInfo,86400);
            return $fee;
        }
        $reson='顺丰价格获取失败，请稍候重试';
        $cacheInfo=[
            'fee'=>0,
            'reson'=>$reson
        ];
        // $this->setCacheKey($this->key,'express:api:getpriceinfo'.$weight,$sendAddress,$consignAddress,$cacheInfo,86400);
        // return false;
        return 0;
    }

    public function cancelOrder($order)
    {
        $mailNo=$order['other_num'];
        if($mailNo==''){
            return true;
        }
        $json=[
            'orderId'=>$order['no'],
            'dealType'=>2
        ];
        $result=$this->SFPost('EXP_RECE_UPDATE_ORDER',$json);
        writeInfoLog('顺丰取消订单',$json,$result);
        if($result['success']==false){
            if($result['errorMsg']=='已消单'){
                return true;
            }
            return false;
        }
        return true;
    }

    public function filterOrder($user,Address $sendAddress,Address $consignAddress,&$reson){
        // $cacheInfo=$this->getCacheKey($this->key,'fiterorder:result:shunfeng',$sendAddress,$consignAddress,$cacheKey);
        // if($cacheInfo!=null){
        //     $reson=$cacheInfo['reson']??'';
        //     return $cacheInfo['result'];
        // }
        $card=ExpressConfig::getConfigVal($user->company_id,$this->key,'card');
        if($card==false){ //未配置月结卡，使用系统默认
            $card=ExpressCompany::getValue($this->key,'month_card');
        }
        $json=[
            [
                'contactInfos'=>[
                    [// 发件人
                        'contactType'=>1,
                        'tel'=>$sendAddress->phone,
                        'country'=>'中国',
                        'province'=>$sendAddress->province,
                        'city'=>$sendAddress->city,
                        'county'=>$sendAddress->area,
                        'address'=>$sendAddress->address
                    ],
                    [
                        'contactType'=>2,
                        'tel'=>$consignAddress->phone,
                        'country'=>'中国',
                        'province'=>$consignAddress->province,
                        'city'=>$consignAddress->city,
                        'county'=>$consignAddress->area,
                        'address'=>$consignAddress->address
                    ]
                ],
                'filterType'=>1,
                'monthlyCard'=>$card
            ]
        ];
        $result=$this->SFPost('EXP_RECE_FILTER_ORDER_BSP',$json);
        WriteLog('顺丰筛单：'.json_encode($json,256)."|".json_encode($result,256));
        if($result['success']==false){
            $reson='请求失败[筛单失败]';
            $cacheInfo=[
                'reson'=>$reson,
                'result'=>false
            ];
            $this->setCacheKey($this->key,'fiterorder:result:shunfeng',$sendAddress,$consignAddress,$cacheInfo,3600);
            return false;
        }
        $data=$result['msgData'][0];
        $fiterResult=$data['filterResult'];
        if($fiterResult==2){
            $reson='可以收派';
            $cacheInfo=[
                'reson'=>$reson,
                'result'=>true
            ];
            $this->setCacheKey($this->key,'fiterorder:result:shunfeng',$sendAddress,$consignAddress,$cacheInfo,3600);
            return true;
        }
        if($fiterResult==3) $reson='顺丰反馈地址不可以收派，请联系顺丰检查地址是否异常';
        else if($fiterResult==4) $reson='地址不确定，请确认收货地址准确，一定要到乡镇级且有准确的地址信息，否则无法下单';
        $cacheInfo=[
            'reson'=>$reson,
            'result'=>false
        ];
        $this->setCacheKey($this->key,'fiterorder:result:shunfeng',$sendAddress,$consignAddress,$cacheInfo,3600);
        return false;
    }

    public function queryRoute($expressNo,$phone)
    {
        $expressResultTmp=Redis::basic()->get("sfexpressroute:{$expressNo}:{$phone}");
        if($expressResultTmp!=null){
            return $expressResultTmp;
        }
        $sphone=substr($phone,strlen($phone)-4,4);
        $json=[
            'trackingType'=>1,
            'trackingNumber'=>$expressNo,
            'methodType'=>1,
            'checkPhoneNo'=>$sphone
        ];
        $result=$this->SFPost2('https://bspgw.sf-express.com/std/service','EXP_RECE_SEARCH_ROUTES',$json);
        // dd($result);
        if($result==false){
            return [];
        }
        if($result['success']!=true){
            return [];
        }
        $expressResult=[];
        $list=$result['msgData']['routeResps'][0]['routes']??[];
        foreach($list as $iteminfo){
            $statusMap=OrderRouteLog::$statusMap;
            $statusMap=array_flip($statusMap);
            $opName=$this->opName($iteminfo['opCode']);
            if(!isset($statusMap[$opName])){
                WriteLog("收到顺丰路由无法识别操作码：".json_encode($iteminfo,256),'error');
            }
            $status=$statusMap[$opName]??41;
            $expressResult[]=[
                'city'=>$iteminfo['acceptAddress'],
                'city_code'=>Map::GetCityCode2($iteminfo['acceptAddress']),
                'time'=>$iteminfo['acceptTime'],
                'express_order'=>$expressNo,
                'content'=>$iteminfo['remark'],
                'contact'=>'',
                'phone'=>'',
                'opname'=>$opName,
                'display'=>'',
                'status'=> $status
            ];
        }
        Redis::basic()->set("sfexpressroute:{$expressNo}:{$phone}",$expressResult,1200);
        return $expressResult;
    }

    public function getOrderPrice($order)
    {
        $json=[
            'trackingType'=>2,
            'trackingNum'=>$order['other_num']
        ];
        $result=$this->SFPost2('https://bspgw.sf-express.com/std/service','EXP_RECE_QUERY_SFWAYBILL',$json);
        WriteLog('获取顺丰清单运费：'.json_encode($result));
        if($result==false){
            return false;
        }
        if($result['errorCode']!='S0000'){
            return false;
        }
        $msg=$result['msgData'];
        $fee=0;
        foreach($msg['waybillFeeList'] as $k=>$v){
            $fee+=$v['value'];
        }
        $expressinfo=$order['expressinfo'];
        $weight=$msg['waybillInfo']['meterageWeightQty'];
        $sendAddress=new Address($order['shipper_province'],$order['shipper_city'],$order['shipper_area'],$order['shipper_address'],$order['shipper_name'],$order['shipper_mobile']);
        $consignAddress=new Address($order['consignee_province'],$order['consignee_city'],$order['consignee_area'],$order['consignee_address'],$order['consignee_name'],$order['consignee_mobile']);
        $price=$this->getPriceLocal($order['express_company_id'],$expressinfo['expressGoods'],$order['shop_company_id'],$sendAddress,$consignAddress,$weight,$order['route_id']);
        if($price==false){
            $price=$fee;
        }
        LineOrder::query()->where('id',$order['id'])->where('status','<',2)->where('status','>=',0)->update([
            'status'=>5
        ]);
        OrderLog::InsertLog($order['id'],'收到计费：'.($price),1,OrderLog::TYPE_PRICE,'line');
        return array($price,$weight,$result);
    }

    public function getOrderPDF($expressNo)
    {
        $tmpCode='fm_150_standard_HYBKJmHF79QJ';
        $lineorder=LineOrder::query()->where('other_num',$expressNo)->first();
        $imgfile2=public_path('storage').'/line-orders/'. substr($lineorder['date'],2,6)."/".$lineorder['other_num'].".pdf";
        if(file_exists($imgfile2)){
            return $imgfile2;
        }
        $customData=[
            'orderno'=>'',
            'goods_num'=>'',
            'remark'=>''
        ];
        $cardinfo=OrderCardInfo::getOrderCardInfo($lineorder['id']);
        if($cardinfo==false){
            $cardinfo=[];
        }
        if($lineorder){
            $customData=[
                'orderno'=>$lineorder['no'],
                'goods_num'=>$lineorder['expire_info'],
                'remark'=>$lineorder['remark'],
                'senderremark'=>$cardinfo['remark']??''
            ];
        }
        $data=[
            'templateCode'=>$tmpCode,
            'documents'=>[
                'masterWaybillNo'=>$expressNo,
                'customData'=>$customData,
                'isPrintLogo'=>'true'
            ],
            'version'=>'2.0',
            'fileType'=>'pdf',
            'sync'=>true,
            'customTemplateCode'=>'fm_150_standard_custom_10008135507_1'
        ];
        $url='https://bspgw.sf-express.com/std/service';
        if($this->isDebug){
            $url='https://sfapi-sbox.sf-express.com/std/service';
        }
        $result=$this->SFPost2($url,'COM_RECE_CLOUD_PRINT_WAYBILLS',$data);
        if($result['success']!=true){
            return false;
        }
        foreach($result['obj']['files'] as $v){
            return $this->SFDownloadFile($expressNo,$v['url'],$v['token'],$lineorder);
        }
        return false;
    }

    private function SFDownloadFile($billno,$url,$token,$order){
        $path=public_path('storage')."/line-orders/".substr($order['date'],2,6)."/";
        if(!is_dir($path)){
            mkdir($path,0777,true);
            @chmod($path,0777);
        }
        $saveFile=$path.$billno.".pdf";
        Basic::downLoadFile($url,$saveFile,[
            'X-Auth-token:'.$token
        ]);
        if(file_exists($saveFile)){
            $imgfile2=public_path('storage').'/line-orders/'. substr($order['date'],2,6)."/";
            $result=pdf2png($saveFile,$imgfile2,210.4,212,5);
            $info=$result[0]??'';
            if($info!=''){
                rename($info,$imgfile2.$order['id'].".png");
            }
        }
        return $saveFile;
    }


    private function SFPost($serviceCode,$msgData){
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
        $result=$this->httpPost($this->host,$post_data);
        if($result['apiResultCode']!='A1000'){
            return false;
        }
        return json_decode($result['apiResultData'],true);
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
        // dd($post_data);
        $result=$this->httpPost($url,$post_data);
        if($result['apiResultCode']!='A1000'){
            return false;
        }
        return json_decode($result['apiResultData'],true);
    }

    public function getProduct()
    {
        $result=Redis::basic()->get('sf:productlist');
        if($result!=null){
            return $result;
        }
        $product=ExpressProduct::query()->where('express',$this->key)->orderBy('sort')->get();
        $result=[];
        foreach($product as $k=>$v){
            $result[$v['product']]=$v['name'];
        }
        Redis::basic()->set('sf:productlist',$result,1200);
        return $result;
    }

    public function expRoute($data, $cache = false)
    {
        if($cache){ // 刚刚推送过来，已进队列，按开发文档返回数据
            return array(
                'return_code'=>'0000',
                'return_msg'=>'成功'
            );
        }
        // $_SERVER['Real-IP']=$data['ip']??null;
        WriteLog('收到顺丰路由信息：'.json_encode($data,256),'info',$data['ip']??null);
        $data=$data['Body']['WaybillRoute'];
        foreach($data as $k=>$v){
            $orderId=$v['orderid'];
            $orderInfo=LineOrder::query()->where('no',$orderId)->first();

            $logStatus=41;
            $orderStatus=0;
            $opCode=intval($v['opCode']);
            switch($opCode){
                case 30:
                case 50:
                case 43:
                    $logStatus=41;
                    $orderStatus=4;
                    break;
                case 31:
                case 36:
                case 303:
                case 5000:
                case 95:
                case 407:
                case 197:
                case 408:
                case 603:
                case 501:
                case 106:
                case 105:
                    $logStatus=41;
                    $orderStatus=5;
                    break;
                case 3036:
                case 70:
                case 870:
                case 33:
                    $orderStatus=-1;
                    $logStatus=44;break;
                case 127:
                case 138:
                case 607:
                case 655:
                case 54:
                case 51:
                case 46:
                    $orderStatus=2;
                    $logStatus=40;break;
                case 678:
                    $orderStatus=6;
                    $logStatus=45;break;
                case 204:
                case 125:
                case 126:
                case 44:
                case 34:
                    $orderStatus=6;
                    $logStatus=42;break;
                case 99:
                case 648:
                case 77:
                    $orderStatus=-1;
                    $logStatus=43;break;
                case 657:
                    $logStatus=45;
                    $orderStatus=6;
                    break;
                case 1603:
                case 1635:
                    $logStatus=46;
                    $orderStatus=4;
                    break;
                case 935:
                case 97:
                case 80:
                case 679:
                case 677:
                case 658:
                case 128:
                case 80:
                case 8000:
                    $orderStatus=20;
                    LineOrder::query()->where('no',$orderId)->update([
                        'finish_at'=>$v['acceptTime']
                    ]);
                    $logStatus=100;break;
            }
            // dd($orderStatus);
            if($orderStatus!=0){
                $id=LineOrder::query()->where('no',$orderId)->value('id');
                if($id){
                    OrderLog::InsertLog($id,'推送状态:'.$orderStatus,1,OrderLog::TYPE_OTHER,'line');
                    LineOrder::query()->where('no',$orderId)->update([
                        'status'=>$orderStatus
                    ]);
                }
            }
            if(($orderInfo['opCode']??0)==648){  //产生新单号
                $newno=$orderInfo['newMailno']??null;
                if($newno==null){
                    continue;
                }
                LineOrder::query()->where('no',$orderId)->update([
                    'express_order'=>serialize([
                        'company'=>'shunfeng',
                        'order'=>$newno
                    ])
                ]);
            }
            OrderRouteLog::insertLog('express',$orderInfo['id'],$v['remark'],null,null,0,0,$logStatus,$v['acceptAddress'],strtotime($v['acceptTime']));
        }
    }

    public function expState($data, $cache = false)
    {
        if($cache){
            return array(
                'success'=>true,
                "code"=>0,
                'msg'=>'成功'
            );
        }
        foreach($data as $k=>$v){
            $ordernum=$v['orderNo']??'';
            if($ordernum==''){
                continue;
            }
            $orderInfo=LineOrder::query()->where('no',$ordernum)->first();
            if(!$orderInfo){
                continue;
            }
            OrderRouteLog::insertLog('express',$orderInfo['id'],$v['orderStateDesc'],"收件员工号:".$v['empCode'],$v['empPhone'],0,0,40,null,strtotime($v['lastTime']));
        }
    }

    public function expPrice($data, $cache = false)
    {
        if($cache){
            return array(
                'code'=>200
            );
        }
        // WriteLog('顺丰收到价格：'.json_encode($data,256));
        $data=$data['content'];
        if(is_string($data)){
            $data=json_decode($data,true);
        }
        $order=$data['orderNo'];
        $list=$data['feeList'];
        if(is_string($list)){
            $list=json_decode($list,true);
        }
        $total=0;
        foreach($list as $k=>$v){
            $total+=$v['feeAmt'];
        }
        $weight=$data['meterageWeightQty'];
        $lineOrder=LineOrder::query()->where('no',$order)->first();
        $price=$total;
        if($lineOrder){
            $city_code=Map::GetCityCode2($lineOrder['consignee_city']);
            if($city_code!=''&&$city_code!=false){
                $price=$this->getPriceLocalCode($lineOrder['express_company_id'],$lineOrder['expressinfo']['expressGoods']??266,$lineOrder['shop_company_id'],$city_code,$weight,$lineOrder['route_id']);
            }
            if($price==false){
                $price=$total;
            }
        }
        $weightArr=$this->getWeightArr($lineOrder,$weight);
        $line=LineOrder::query()->where('no',$order)->where('from_admin','express')->update([
            'weight'=>$data['meterageWeightQty'],
            'price'=>$price,
            'pricelog'=>serialize($data),
            'newprice'=>1,
            'volume'=>$data['volume']??0,
            'fee_time'=>getFeeTime('expressorder'),
            'weight_arr'=>json_encode($weightArr,256)
        ]);
        LineOrder::query()->where('no',$order)->where('status','<',2)->where('status','>=',0)->update([
            'status'=>5
        ]);
        LineOrder::query()->where('no',$order)->whereNull('receive_at')->update([
            'receive_at'=>nowDate()
        ]);
        WriteLog('顺丰收到价格：'.json_encode($data,256)."|".getFeeTime('expressorder')."|".$line,'info',$data['ip']??null);
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
        $data=[
            'type'=>2,
            'attributeNo'=>$order['other_num'],
            'checkPhoneNo'=>substr($phone,mb_strlen($phone)-4,4),
            'orderId'=>$order['no']
        ];
        $result=$this->SFPost('EXP_RECE_REGISTER_ROUTE',$data);
        WriteLog('注册顺丰订单：'.json_encode($data,256)."|".json_encode($result,256));
        if($result['success']){
            return true;
        }
        return $result['errorMsg'];
    }
}
