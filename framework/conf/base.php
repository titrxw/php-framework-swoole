<?php
/**
 * Created by PhpStorm.
 * User: rxw
 * Date: 17-8-26
 * Time: 下午9:33
 */
return array(
    'composer' => array(
        'Logger' => function (array $params) {
            return new \Monolog\Logger($params[0]);      //这里测试composer的加载
        },
        'sendfile' => function (array $params) {
            return new \diversen\sendfile();
        }
    ),
    'addComponentsMap' => array(
        'msgTask' => 'blog\\conf\\Task',
        'crontabTask' => 'blog\\conf\\CrontabTask',
        'crontab' => 'framework\\crontab\\Crontab',
        'zookeeper' => 'framework\\components\\zookeeper\\ZookeeperConf',
        'doc' => 'framework\\base\\Documentor',
        'rpcClient' => 'framework\\client\\RpcClient',
        // 'rpcClient' => 'framework\\client\\ZookeeperRpcClient',
        'monitor' => 'framework\\components\\monitor\\Monitor',
        'distribute_ws_mq' => 'framework\\components\\mq\\Mq'
    ),
    'components' => array(
        'controller' => [
            'distribute_ws_mq' => [
                'exchange' => 'distribute_ws_mq',
                'mode' => 'direct',
                'host' => '127.0.0.1',
                'port' => '5672',
                'user' => 'guest',
                'password' => 'guest'
            ]
        ],
        'monitor' => [
            'address' => '127.0.0.1:55656'
        ],
        // 'rpcClient' => [
        //     'conf' => 'zookeeper'
        // ],
        'zookeeper' => [
            'hosts' => 'localhost:2181',
            'watch_node' => [
                [
                    'haver' => 'blog',
                    'node' => '/blog/conf/test',
                    'save_path' => 'test.php'
                ],
                [
                    'haver' => 'APP',
                    'node' => '/APP/conf/test',
                    'save_path' => 'test.php'
                ]
            ]
        ],
        'log' => array(
            'path' => 'runtime/log/',
            'isLog' => true,
            'maxSize' => 2097152,
            'url' => 'url'
        ),
        'url' => array(
            'routerKey' => '',
            'type' => '/',
            'separator' => '/',
            'defaultSystem' => 'blog',
            'defaultSystemKey' => 's',
            'controllerKey' => 'm',
            'actionKey' => 'act',
            'defaultController' => 'index',
            'defaultAction' => 'index',
            'systems' => array('application', 'application1', 'blog'),
            'alias' => [
                'v1' => 'blog.v1'
            ]
        ),
        'resquest' => array(
            'separator' => '/',
            'url' => 'url'
        ),
        'response' => array(
            'defaultType' => 'text',
            'charset' => 'utf-8'
        ),
        'distribute_ws_mq' => [
            'handle' => 'rabbit',
            'conf' => [
                'exchange' => 'distribute_ws_mq',
                'mode' => 'direct',
                'host' => '127.0.0.1',
                'port' => '5672',
                'user' => 'guest',
                'password' => 'guest'
            ]
        ],
        'server' => array(
            // 是否启用配置管理
            'zookeeper' => '',
            'pid_file' => '/var/www/server_http1.pid',
            'event' => 'framework\\web\\ServerEvent',
            'ip' => '127.0.0.1',
            'port' => '8081',
            'supportHttp' => false,
            'type' => 'http',
            'auto_services' => false,
            'services' => [
                'Hello\\HelloService' => [
                    'handle' => '\\services\\Hello\\Handler',
                    'process' => '\\services\\Hello\\HelloServiceProcessor'
                ]
            ],
            'mq' => [
                'exchange' => 'router',
                'queue' => 'log_error',
                'mode' => 'direct',
                'host' => '127.0.0.1',
                'port' => '5672',
                'user' => 'guest',
                'password' => 'guest',
                // 'router_key' => [
                //     'test'
                // ]
            ],
            // 'factory_mode'=>2,
            // 'daemonize' => 1,
            'dispatch_mode' => 2,
            'task_worker_num' =>0, //异步任务进程
            // "task_max_request"=>10,
            'max_request'=>3000,
            'worker_num'=>1,
            // 'task_ipc_mode' => 2, 
            'message_queue_key' => '0x72000100', //指定一个消息队列key。如果需要运行多个swoole_server的实例，务必指定。否则会发生数据错乱
            'log_file' => '/tmp/swoole.log',
//             'enable_static_handler' => true,
//             'document_root' => '/var/www/php/easy-framework-swoole/public/assets/application/images/' //访问链接是 127.0.0.1:81/jpg文件名
        ),
        'client' => array(
            'host' => '127.0.0.1',
            'port' => '8082',
            'type' => 'crontab'
        ),
        'crontab' => array(
            'tasks' => array(
                
                // '2 /2 3-8 3,5 1--crontabTask test',
                // '3 /2 3-8/2 3,5 1--crontabTask test'
            )
        )
    )
);

