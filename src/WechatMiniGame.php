<?php

namespace jinyicheng\tencent_minigame;

use BadFunctionCallException;
use Exception;
use HttpRequestException;
use HttpResponseException;
use InvalidArgumentException;
use OSS\Core\OssException;
use OSS\OssClient;
use think\Config;
use UnexpectedValueException;

class WechatMiniGame extends Common
{

    private $options;
    private static $instance = [];

    /**
     * WechatMiniGame constructor.
     * @param array $options
     */
    private function __construct($options = [])
    {
        $this->options = $options;
        if (!extension_loaded('redis')) throw new BadFunctionCallException('Redis扩展不支持');
    }

    /**
     * @return WechatMiniGame
     */
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
            if (!isset($options['app_qrcode_cache_type'])) throw new InvalidArgumentException('tencent配置下没有找到app_qrcode_cache_type设置');
            if (in_array($options['app_qrcode_cache_type'], ['oss', 'local'])) throw new InvalidArgumentException('tencent配置下app_qrcode_cache_type参数无效仅支持：oss或local');
            if ($options['app_qrcode_cache_type'] == 'oss') {
                if (!class_exists('OssClient', false)) throw new BadFunctionCallException('OSS OssClient类不存在');
                if (!isset($options['app_qrcode_cache_oss_access_key_id'])) throw new InvalidArgumentException('tencent配置下没有找到app_qrcode_cache_oss_access_key_id设置');
                if (!isset($options['app_qrcode_cache_oss_access_key_secret'])) throw new InvalidArgumentException('tencent配置下没有找到app_qrcode_cache_oss_access_key_secret设置');
                if (!isset($options['app_qrcode_cache_oss_end_point'])) throw new InvalidArgumentException('tencent配置下没有找到app_qrcode_cache_oss_end_point设置');
                if (!isset($options['app_qrcode_cache_oss_bucket'])) throw new InvalidArgumentException('tencent配置下没有找到app_qrcode_cache_oss_bucket设置');
            }
            if (!isset($options['app_qrcode_cache_path'])) throw new InvalidArgumentException('tencent配置下没有找到app_qrcode_cache_path设置');
            if (!is_dir($options['app_qrcode_cache_real_dir_path'])) throw new InvalidArgumentException('tencent配置下app_qrcode_cache_real_dir_path路径无效');
            if (!isset($options['app_qrcode_cache_relative_dir_path'])) throw new InvalidArgumentException('tencent配置下app_qrcode_cache_relative_dir_path路径无效');
            if (!isset($options['app_qrcode_request_url_prefix'])) throw new InvalidArgumentException('tencent配置下没有找到app_qrcode_request_url_prefix设置');
        }
        $hash = md5(json_encode($options));
        if (!isset(self::$instance[$hash])) {
            self::$instance[$hash] = new self($options);
        }
        return self::$instance[$hash];
    }

    /**
     * @return string
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
            $getResult = self::get(
                "https://api.weixin.qq.com/cgi-bin/token",
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
     * @param $open_id
     * @param $session_key
     * @return bool
     * @throws HttpRequestException
     * @throws HttpResponseException
     */
    public function checkSessionKey($open_id, $session_key)
    {
        $access_token = $this->getAccessToken();
        /**
         * 请求接口
         */
        $getResult = self::get(
            "https://api.weixin.qq.com/wxa/checksession",
            [
                'access_token' => $access_token,
                'openid' => $open_id,
                'signature' => hash_hmac('sha256', $session_key, $this->options['app_secret']),
                'sig_method' => 'hmac_sha256'
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
        return true;
    }

    /**
     * @param $js_code
     * @return array
     * @throws HttpRequestException
     * @throws HttpResponseException
     */
    public function code2Session($js_code)
    {
        /**
         * 请求接口
         */
        $getResult = self::get(
            "https://api.weixin.qq.com/sns/jscode2session",
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
            'session_key' => $getResult['session_key'],
            'union_id' => isset($getResult['unionid']) ? $getResult['unionid'] : null
        ];
    }

    /**
     * @param $open_id
     * @param $template_id
     * @param $page
     * @param $data
     * @return bool
     * @throws HttpRequestException
     * @throws HttpResponseException
     */
    public function sendTemplateMessage($open_id, $template_id, $page, $data)
    {
        /**
         * 获取access_token
         */
        $access_token = $this->getAccessToken();
        /**
         * 请求接口
         */
        $postResult = parent::post(
            "https://api.weixin.qq.com/cgi-bin/message/subscribe/send?access_token=" . $access_token,
            [
                'touser' => $open_id,
                'template_id' => $template_id,
                'page' => $page,
                'data' => $data
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

    /**
     * @param string $path
     * @param int $width
     * @param bool $auto_color
     * @param array $line_color
     * @param bool $is_hyaline
     * @return string
     * @throws HttpRequestException
     * @throws HttpResponseException
     * @throws Exception
     */
    public function getQRCode($path, $width = 430, $auto_color = false, $line_color = ["r" => 0, "g" => 0, "b" => 0], $is_hyaline = false)
    {
        /**
         * 获取access_token
         */
        $access_token = $this->getAccessToken();
        /**
         * 请求接口
         */
        $postResult = self::post(
            "https://api.weixin.qq.com/wxa/getwxacode?access_token=" . $access_token,
            [
                'path' => $path,
                'width' => (int)$width,
                'auto_color' => (bool)$auto_color,
                'line_color' => $line_color,
                'is_hyaline' => (bool)$is_hyaline
            ],
            [],
            200
        );
        //返回状态：不成功，抛出异常
        if ($postResult['errcode'] != 0) {
            throw new HttpResponseException($postResult['errmsg'], $postResult['errcode']);
        }
        switch ($postResult['contentType']) {
            case 'image/jpeg':
                $ext = '.jpg';
                break;
            case 'image/png':
            case 'application/x-png':
                $ext = 'png';
                break;
            case 'image/gif':
                $ext = '.gif';
                break;
            case 'image/vnd.wap.wbmp':
                $ext = '.wbmp';
                break;
            case 'image/x-icon':
                $ext = '.ico';
                break;
            case 'image/vnd.rn-realpix':
                $ext = '.rp';
                break;
            case 'image/tiff':
                $ext = '.tiff';
                break;
            case 'image/pnetvue':
                $ext = '.net';
                break;
            case 'image/fax':
                $ext = '.fax';
                break;
            default:
                throw new UnexpectedValueException('未知类型文件' . $postResult['contentType'] . '无法确定存储文件后缀');
        }
        $filename = md5($postResult['buffer']) . $ext;
        $real_file_path = $this->options['app_qrcode_cache_real_dir_path'] . DIRECTORY_SEPARATOR . $filename;
        $relative_file_path = $this->options['app_qrcode_cache_relative_dir_path'] . DIRECTORY_SEPARATOR . $filename;
        switch ($this->options['app_qrcode_cache_type']) {
            case 'oss':
                /**
                 * 执行本地文件至oss的迁移
                 */
                $ossClient = new OssClient(
                    $this->options['access_key_id'],
                    $this->options['access_key_secret'],
                    $this->options['end_point']
                );
                $ossClient->putObject($this->config['bucket'], $relative_file_path, $postResult['buffer']);
                break;
            case 'local':
                if (file_put_contents($real_file_path, $postResult['buffer']) === false) {
                    throw new Exception('文件：' . $real_file_path . '写入失败');
                }
                break;
        }
        return $this->options['app_qrcode_request_url_prefix'] . DIRECTORY_SEPARATOR . $relative_file_path;
    }
}