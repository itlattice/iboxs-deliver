<?php

namespace iboxs\deliver\express\info;

/**
 * 地址信息
 */
class Address
{
    public $province;
    public $city;
    public $area;
    public $address;
    public $name;
    public $phone;
    public function __construct($province,$city,$area,$address,$name,$phone)
    {
        $this->province=$province;
        $this->city=$city;
        $this->area=$area;
        $this->address=$address;
        $this->name=$name;
        $this->phone=$phone;
    }
}
