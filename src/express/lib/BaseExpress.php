<?php

namespace iboxs\deliver\express\lib;

class BaseExpress
{
    /**
     * 快递公司编码
     */
    protected $key;
    /**
     * 配置信息
     */
    protected $config;

    public function __construct($key,$config)
    {
        $this->key=$key;
        $this->initialize($config);
        $this->config=$config;
        $this->setConfig($config);
    }

    protected function initialize(&$config)
    {

    }

    public function setConfig($config){

    }
}
