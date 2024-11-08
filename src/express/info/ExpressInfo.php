<?php

namespace iboxs\deliver\express\info;

class ExpressInfo
{
    /**
     * 寄付
     */
    const PAYMODE_SOLIC=0;
    /**
     * 到付
     */
    const PAYMODE_PICK=1;

    public static $SFPayMode=[
        self::PAYMODE_SOLIC=>1,
        self::PAYMODE_PICK=>2
    ];

    public static $SFLYPayMode=[
        self::PAYMODE_SOLIC=>'PR_ACCOUNT',
        self::PAYMODE_PICK=>'CC_CASH'
    ];

    public static $DBKPayMode=[
        self::PAYMODE_SOLIC=>2,
        self::PAYMODE_PICK=>1
    ];

    public static $EMSPayMode = [
        self::PAYMODE_SOLIC => 1,
        self::PAYMODE_PICK => 2
    ];
}
