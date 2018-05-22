<?php
/**
 * Created by PhpStorm.
 * User: rxw
 * Date: 17-9-16
 * Time: 下午8:48
 * 
 * 
 * 待处理： 粘包问题  在send的时候进行包分割
 */
namespace framework\crontab;
use framework\base\Container;
use framework\server\TcpServer;

class CrontabServer extends TcpServer
{
    protected $_nodeHandle;
    protected $_curNode;
    protected $_curTask;
    protected $_nodeNum = 0;
    protected $_busyNodeNum = 0;
    protected $_isPublish = false;
    protected $_tasks = [];
    protected $_publishFd = 0;
    protected $_publishData = [];

    protected function init()
    {
        parent::init(); // TODO: Change the autogenerated stub
        $this->onPipMessage();
    }

    protected function afterWorkStart(\swoole_server $serv, $workerId)
    {
        if ($this->isWork($workerId)) {
            \swoole_set_process_name('php-crontab');
            $this->_nodeHandle = new \framework\conformancehash\Dispatcher();
//                监控其他服务节点
        } else {
            \swoole_set_process_name('php-task-crontab');
//                启动任务  只能有一个启动
            if (!$this->isFirstTask($workerId)) {
                return false;
            }

            // 这里需要添加任务更新命令
            $crontab = Container::getInstance()->getComponent(SYSTEM_APP_NAME, 'crontab');
            $this->_server->tick($this->getValueFromConf('task_step', 1000), function() use ($crontab) {
                try{
                    if ($this->_isPublish) {
                        $this->_isPublish = false;
                        // 更新任务
                        if (!$crontab->updateLatestTask($this->_publishData)) {
                            $this->_server->sendMessage(['cmd' => 'publish', 'data' => 'error'], 0);
                        } else {
                            $this->_server->sendMessage(['cmd' => 'publish', 'data' => 'success'], 0);
                        }
                        $this->_publishData = [];
                        // 更新完成
                    }
                    $num = 0;
                    foreach($crontab->run() as $task_item) {
                        if (!empty($task_item)) {
                            $this->_server->sendMessage(['cmd' => 'task', 'data' => $task_item, 'no' => $num], 0);
                            ++$num;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->handleThrowable($e);
                }
            });
        }
        return parent::afterWorkStart($serv, $workerId); // TODO: Change the autogenerated stub
    }

    protected function afterReceive(\swoole_server $serv, $fd, $from_id, $data)
    {
        if ($data === 'doer') {
            $this->_nodeHandle->addNode($fd) && ++$this->_nodeNum;
        } else if ($data === 'publish') {
            if ($this->_publishFd > 0) {
                // 只能有一个发布者
                $this->_server->close($fd);
            } else {
                $this->_publishFd = $fd;
                $this->send($this->_publishFd, 'begin');
            }
        } else if ($data === 'busy') {
            $node = $this->_nodeHandle->findNode($fd);
            $node->_isBusy = true;
        } else if ($data === 'free') {
            // 目前这里的free命令会在每次任务执行完成后自动发出，这里的任务执行完成是每个进程执行完任务后， 所以暂时不能在这里进行任务是否是busy的条件
            // 如果这里的条件满足的话  下面在查找free节点的时候可以少判断n-1次
            $node = $this->_nodeHandle->findNode($fd);
            $node->_isBusy = false;
        } else if ($fd == $this->_publishFd) {
            $_data = \json_decode($data, true);
            if (isset($_data)) {
                $this->_server->sendMessage(['cmd' => 'publish', 'data' => $data], 1);
            } else {
                $this->send($this->_publishFd, 'publish error');
            }
        }
    }

    protected function afterClose(\swoole_server $server, int $client_id, int $from_id)
    {
        if ($this->_publishFd === $client_id) {
            $this->_publishFd = 0;
        } else {
            $this->_nodeHandle->removeNode($client_id) && --$this->_nodeNum;
        }
        
        return parent::afterClose($server, $client_id, $from_id);
    }

    protected function afterPipMessage(\swoole_server $serv, $src_worker_id, $data)
    {
        if ($this->isTask($src_worker_id)) {
            if (empty($data['cmd'])) {
                return false;
            }
            $cmd = $data['cmd'];
            unset($data['cmd']);
            switch ($cmd) {
                case 'task':
                    $this->_busyNodeNum = 0;
                    $this->dispatchTask($data['data']);
                break;
                case 'publish':
                    $this->send($this->_publishFd, $data['data']);
                break;
            }
        } else {
            if (empty($data['cmd'])) {
                return false;
            }
            $cmd = $data['cmd'];
            unset($data['cmd']);
            switch ($cmd) {
                case 'publish':
                $this->_publishData = json_decode($data['data'], true);
                $this->_isPublish = true;

                break;
            }
        }

        return parent::afterPipMessage($serv, $src_worker_id, $data);
    }

    protected function isFirstTask($workId)
    {
        if ($workId = $this->getValueFromConf('work_num', 4)) {
            return true;
        }
        return false;
    }

    protected function dispatchTask($data)
    {
        $this->_curTask = $data;
        $this->_curNode = $this->_nodeHandle->findNextNodeByValue(\serialize($data));
        if (!$this->_curNode) {
            return false;
        }
        if (!$this->_curNode->_isBusy) {
            if (isset($data['retry'])) unset($data['retry']);
            if (isset($data['rand'])) unset($data['rand']);
            unset($data['no']);
            // 分包处理
            $this->send($this->_curNode->_info, \json_encode(['cmd' => 'task', 'data' => $data]), '\n\r');
        } else if ($this->_busyNodeNum != $this->_nodeNum) {
            ++$this->_busyNodeNum;
            $data['retry'] = $this->_busyNodeNum;
            $this->dispatchTask($data);
        } else {
            $data['rand'] = $this->_busyNodeNum;
            $this->_curNode = $this->_nodeHandle->findNextNodeByValue(\serialize($data));
            if (isset($data['retry'])) unset($data['retry']);
            if (isset($data['rand'])) unset($data['rand']);
            unset($data['no']);
            // 分包处理
            $this->send($this->_curNode->_info, \json_encode(['cmd' => 'task', 'data' => $data]), '\n\r');
        }
    }
}