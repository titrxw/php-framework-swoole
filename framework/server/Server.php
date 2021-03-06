<?php
/**
 * Created by PhpStorm.
 * User: rxw
 * Date: 17-9-16
 * Time: 下午9:06
 */
namespace framework\server;

use framework\base\Component;
use Thrift\ClassLoader\ThriftClassLoader;
use Thrift\Server\TServerSocket;

class Server extends Component
{
    protected $_server = null;
    protected $_app;

    public function start($instance)
    {
        if (!extension_loaded('swoole')) {
            throw new \Error('not support: swoole', 500);
        }
        
        $this->_app = $instance;
        switch ($this->getValueFromConf('type' , 'http'))
        {
            case 'http':
                $this->_server = new HttpServer($this->_conf);
                $this->_server->start();
                break;
            case "webSocket":
                $this->_server = new WebSocketServer($this->_conf);
                $this->_server->start();
                break;
            case 'crontab':
                $this->_server = new CrontabServer($this->_conf);
                $this->_server->start();
                break;
            case 'mq':
                $this->_server = new MqServer($this->_conf);
                $this->_server->start();
            case 'rpc':
                require_once (APP_ROOT."framework/Thrift/ClassLoader/ThriftClassLoader.php");
                $loader = new ThriftClassLoader();
                $loader->registerNamespace('Thrift', APP_ROOT. 'framework');
                $loader->registerNamespace('services', APP_ROOT);
                $loader->registerDefinition('services',  APP_ROOT);
                $loader->register(TRUE);
                if (!empty($this->_conf['auto_services'])) {
                    $this->_server = new ZookeeperRpcServer($this->_conf);
                } else {
                    $this->_server = new RpcServer($this->_conf);
                }
                $this->_server->start();
            break;
        }
    }

    public function getServer()
    {
        return $this->_server;
    }

    public function getApp()
    {
        return $this->_app;
    }
}
