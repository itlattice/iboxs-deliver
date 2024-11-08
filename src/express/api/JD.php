<?php

namespace iboxs\deliver\express\api;

use iboxs\deliver\express\lib\BaseExpress;
use iboxs\deliver\express\lib\ExpressApiImplement;

class JD extends BaseExpress implements ExpressApiImplement
{
    public function setConfig($config) {

    }
    public function sendOrder($order_id, &$reson)
    {

    }

    public function GetPrice(Address $sendAddress, Address $consignAddress, $weight, $volume, &$reson, $local = true)
    {
        // TODO: Implement GetPrice() method.
    }

    public function cancelOrder($order)
    {
        // TODO: Implement cancelOrder() method.
    }

    public function filterOrder($user, Address $sendAddress, Address $consignAddress, &$reson)
    {
        // TODO: Implement filterOrder() method.
    }

    public function queryRoute($expressNo, $phone)
    {
        // TODO: Implement queryRoute() method.
    }

    public function getOrderPrice($order)
    {
        // TODO: Implement getOrderPrice() method.
    }

    public function getOrderPDF($expressNo)
    {
        // TODO: Implement getOrderPDF() method.
    }

    public function getProduct()
    {
        // TODO: Implement getProduct() method.
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
        // TODO: Implement regRoute() method.
    }
}
