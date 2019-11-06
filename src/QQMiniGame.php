<?php

namespace jinyicheng\tencent_minigame;

use BadFunctionCallException;
use Exception;
use InvalidArgumentException;
use jinyicheng\redis\Redis;
use think\Config;

class QQMiniGame extends Common
{

    private $options;
    private static $instance = [];

    /**
     * QQMiniGame constructor.
     * @param array $options
     */
    private function __construct($options = [])
    {
        $this->options = $options;
        if (!extension_loaded('redis')) throw new BadFunctionCallException('Redis扩展不支持');
    }

    /**
     * @return QQMiniGame
     */
    public static function getInstance()
    {
        $options = Config::get('tencent');
        if ($options === false || $options === []) throw new InvalidArgumentException('tencent配置不存在');
        if (!isset($options['app_id'])) throw new InvalidArgumentException('tencent配置下没有找到app_id设置');
        if (!isset($options['app_secret'])) throw new InvalidArgumentException('tencent配置下没有找到app_secret设置');
        if (!isset($options['app_token'])) throw new InvalidArgumentException('tencent配置下没有找到app_token设置');
        if (!isset($options['app_redis_cache_db_number'])) throw new InvalidArgumentException('tencent配置下没有找到app_redis_cache_db_number设置');
        if (!isset($options['app_redis_cache_key_prefix'])) throw new InvalidArgumentException('tencent配置下没有找到app_redis_cache_key_prefix设置');
        $hash = md5(json_encode($options));
        if (!isset(self::$instance[$hash])) {
            self::$instance[$hash] = new self($options);
        }
        return self::$instance[$hash];
    }

    /**
     * @return string
     * @throws Exception
     * @throws Exception
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
                2000
            );
            /**
             * 处理返回结果
             */
            //返回状态：不成功，抛出异常
            if ($getResult['errcode'] != 0) {
                throw new Exception($getResult['errmsg'], $getResult['errcode']);
            }
            //在redis中保存access_token
            $redis->set($access_token_key, $getResult['access_token'], $getResult['expires_in']);
            return $getResult['access_token'];
        }
    }

    /**
     * @param $js_code
     * @return array
     * @throws Exception
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
            2000
        );
        /**
         * 处理返回结果
         */
        //返回状态：不成功，抛出异常
        if ($getResult['errcode'] != 0) {
            throw new Exception($getResult['errmsg'], $getResult['errcode']);
        }
        return [
            'open_id' => $getResult['openid'],
            'session_key' => $getResult['session_key'],
            'union_id' => (isset($getResult['unionid'])) ? $getResult['unionid'] : ''
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
     * @throws Exception
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
            json_encode([
                'touser' => $open_id,
                'template_id' => $template_id,
                'page' => $page,
                'form_id' => $form_id,
                'data' => $data,
                'emphasis_keyword' => $emphasis_keyword
            ]),
            [
                'Content-Type:application/json;charset=utf-8'
            ],
            2000
        );
        //返回状态：不成功，抛出异常
        if ($postResult['errcode'] != 0) {
            throw new Exception($postResult['errmsg'], $postResult['errcode']);
        }
        return true;
    }
}