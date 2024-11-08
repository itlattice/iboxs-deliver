<?php

namespace iboxs\deliver\express\orderCard;

class ZTO
{
    public function Main($order,$file){
        if(!file_exists($file)){
            return $file;
        }
        $bgimg = $file;//背景图
        $bg_info = getimagesize($bgimg);
        $bg_type = image_type_to_extension($bg_info[2], false);
        $wryh = resource_path('font')."/msyh.ttf"; //微软雅黑字体
        $dengxian = resource_path('font')."/dengxian.ttf"; //等线
        $fangsong = resource_path('font')."/fangsong.ttf";  //仿宋
        $heiti=resource_path('font')."/heiti.TTF"; //黑体
        $func = 'imagecreatefrom' . $bg_type;
        $bg_image = $func($bgimg);

        $remark=$order['remark']??'';
        $expireInfo=$order['expire_info'];
        $product=$order['expressinfo']['expressGoods']??false;
        if($product=='zhongtong'||$product==false||$product=='LEN'){
            $product='冷链';
        } else{
            $product='航空';
        }
        if($remark!=''){
            imagettftext($bg_image, 80, 0, 150, 3200, 0, $heiti, '客户备注:'.$remark);
        }
        $cardinfo=OrderCardInfo::getOrderCardInfo($order['id']);
        if($cardinfo==false){
            $cardinfo=[];
        }
        imagettftext($bg_image, 80, 0, 150, 3200, 0, $heiti, $cardinfo['remark']??'');
        imagettftext($bg_image, 80, 0, 150, 3080, 0, $heiti, '运输方式:'.$product);
        imagettftext($bg_image, 60, 0, 150, 3480, 0, $heiti, $order['no']);
        $x=1800-mb_strlen($expireInfo)*130;
        imagettftext($bg_image, 220, 0, $x, 3400, 0, $heiti, $expireInfo);

        $date=substr($order['date'],2,6);
        $pathUrl="/line-orders/".$date;

        $path= ZTO . phppublic_path('storage') . $pathUrl;
        if(!is_dir($path)){
            @mkdir($path,0777,true);
            chmod($path,0777);
        }
        $name=$order['id'].".png";
        $img_name = $path."/". $name;

        // $img_name=public_path()."/print.png";
        $outFunc = 'image' . $bg_type;
        $outFunc($bg_image, $img_name);
        imagedestroy($bg_image);
        return true;
    }
}