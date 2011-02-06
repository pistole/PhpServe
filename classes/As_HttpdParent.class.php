<?php
class As_HttpdParent
{
	
	private $port = '8080';
	private $bindAddr = '127.0.0.1';
	
	private $servePath = dirname(dirname(__FILE__)) . '/webroot/';
	
	private $phpCommand = '/usr/bin/php';

	private $childScript = '';
	
	private $sock = null;
	
	private $processes = array();
	
	public function __construct($port = '8080', $bindAddr = '127.0.0.1', $servePath = '.', $phpCommand = '/usr/bin/php', $childScript = null)
	{
		$this->port = $port;
		$this->bindAddr = $bindAddr;
		// $this->servePath = $servePath;
		$this->phpCommand = $phpCommand;
		
		if ($childScript === null)
		{
			$childScript = dirname(dirname(__FILE__)) . '/' . 'child_serve.php';
		}
		
		$this->childScript = $childScript;
	}
	
	public function serve()
	{
		$this->bind();
		while (true)
		{
			$connection = $this->connect();
			if (!is_null($connection) && $connection != FALSE)
			{
				$this->spawnProcess($connection);
			}
			foreach ($this->processes as $key => $procInfo)
			{
				$status = proc_get_status($procInfo['process']);
				
				if (!$status['running'])
				{
					fflush($procInfo['socket']);
	                fclose($procInfo['socket']);
	                proc_close($procInfo['process']);
					unset($this->processes[$key]);
				}
			}
			usleep(100);
			
		}
		
	}
	
	private function bind()
	{
		$this->sock = stream_socket_server("tcp://". $this->bindAddr . ":" . $this->port, $errno, $errstr);
	}
	
	private function connect()
	{
		return @stream_socket_accept($this->sock, 0 );
	}


	private function spawnProcess(&$connection)
	{
		$shellCmd = $this->phpCommand . ' ' . $this->childScript;
		$shellCmd = escapeshellcmd($shellCmd);

		$descriptors = array(
			0 => $connection,
			1 => $connection,
			);
		$pipes = array();

		$response = proc_open($shellCmd, $descriptors, $pipes, $this->servePath);
		$status = proc_get_status($response);
        print_r($status);
		$this->processes[] = array('socket' => $connection, 'process' => $response);
	}
	
}