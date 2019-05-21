<?php


namespace EasySwoole\Rpc;


use EasySwoole\Component\Process\AbstractProcess;
use EasySwoole\Component\TableManager;
use Swoole\Coroutine\Socket;

class WorkerProcess extends AbstractProcess
{

    public function run($arg)
    {
        /** @var Config $config */
        $config  = $arg['config'];
        $serviceList = $arg['serviceList'];
        $socket = new Socket(AF_INET,SOCK_STREAM,0);
        $socket->setOption(SOL_SOCKET,SO_REUSEPORT,true);
        $socket->setOption(SOL_SOCKET,SO_REUSEADDR,true);
        $ret = $socket->bind($config->getListenAddress(),$config->getListenPort());
        if(!$ret){
            trigger_error("Rpc bind {$config->getListenAddress()}:{$config->getListenPort()} fail");
            return;
        }
        $ret = $socket->listen(2048);
        if(!$ret){
            trigger_error("Rpc listen {$config->getListenAddress()}:{$config->getListenPort()} fail");
            return;
        }
        while (1){
            /** @var Socket $clientSocket */
            $clientSocket = $socket->accept(-1);
            if($clientSocket){
                go(function ()use($clientSocket,$config,$serviceList){
                    $reply = new Response();
                    $reply->setNodeId($config->getNodeId());
                    $header = $clientSocket->recvAll(4,1);
                    if(strlen($header) != 4){
                        $reply->setStatus(Response::STATUS_ILLEGAL_PACKAGE);
                        $this->reply($clientSocket,$reply);
                        return;
                    }
                    $allLength = Protocol::packDataLength($header);
                    $data = $clientSocket->recvAll($allLength,3);
                    if(strlen($data) != $allLength){
                        $reply->setStatus(Response::STATUS_ILLEGAL_PACKAGE);
                        $this->reply($clientSocket,$reply);
                        return;
                    }
                    $command = json_decode($data,true);
                    if(is_array($command)){
                        $command = new Command($command);
                    }else{
                        $reply->setStatus(Response::STATUS_ILLEGAL_PACKAGE);
                        $this->reply($clientSocket,$reply);
                        return;
                    }
                    $request = $command->getRequest();
                    if(!$request){
                        $reply->setStatus(Response::STATUS_ILLEGAL_PACKAGE);
                        $this->reply($clientSocket,$reply);
                        return;
                    }
                    switch ($command->getCommand()){
                        case Command::SERVICE_CALL:{
                            if(isset($serviceList[$request->getServiceName()])){
                                $client = new SocketClient();
                                $client->setSocket($client);
                                /** @var AbstractService $service */
                                $service = $serviceList[$request->getServiceName()];
                                $service->__hook($request,$reply,$client);
                                $this->reply($clientSocket,$reply);
                            }else{
                                $reply->setStatus(Response::STATUS_SERVICE_NOT_EXIST);
                                $this->reply($clientSocket,$reply);
                            }
                            break;
                        }
                        case Command::SERVICE_STATUS:{
                            $ret = [];
                            if(!empty($request->getServiceName())){
                                $table = TableManager::getInstance()->get($request->getServiceName());
                                if($table){
                                    foreach ($table as $action => $info){
                                        $ret[$request->getServiceName()][$action] = $info;
                                    }
                                }else{
                                    $reply->setMsg('service not exits');
                                }
                            }else{
                                foreach ($serviceList as $serviceName => $item){
                                    $table = TableManager::getInstance()->get($serviceName);
                                    if($table){
                                        foreach ($table as $action => $info){
                                            $ret[$serviceName][$action] = $info;
                                        }
                                    }
                                }
                            }
                            $reply->setResult($ret);
                            $this->reply($clientSocket,$reply);
                            break;
                        }
                    }
                });
            }
        }
    }

    public function onShutDown()
    {
        // TODO: Implement onShutDown() method.
    }

    public function onReceive(string $str)
    {
        // TODO: Implement onReceive() method.
    }

    private function reply(Socket $clientSocket,Response $response)
    {
        $str = $response->__toString();
        $str = Protocol::pack($str);
        $clientSocket->sendAll($str);
        $clientSocket->close();
    }
}