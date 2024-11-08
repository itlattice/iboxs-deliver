<?php

namespace iboxs\deliver\express\orderCard;

class DBK
{
    private $productMap=[
        'HKDJC'=>'航空次日',
        'HKDJG'=>'航空隔日',
        'TZKJC'=>'特快专递',
        'DEAP'=>'特快冷链'
    ];

    private $productMap2=[
        'HKDJC'=>'空运',
        'HKDJG'=>'空运',
        'TZKJC'=>'专递',
        'DEAP'=>'冷链'
    ];

    public function Main($order){
        $bgimg = resource_path('image').'/debang.png';//背景图
        $bg_info = getimagesize($bgimg);
        $bg_type = image_type_to_extension($bg_info[2], false);
        $wryh = resource_path('font')."/msyh.ttf"; //微软雅黑字体
        $dengxian = resource_path('font')."/dengxian.ttf"; //等线
        $fangsong = resource_path('font')."/fangsong.ttf";  //仿宋
        $heiti=resource_path('font')."/heiti.TTF"; //黑体
        $func = 'imagecreatefrom' . $bg_type;
        $bg_image = $func($bgimg);
        $pay_mode=$order['pay_mode']=='collect'?'到付':'月结';
        imagettftext($bg_image, 30, 0, 250, 80, 0, $heiti, $pay_mode);
        $product=$order['expressinfo']['expressGoods']??'HKDJG';
        $product=$this->productMap[$product]??(Express::DBK()->getProduct()[$product]??'航空次日');
        $product=chunk_split($product,6);
        imagettftext($bg_image, 23, 0, 420, 32, 0, $heiti, $product);
        if($order['express_return']==null){
            return false;
        }
        if(is_string($order['express_return'])){
            $order['express_return']=unserialize($order['express_return']);
        }
        $arrayName=$order['express_return']['arrivedOrgSimpleName']??'';
        if($arrayName==''){
            return false;
        }
        imagettftext($bg_image, 37, 0, 30, 150, 0, $heiti, $arrayName);
        imagettftext($bg_image, 37, 0, 30, 151, 0, $heiti, $arrayName);
        // dd($product);

        $area=$order['consignee_area'];
        imagettftext($bg_image, 28, 0, 120, 248, 0, $heiti, $area);
        imagettftext($bg_image, 28, 0, 420, 300, 0, $heiti, '1');

        list($barcode,$barcodeWidth)=BarCode($order['other_num'],2.4,100);
        imagecopy($bg_image, $barcode, 30, 320, 0, 0, 346, 60);
        imagettftext($bg_image, 23, 0, 60, 415, 0, $heiti, $order['other_num']);

        $address=$order['consignee_province'].$order['consignee_city'].$order['consignee_area'].$order['consignee_address'];
        if(mb_strlen($address)>38){
            $address=mb_substr($address,0,38);
        }
        $consignee=$order['consignee_name'].PHP_EOL.
            Basic::phoneHandle($order['consignee_mobile']).PHP_EOL.
            mb_wordwrap($address,19);
        imagettftext($bg_image, 18, 0, 60, 455, 0, $heiti, $consignee);
        $sender=$order['shipper_name']." ".Basic::phoneHandle($order['shipper_mobile'])."/**** ****";
        imagettftext($bg_image, 17, 0, 30, 618, 0, $heiti, $sender);

        $goods=$order['goods']??'鲜花';
        imagettftext($bg_image, 17, 0, 70, 695, 0, $heiti, $goods);
        $remark=$order['remark']??'';
        if(mb_strlen($remark)>38){
            $remark=mb_substr($remark,0,38);
        }
        $remark= mb_wordwrap($remark,19);
        imagettftext($bg_image, 17, 0, 70, 770, 0, $heiti, $remark);

        $info=$order['expire_info']??'';
        if($info!=''){
            imagettftext($bg_image, 30, 0, 28, 850, 0, $wryh, $info);
            imagettftext($bg_image, 30, 0, 29, 851, 0, $wryh, $info);
        }
        imagettftext($bg_image, 17, 0, 130, 885, 0, $wryh, $order['no']);
        $product=$this->productMap2[$product]??(Express::DBK()->getProduct()[$product]??'空运');
        imagettftext($bg_image, 15, 0, 232, 925, 0, $wryh, $product);

        $cardinfo=OrderCardInfo::getOrderCardInfo($order['id']);
        if($cardinfo==false){
            $cardinfo=[];
        }
        imagettftext($bg_image, 20, 0, 10, 850, 0, $heiti, $cardinfo['remark']??'');

        $count=LineOrder::query()->where('id','<',$order['id'])->where('date',$order['date'])->where('express_company_id',1483)->count();
        $count++;
        $count= str_pad($count, 4, '0', STR_PAD_LEFT);
        $str=date('Y-m-d H:i:s')." 第{$count}单";
        imagettftext($bg_image, 15, 0, 5, 1000, 0, $wryh, $str);

        $date=substr($order['date'],2,6);
        $pathUrl="/line-orders/".$date;
        $path= DBK . phppublic_path('storage') . $pathUrl;
        if(!is_dir($path)){
            @mkdir($path,0777,true);
            chmod($path,0777);
        }
        $name=$order['id'].".png";
        $img_name = $path."/". $name;
        $outFunc = 'image' . $bg_type;
        $outFunc($bg_image, $img_name);
        imagedestroy($bg_image);
        $path= public_path('storage/line-orders') . "DBK.php/" .$date.'/';
        if(!is_dir($path)){
            mkdir($path,0777,true);
            @chmod($path,0777);
        }
        $file= public_path('storage/line-orders') . "DBK.php/" .$date.'/'.$order['other_num'].".pdf";
        if(file_exists($img_name)){
            $imgfile2=public_path('storage').'/line-orders/'. substr($order['date'],2,6)."/";
            $save=$imgfile2.$order['id'].".png";
            if(!file_exists($save)){
                rename($img_name,$save);
                $img_name=$save;
            }
            grayImg($save,$save);
        }
        return png2pdf([$img_name],$file);
    }
}