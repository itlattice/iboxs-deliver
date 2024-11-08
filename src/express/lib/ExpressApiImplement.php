<?php

namespace iboxs\deliver\express\lib;

interface ExpressApiImplement
{
    /**
     * 下单接口
     * @param int $order_id 订单ID
     * @return bool
     */
    public function sendOrder($order_id,&$reson);

    /**
     * 时效及价格查询
     * @param Address $sendAddress 发货地址
     * @param Address $consignAddress 收货地址
     * @param double $weight 重量
     * @param double $volume 体积
     * @param string $reson 失败原因
     * @return false|double
     */
    public function GetPrice(Address $sendAddress,Address $consignAddress,$weight,$volume,&$reson,$local=true);

    /**
     * 取消订单
     * @param \App\Models\LineOrder $order
     * @return bool
     */
    public function cancelOrder($order);

    /**
     * 筛单
     * @param \App\Models\User $user 下单用户
     * @param Address $sendAddress 发货信息
     * @param Address $consignAddress 收货信息
     * @param string $reson 原因
     * @return boolean 筛单结果
     */
    public function filterOrder($user,Address $sendAddress,Address $consignAddress,&$reson);

    /**
     * 查询路由信息
     * @param string $expressNo 快递单号
     * @param string $phone 收件人/发件人手机号
     * @return array|boolean 结果
     */
    public function queryRoute($expressNo,$phone);

    /**
     * 获取清单运费
     * @param \App\Models\LineOrder $order
     * @return false|double 运费信息，失败为false
     */
    public function getOrderPrice($order);

    /**
     * 云打印面单文件
     * @param string $expressNo 快递单号
     * @return false|string 文件地址，失败为false
     */
    public function getOrderPDF($expressNo);

    /**
     * 获取快递产品列表
     * @return array
     */
    public function getProduct();

    /**
     * 路由信息被推处理
     * @param $data
     * @param $cache
     * @return mixed
     */
    public function expRoute($data,$cache=false);

    /**
     * 订单状态被推处理
     * @param $data
     * @param $cache
     * @return mixed
     */
    public function expState($data,$cache=false);

    /**
     * 清单运费处理
     * @param $data
     * @param $cache
     * @return mixed
     */
    public function expPrice($data,$cache=false);

    /**
     * 注册路由接口
     * @param $line_order_id 干线订单ID
     * @return true|string 成功true，失败返回原因
     */
    public function regRoute($line_order_id);
}