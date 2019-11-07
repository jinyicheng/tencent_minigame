<?php

namespace jinyicheng\tencent_minigame;

use BadFunctionCallException;
use Exception;
use InvalidArgumentException;
use jinyicheng\redis\Redis;
use OSS\Core\OssException;
use OSS\OssClient;
use think\Config;
use UnexpectedValueException;

class TouTiaoMiniGame extends Common
{

    private $options;
    private static $instance = [];

    /**
     * TouTiaoMiniGame constructor.
     * @param array $options
     */
    private function __construct($options = [])
    {
        $this->options = $options;
        if (!extension_loaded('redis')) throw new BadFunctionCallException('Redis扩展不支持');
    }

    /**
     * @return TouTiaoMiniGame
     */
    public static function getInstance()
    {
        $options = Config::get('tencent');
        if ($options === false || $options === []) throw new InvalidArgumentException('tencent配置不存在');
        if (!isset($options['app_id'])) throw new InvalidArgumentException('tencent配置下没有找到app_id设置');
        if (!isset($options['app_secret'])) throw new InvalidArgumentException('tencent配置下没有找到app_secret设置');
        if (!isset($options['app_redis_cache_db_number'])) throw new InvalidArgumentException('tencent配置下没有找到app_redis_cache_db_number设置');
        if (!isset($options['app_redis_cache_key_prefix'])) throw new InvalidArgumentException('tencent配置下没有找到app_redis_cache_key_prefix设置');
        if (!isset($options['app_qrcode_cache_type'])) throw new InvalidArgumentException('tencent配置下没有找到app_qrcode_cache_type设置');
        if (!in_array($options['app_qrcode_cache_type'], ['oss', 'local'])) throw new InvalidArgumentException('tencent配置下app_qrcode_cache_type参数无效仅支持：oss或local');
        if ($options['app_qrcode_cache_type'] == 'oss') {
            if(!Config::has('oss'))throw new InvalidArgumentException('oss配置不存在');
            $options=array_merge($options,Config::get('oss'));
            if (!isset($options['access_key_id'])) throw new InvalidArgumentException('oss配置下没有找到access_key_id设置');
            if (!isset($options['access_key_secret'])) throw new InvalidArgumentException('oss配置下没有找到access_key_secret设置');
            if (!isset($options['end_point'])) throw new InvalidArgumentException('oss配置下没有找到end_point设置');
            if (!isset($options['bucket'])) throw new InvalidArgumentException('oss配置下没有找到bucket设置');
        }
        if (!is_dir($options['app_qrcode_cache_real_dir_path'])) throw new InvalidArgumentException('tencent配置下app_qrcode_cache_real_dir_path路径无效');
        if (!isset($options['app_qrcode_cache_relative_dir_path'])) throw new InvalidArgumentException('tencent配置下app_qrcode_cache_relative_dir_path路径无效');
        if (!isset($options['app_qrcode_request_url_prefix'])) throw new InvalidArgumentException('tencent配置下没有找到app_qrcode_request_url_prefix设置');
        /** @var array $oss_conf */

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
                "https://developer.toutiao.com/api/apps/token",
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
     * @param $anonymous_code
     * @return array
     * @throws Exception
     */
    public function code2Session($js_code, $anonymous_code)
    {
        /**
         * 请求接口
         */
        $getResult = parent::get(
            "https://developer.toutiao.com/api/apps/jscode2session",
            ($js_code != '') ? [
                'appid' => $this->options['app_id'],
                'secret' => $this->options['app_secret'],
                'code' => $js_code
            ] : [
                'appid' => $this->options['app_id'],
                'secret' => $this->options['app_secret'],
                'anonymous_code' => $anonymous_code
            ],
            [],
            2000
        );
        /**
         * 处理返回结果
         */
        //返回状态：不成功，抛出异常
        if ($getResult['error'] != 0) {
            throw new Exception($getResult['errmsg'], $getResult['error']);
        }
        return [
            'openid' => (isset($getResult['openid'])) ? $getResult['openid'] : '',
            'anonymous_open_id' => (isset($getResult['anonymous_openid'])) ? $getResult['anonymous_openid'] : '',
            'session_key' => $getResult['session_key']
        ];
    }

    /**
     * @param $open_id
     * @param $template_id
     * @param $page
     * @param $form_id
     * @param $data
     * @return bool
     * @throws Exception
     */
    public function sendTemplateMessage($open_id, $template_id, $page, $form_id, $data)
    {
        /**
         * 请求接口
         */
        $postResult = parent::post(
            "https://developer.toutiao.com/api/apps/game/template/send",
            json_encode([
                'access_token' => $this->getAccessToken(),
                'touser' => $open_id,
                'template_id' => $template_id,
                'page' => $page,
                'form_id' => $form_id,
                'data' => $data
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

    /**
     * @param string $path
     * @param string $app_name
     * 对应字节系app_name
     * toutiao：今日头条
     * douyin：抖音
     * pipixia：皮皮虾
     * huoshan：火山小视频
     * @param int $width
     * @param bool $auto_color
     * @param array $line_color
     * @param array $background
     * @param bool $set_icon
     * @return string
     * @throws Exception
     */
    public function createQRCode($path, $app_name = 'toutiao', $width = 430, $auto_color = false, $line_color = ["r" => 0, "g" => 0, "b" => 0], $background = ["r" => 255, "g" => 255, "b" => 255], $set_icon = false)
    {
        /**
         * 请求接口
         */
        $postResult = self::post(
            "https://developer.toutiao.com/api/apps/qrcode",
            [
                'access_token' => $this->getAccessToken(),
                'appname' => $app_name,
                'path' => $path,
                'width' => (int)$width,
                'line_color' => $line_color,
                'background' => $background,
                'set_icon' => (bool)$set_icon
            ],
            [],
            2000
        );
        //返回状态：不成功，抛出异常
        if ($postResult['errcode'] != 0) {
            throw new Exception($postResult['errmsg'], $postResult['errcode']);
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

        $relative_file_path = $this->options['app_qrcode_cache_relative_dir_path'] . DIRECTORY_SEPARATOR . $filename;
        switch ($this->options['app_qrcode_cache_type']) {
            case 'oss':
                /**
                 * 执行数据量到oss的远程文件生成
                 */
                $ossClient = new OssClient(
                    $this->options['access_key_id'],
                    $this->options['access_key_secret'],
                    $this->options['end_point']
                );
                $ossClient->putObject($this->config['bucket'], $relative_file_path, $postResult['buffer']);
                break;
            case 'local':
                /**
                 * 执行数据流到本地文件的生成
                 */
                $real_file_path = $this->options['app_qrcode_cache_real_dir_path'] . DIRECTORY_SEPARATOR . $filename;
                if (file_put_contents($real_file_path, $postResult['buffer']) === false) {
                    throw new Exception('文件：' . $real_file_path . '写入失败');
                }
                break;
        }
        return $this->options['app_qrcode_request_url_prefix'] . DIRECTORY_SEPARATOR . $relative_file_path;
    }
}