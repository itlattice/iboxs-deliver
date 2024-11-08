<?php

namespace iboxs\deliver;

use iboxs\deliver\express\lib\ExpressApiImplement;

/**
 * 快递类接口
 * @see \iboxs\deliver\Express
 * @package ExpressApi
 * @method static ExpressApiImplement SF() 顺丰
 * @method static ExpressApiImplement DBK() 德邦
 * @method static ExpressApiImplement EMS() 邮政
 * @method static ExpressApiImplement ZTO() 中通
 * @method static ExpressApiImplement JD() 京东
 * @method static ExpressApiImplement SFL() 顺丰冷运
 */
class Express
{
    public static function __callStatic($name, $arguments)
    {
        $config=$arguments[0]??[];
        $name=self::$keyMap[$name]??$name;
        $class="\\iboxs\deliver\\express\\api\\{$name}";
        if(!class_exists($class)){
            $class='\\iboxs\deliver\\express\\api\\NONE'; // 快递编码异常不存在时执行该实例
        }
        return new $class($name,$config);
    }
}