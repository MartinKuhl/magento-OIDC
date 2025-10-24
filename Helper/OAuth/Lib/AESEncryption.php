<?php

namespace MiniOrange\OAuth\Helper\OAuth\Lib;

/**
 * @package    miniOrange
 * @author     miniOrange Security Software Pvt. Ltd.
 * @license    GNU/GPLv3
 * @copyright  Copyright 2015 miniOrange. All Rights Reserved.
 *
 *
 * This file is part of miniOrange plugin.
 */
class AESEncryption 
{
	 public static function encrypt_data($string, $pass)
    {
        $result = '';
        for($i = 0; $i < strlen($string); $i++) {
            $char = substr($string, $i, 1);
            $keychar = substr($pass, ($i % strlen($pass))-1, 1);
            $char = chr(ord($char) + ord($keychar));
            $result .= $char;
        }

        return base64_encode($result);
    }
    public static function decrypt_data($string, $pass)
    {
        $result = '';
        $string = base64_decode($string);

        for($i = 0; $i < strlen($string); $i++) {
            $char = substr($string, $i, 1);
            $keychar = substr($pass, ($i % strlen($pass))-1, 1);
            $char = chr(ord($char) - ord($keychar));
            $result .= $char;
        }

        return $result;
    }
}