<?php

namespace jinyicheng\tencent_minigame;

use Exception;

class Common
{
    /**
     * @param $url
     * @param $data
     * @param array $headers
     * @param int $timeout
     * @return array
     * @throws Exception
     */
    public static function post($url, $data, $headers = [], $timeout = 200)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);//抓取指定网页
        curl_setopt($ch, CURLOPT_POST, 1);//post提交方式
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);    //注意，毫秒超时一定要设置这个
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout); //超时时间200毫秒
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);//运行curl
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($httpCode!=200){
            throw new Exception('请求出错',$httpCode);
        }else{
            return json_decode($response, true);
        }
    }

    /**
     * @param $url
     * @param $data
     * @param array $headers
     * @param int $timeout
     * @return array
     * @throws Exception
     */
    public static function get($url, $data, $headers = [], $timeout = 200)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($data));//抓取指定网页
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);    //注意，毫秒超时一定要设置这个
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout); //超时时间200毫秒
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);//运行curl
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($httpCode!=200){
            throw new Exception('请求出错',$httpCode);
        }else{
            return json_decode($response, true);
        }
    }
}