<?php
/**
 * Created by PhpStorm.
 * User: rxw
 * Date: 17-9-16
 * Time: 下午8:54
 */
namespace framework\server;

use framework\base\Container;

class WebSocketServer extends HttpServer
{
    protected $_prePushList = [];
    protected $_fd;

    protected function init()
    {
        $this->_server = new  \swoole_websocket_server($this->_conf['ip'], $this->_conf['port']);
        parent::init(); // TODO: Change the autogenerated stub
        $this->onHandShake();
        if ($this->getValueFromConf('supportHttp', false)) {
            $this->onRequest();
        }
        $this->onMessage();
    }

    public function disConnect($fd)
    {
        if ($this->_server->exist($fd)) {
            $this->_server->disconnect($fd);
        }
    }
 
    public function push($fd, $data, $now = false)
    {
        $data = \is_array($data) ? \json_encode($data) : $data;
        if ($now) {
            $this->_server->push($fd,$data);
        } else {
            $this->_prePushList[] = [
                'fd' => $fd,
                'data' => $data
            ];
        }
    }

    protected function pushAll()
    {
        foreach ($this->_prePushList as $key => $value) {
            # code...
            $this->_server->push($value['fd'],$value['data']);
        }
        $this->_prePushList = [];
    }

    public function fd()
    {
        return $this->_fd;
    }
 
    protected function onHandShake()
    {
        $this->_server->on('handshake', function (\swoole_http_request $request, \swoole_http_response $response)
        {
            $GLOBALS['ERROR'] = false;
            $GLOBALS['EXCEPTION'] = false;
            global $FD_SYSTEM;
            try {
                if (!isset($request->header['sec-websocket-key']))
                {
                    //'Bad protocol implementation: it is not RFC6455.'
                    $response->end();
                    return false;
                }
                if (0 === preg_match('#^[+/0-9A-Za-z]{21}[AQgw]==$#', $request->header['sec-websocket-key'])
                    || 16 !== strlen(base64_decode($request->header['sec-websocket-key']))
                )
                {
                    //Header Sec-WebSocket-Key is illegal;
                    $response->end();
                    return false;
                }

                foreach ($request->server as $key => $item)
                {
                    $_SERVER[strtoupper($key)] = $item;
                }
                unset($request->server);

                $container = Container::getInstance();
                $pathInfo = $container->getComponent(SYSTEM_APP_NAME, 'url')->run();
                $_SERVER['CURRENT_SYSTEM'] = $pathInfo['system'];
                $FD_SYSTEM[$request->fd] = $pathInfo['system'];

                if (!$container->appHasComponents($pathInfo['system'])) {
                    $appConf = require_file($pathInfo['system'] . '/conf/conf.php');
                    $container->addComponents($pathInfo['system'], $appConf['addComponentsMap'] ?? []);
                    $container->setAppComponents($pathInfo['system'] ,[
                        'components' => $appConf['components'] ?? [],
                        'composer' => $appConf['composer'] ?? []
                    ]);
                }

                if ($this->_event) {
                    $result = $this->_event->onHandShake($request, $response);
                    if (!$result) {
                        $_SERVER = [];
                        $response->end();
                        return false;
                    }
                }

                $key = base64_encode(sha1($request->header['sec-websocket-key']
                    . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
                    true));
                $headers = array(
                    'Upgrade'               => 'websocket',
                    'Connection'            => 'Upgrade',
                    'Sec-WebSocket-Accept'  => $key,
                    'Sec-WebSocket-Version' => '13',
                    'KeepAlive'             => 'off',
                );
                foreach ($headers as $key => $val)
                {
                    $response->header($key, $val);
                }
                $response->status(101);
                $response->end();

            } catch (\Throwable $e) {
                $this->handleThrowable($e);
                $_SERVER = [];
                if (!empty($FD_SYSTEM[$request->fd])) unset($FD_SYSTEM[$request->fd]);
                $response->end();
                return false;
            }

            if ($GLOBALS['EXCEPTION'] || $GLOBALS['ERROR']) {
                $_SERVER = [];
                if (!empty($FD_SYSTEM[$request->fd])) unset($FD_SYSTEM[$request->fd]);
                $response->end();
                return false;
            }
            
            return true;
        });
    }

    protected function onMessage()
    {
        $this->_server->on('message', function (\swoole_websocket_server $server, $frame)
        {
            $GLOBALS['ERROR'] = false;
            $GLOBALS['EXCEPTION'] = false;
//            目前不支持过大消息和二进制数据
            if (!$frame->finish || $frame->opcode !== WEBSOCKET_OPCODE_TEXT) {
                $server->push($frame->fd, '');
                return false;
            }
            if (DEBUG)
            {
                ob_start();
            }
            $frame->data = json_decode($frame->data, true);

            if (empty($frame->data['controller']) || empty($frame->data['action'])) {
                $server->push($frame->fd, 'bad request');
                return false;
            }

            global $ALL_MODULES, $FD_SYSTEM;
            $_SERVER['CURRENT_SYSTEM'] = $FD_SYSTEM[$frame->fd];
            $frame->data['system'] = $FD_SYSTEM[$frame->fd];
            $ALL_MODULES[$frame->data['system']] = true;

            $result = '';
            try
            {
                if (!empty($this->_event))
                {
                    $this->_event->onMessage($server, $frame);
                }
                if (!empty($frame->data['data'])) {
                    $_GET = $frame->data['data'];
                }
                    // 初始化配置项
                $container = Container::getInstance();
                $this->_fd = $frame->fd;
                
                $result = $container->getComponent(SYSTEM_APP_NAME, 'dispatcher')->run(array(
                    'system' => $frame->data['system'],
                    'controller' => $frame->data['controller'],
                    'action' => $frame->data['action']
                ));
                if (is_array($result)) {
                    $result = json_encode($result);
                }
                if (DEBUG)
                {
                    $_result = ob_get_clean();
                    $_result = is_array($_result) ? json_encode($_result) : $_result;
                    $result = $_result . $result;
                    unset($_result);
                }
            }
            catch (\Throwable $exception)
            {
                $this->handleThrowable($exception);
                if (DEBUG) {
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
            
            if (DEBUG) {
                if ($GLOBALS['EXCEPTION']) {
                    $result .= $GLOBALS['EXCEPTION'];
                } else if ($GLOBALS['ERROR']) {
                    $result .= $GLOBALS['ERROR'];
                }
            }

            $this->push($frame->fd, $result);
            $this->pushAll();
            $container->finish($frame->data['system']);
            $container->finish(SYSTEM_APP_NAME);
            unset($container, $server, $frame, $result);
            $_GET = [];
            $_SERVER = [];
            return false;
        });
    }

    protected function afterClose(\swoole_server $server, int $fd, int $reactorId)
    {

        global $FD_SYSTEM;
        unset($FD_SYSTEM[$fd]);
        return true;
    }
}
