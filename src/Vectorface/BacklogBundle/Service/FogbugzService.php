<?php

namespace Vectorface\BacklogBundle\Service;

use Vectorface\BacklogBundle\Service\RedisService;
use There4\FogBugz;

class FogbugzService
{
    private $redis;
    private $logon;

    public function __construct(RedisService $redis)
    {
        $this->redis = $redis;
    }

    public function setConfig($config = array())
    {
        $this->config = $config;
    }

    public function logon()
    {
        $parameters = $this->config;

        /* Logon/Token is kind of hacky because of the vendor package we're using */
        $this->fogbugz = new FogBugz\Api(
            $parameters['user'],
            $parameters['password'],
            $parameters['url']
            );

        /* If provided username & password log in to get the token */
        if(!empty($parameters['user']) && !empty($parameters['password'])){
            $this->fogbugz->logon();
        }

        /* If provided token; Logon process isn't needed */
        if(!empty($parameters['token'])){
            $this->fogbugz->token = $parameters['token'];
        }
    }

    public function pullTickets()
    {
        $xml = $this->fogbugz->search(array('q' => 'status:"active" OR status:"open"', 'cols' => 'ixBug,sCategory,sTitle,sProject,ixProject,ixFixFor,sFixFor,sEmailAssignedTo,sPersonAssignedTo,ixPersonAssignedTo,hrsCurrEst'));
        $this->redis->del('tickets');
        foreach($xml->children() as $tickets){
            foreach($tickets->children() as $ticket){
                $data = array(
                    'ixBug' => (string)$ticket->ixBug,
                    'sCategory' => (string)$ticket->sCategory,
                    'sTitle' => (string)$ticket->sTitle,
                    'ixProject' => (string)$ticket->ixProject,
                    'sProject' => (string)$ticket->sProject,
                    'ixFixFor' => (string)$ticket->ixFixFor,
                    'sFixFor' => (string)$ticket->sFixFor,
                    'sEmailAssignedTo' => (string)$ticket->sEmailAssignedTo,
                    'sPersonAssignedTo' => (string)$ticket->sPersonAssignedTo,
                    'hrsCurrEst' => (string)$ticket->hrsCurrEst
                    );
                $this->redis->hMset('ticket:'.(string)$ticket->ixBug, $data);
                $this->redis->zAdd('tickets', (string)$ticket->ixBug, (string)$ticket->sTitle);
            }
        }
        return $xml->cases->attributes()->count;
    }

    public function removeClosedTickets()
    {
        $this->redis->zInter('listOfBacklogs', array('tickets', 'listOfBacklogs'), array(1, 0));
        $backLogs = $this->redis->lRange("rankOfBacklogs", 0, -1);
        foreach($backLogs as $backlog) {
            $exists = $this->redis->ZRANGEBYSCORE('listOfBacklogs', $backlog, $backlog);
            if(empty($exists)) {
                $this->redis->lRem('rankOfBacklogs', $backlog);
            }

        }
    }

    public function pushBacklog()
    {
        $backlogs = $this->redis->lRange("rankOfBacklogs", 0, -1);
        $totalBacklogs = count($backlogs);
        for($i=0; $i < $totalBacklogs; $i++) {
            $this->fogbugz->edit(array('ixBug' => $backlogs[$i], 'plugin_projectbacklog_at_fogcreek_com_ibacklog' => $i));
        }
    }

    public function pullUsers()
    {
        $xml = $this->fogbugz->listPeople(array('fIncludeVirtual' => 1, 'fIncludeNormal' => 1));
        $this->redis->del('users');
        foreach($xml->children() as $users){
            foreach($users->children() as $user){
                $this->redis->zAdd('users', $user->ixPerson, (string)$user->sFullName);
            }
        }
    }

    public function updateTimeEstimate($ixBug, $estimatedTime)
    {
        $ticket = $this->redis->hGetAll('ticket:'. $ixBug);
        if($ticket['hrsCurrEst'] != $estimatedTime) {
            $this->fogbugz->edit(array('ixBug' => $ixBug, 'hrsCurrEst' => $estimatedTime));
            $this->redis->hSet('ticket:'.$ixBug, 'hrsCurrEst', $estimatedTime);
        }
    }

    public function updatePersonAssignedTo($ixBug, $personAssignedTo)
    {
        $ticket = $this->redis->hGetAll('ticket:'. $ixBug);
        if($ticket['sPersonAssignedTo'] != $personAssignedTo) {
            $this->fogbugz->edit(array('ixBug' => $ixBug, 'sPersonAssignedTo' => $personAssignedTo));
            $this->redis->hSet('ticket:'.$ixBug, 'sPersonAssignedTo', $personAssignedTo);
        }
    }

}