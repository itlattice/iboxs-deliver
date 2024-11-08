<?php

namespace iboxs\deliver\express\api;

use iboxs\deliver\express\lib\BaseExpress;
use iboxs\deliver\express\lib\ExpressApiImplement;

class NONE extends BaseExpress implements ExpressApiImplement
{
    public function sendOrder($order_id,&$reson)
    {
        $reson='';
        return true;
    }

    public function GetPrice(Address $sendAddress, Address $consignAddress, $weight, $volume, &$reson,$local=true)
    {
        $reson='不支持的快递';
        return 0;
    }

    public function cancelOrder($order)
    {
        return true;
    }

    public function filterOrder($user, Address $sendAddress, Address $consignAddress, &$reson)
    {
        return true;
    }

    public function getOrderPrice($order)
    {
        return array($order['price'],$order['weight'],'无快递编码');
    }

    public function queryRoute($expressNo, $phone)
    {
        return [];
    }

    public function getOrderPDF($expressNo): bool
    {
        return false;
    }

    public function getProduct(): array
    {
        return [
            'none'=>'快递运输'
        ];
    }

    public function expRoute($data, $cache = false)
    {
        // TODO: Implement expRoute() method.
    }

    public function expState($data, $cache = false)
    {
        // TODO: Implement expState() method.
    }

    public function expPrice($data, $cache = false)
    {
        // TODO: Implement expPrice() method.
    }

    public function regRoute($line_order_id)
    {
        return true;
    }
}
