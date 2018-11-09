<?php
/**
 * Created by PhpStorm.
 * User: rxw
 * Date: 17-9-16
 * Time: 下午8:48
 */
namespace framework\server;

use framework\base\Container;
use framework\process\ZookeeperProcess;

class HttpServer extends BaseServer
{
    protected $_urlInfo;

    protected function init()
    {
        $hasOnRequest = false;
        if (!$this->_server) {
            $hasOnRequest = true;
            $this->_server = new \swoole_http_server($this->_conf['ip'], $this->_conf['port'], $this->getValueFromConf('mode', SWOOLE_PROCESS));
        }

        parent::init(); // TODO: Change the autogenerated stub

        if ($hasOnRequest) {
            $this->onRequest();
        }
    }

    protected function execApp(&$response)
    {
        // TODO: Implement execApp() method.
        $container = Container::getInstance();
        $urlInfo = $container->getComponent(SYSTEM_APP_NAME, 'url')->run();
        MONITOR && $urlInfo !== false && $container->getComponent(SYSTEM_APP_NAME, 'monitor')::tick('http:'.$urlInfo['system'] . '_' . $urlInfo['controller'], $urlInfo['action']);
        
        $result = '';

        if ($urlInfo !== false) {
            $this->_urlInfo = $urlInfo;
            $_SERVER['CURRENT_SYSTEM'] = $urlInfo['system'];
            global $ALL_MODULES;
            $ALL_MODULES[$_SERVER['CURRENT_SYSTEM']] = true;
            
            // 初始化配置项
            if (!$container->appHasComponents($urlInfo['system'])) {
//                这里现在还缺少文件系统
                $appConf = require_file($urlInfo['system'] . '/conf/conf.php');
                $container->addComponents($urlInfo['system'], $appConf['addComponentsMap'] ?? []);
                $container->setAppComponents($urlInfo['system'] ,array(
                    'components' => $appConf['components'] ?? [],
                    'composer' => $appConf['composer'] ?? []
                ));
                unset($appConf);
            }

            $result = $container->getComponent(SYSTEM_APP_NAME, 'dispatcher')->run($urlInfo);
        } else {
            $this->_urlInfo = null;
            return FAVICON;
        }

        unset($container);
        return $result;
    }

    protected function onRequest()
    {
        $this->_server->on("request", function (\swoole_http_request $request,\swoole_http_response $response)
        {
            $GLOBALS['ERROR'] = false;
            $GLOBALS['EXCEPTION'] = false;
            if (DEBUG)
            {
                ob_start();
            }
            if ($this->_event)
            {
                $this->_event->onRequest($request,$response);
            }
            $container = Container::getInstance();
            if (!empty($request->get)) {
                $_GET = $request->get;
            }
            if (!empty($request->post)) {
                $_POST = $request->post;
            }
            if (!empty($request->files)) {
                $_FILES = $request->files;
//                $container->getComponent('upload')->save('file'); 上传文件测试
            }
            if (!empty($request->cookie)) {
                $_COOKIE = $request->cookie;
            }

            $result = '';
            $code = 500;
            $hasEnd = false;
            $success = false;
            try
            {
                $_SERVER['HTTP_HOST'] = $request->header['host'];
                foreach ($request->server as $key => $item)
                {
                    $_SERVER[strtoupper($key)] = $item;
                }
                unset($request->server);

                $result = $this->execApp($response);
                if (\is_array($result)) {
                    $result = json_encode($result);
                }
                $result != FAVICON && $container->getComponent(SYSTEM_APP_NAME, 'cookie')->send($response);
                
                if ($this->_event)
                {
                    $response->ret = $result;
                    $this->_event->onResponse($request,$response);
                }
                if (DEBUG)
                {
                    $elseContent = ob_get_clean();
                    if ($elseContent) {
                        if (is_array($elseContent)) {
                            $elseContent = json_encode($elseContent);
                        }
                        $result .= $elseContent;
                        unset($elseContent);
                    }
                }
                $success = true;
                $code = 200;
            }
            catch (\Throwable $exception)
            {
                $code = $exception->getCode() > 0 ? $exception->getCode() : 404;
                $this->handleThrowable($exception);
                if (DEBUG)
                {
                    $result = $exception->getMessage();
                    $elseContent = ob_get_clean();
                    if ($elseContent) {
                        if (is_array($elseContent)) {
                            $elseContent = json_encode($elseContent);
                        }
                        $result .= $elseContent;
                        unset($elseContent);
                    }
                }
            }
            
            if ($GLOBALS['EXCEPTION']) {
                DEBUG && $result .= $GLOBALS['EXCEPTION'];
                $container->getComponent(SYSTEM_APP_NAME, 'header')->setCode($code);
            }
            if ($GLOBALS['ERROR']) {
                DEBUG && $result .= $GLOBALS['ERROR'];
                $container->getComponent(SYSTEM_APP_NAME, 'header')->setCode($code);
            }
            
            try{
                MONITOR && $result != FAVICON && $container->getComponent(SYSTEM_APP_NAME, 'monitor')::report('http:'.$this->_urlInfo['system'] . '_' . $this->_urlInfo['controller'], $this->_urlInfo['action'], $success, $code, $result);
            }
            catch (\Throwable $exception)
            {
                $this->handleThrowable($exception);
            }
           
            $hasEnd = $container->getComponent(SYSTEM_APP_NAME, 'response')->send($response, $result);
            if (!$hasEnd) {
                $response->end();
            }
            $container->finish(\getModule());
            $container->finish(SYSTEM_APP_NAME);
            $_GET = [];
            $_POST = [];
            $_FILES = [];
            $_COOKIE = [];
            $_SERVER = [];
            unset($container,$request,$response);
        });
    }
}
