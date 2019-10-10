<?php

namespace tencent_minigame;

use BadFunctionCallException;
use HttpRequestException;
use HttpResponseException;
use InvalidArgumentException;
use think\Config;

class QQMiniGame extends Common
{

    private $options;
    private static $instance = [];

    private function __construct($options = [])
    {
        $this->options = $options;
        if (!extension_loaded('redis')) throw new BadFunctionCallException('Redis扩展不支持');
    }

    public static function getInstance()
    {
        if (!class_exists('Config', false)) {
            throw new BadFunctionCallException('ThinkPHP Config类不存在');
        } else {
            $options = Config::get('tencent');
            if ($options === false || $options === []) throw new InvalidArgumentException('tencent配置不存在');
            if (!isset($options['app_id'])) throw new InvalidArgumentException('tencent配置下没有找到app_id设置');
            if (!isset($options['app_secret'])) throw new InvalidArgumentException('tencent配置下没有找到app_secret设置');
            if (!isset($options['app_token'])) throw new InvalidArgumentException('tencent配置下没有找到app_token设置');
            if (!isset($options['app_redis_cache_db_number'])) throw new InvalidArgumentException('tencent配置下没有找到app_redis_cache_db_number设置');
            if (!isset($options['app_redis_cache_key_prefix'])) throw new InvalidArgumentException('tencent配置下没有找到app_redis_cache_key_prefix设置');
        }
        $hash = md5(json_encode($options));
        if (!isset(self::$instance[$hash])) {
            self::$instance[$hash] = new self($options);
        }
        return self::$instance[$hash];
    }

    /**
     * @return bool|string
     * @throws HttpRequestException
     * @throws HttpResponseException
     */
    public function getAccessToken()
    {
        /**
         * 尝试从redis中获取access_token
         */
        $redis = Redis::db($this->options['app_redis_cache_db_number']);
        $access_token_key = $this->options['app_redis_cache_key_prefix'] . ':access_token:' . $this->options['app_id'];
        $access_token = $redis->get($access_token_key);
        if ($access_token !== false) {
            return $access_token;
        } else {
            /**
             * 请求接口
             */
            $getResult = parent::get(
                "https://api.q.qq.com/api/getToken",
                [
                    'appid' => $this->options['app_id'],
                    'secret' => $this->options['app_secret'],
                    'grant_type' => 'client_credential'
                ],
                [],
                200
            );
            /**
             * 处理返回结果
             */
            //返回状态：不成功，抛出异常
            if ($getResult['errcode'] != 0) {
                throw new HttpResponseException($getResult['errmsg'], $getResult['errcode']);
            }
            //在redis中保存access_token
            $redis->set($access_token_key, $getResult['access_token'], $getResult['expires_in']);
            return $getResult['access_token'];
        }
    }

    /**
     * @param $js_code
     * @return bool|array
     * @throws HttpRequestException
     * @throws HttpResponseException
     */
    public function code2Session($js_code)
    {
        /**
         * 请求接口
         */
        $getResult = parent::get(
            "https://api.q.qq.com/sns/jscode2session",
            [
                'appid' => $this->options['app_id'],
                'secret' => $this->options['app_secret'],
                'js_code' => $js_code,
                'grant_type' => 'authorization_code'
            ],
            [],
            200
        );
        /**
         * 处理返回结果
         */
        //返回状态：不成功，抛出异常
        if ($getResult['errcode'] != 0) {
            throw new HttpResponseException($getResult['errmsg'], $getResult['errcode']);
        }
        return [
            'open_id' => $getResult['openid'],
            'session_key' => $getResult['session_key']
        ];
    }

    /**
     * @param $open_id
     * @param $template_id
     * @param $page
     * @param $form_id
     * @param $data
     * @param $emphasis_keyword
     * @return bool
     * @throws HttpRequestException
     * @throws HttpResponseException
     */
    public function sendTemplateMessage($open_id, $template_id, $page, $form_id, $data, $emphasis_keyword)
    {
        /**
         * 获取access_token
         */
        $access_token = $this->getAccessToken();
        /**
         * 请求接口
         */
        $postResult = parent::post(
            "https://api.q.qq.com/api/json/template/send?access_token=" . $access_token,
            [
                'touser' => $open_id,
                'template_id' => $template_id,
                'page' => $page,
                'form_id' => $form_id,
                'data' => $data,
                'emphasis_keyword' => $emphasis_keyword
            ],
            [],
            200
        );
        //返回状态：不成功，抛出异常
        if ($postResult['errcode'] != 0) {
            throw new HttpResponseException($postResult['errmsg'], $postResult['errcode']);
        }
        return true;
    }
}