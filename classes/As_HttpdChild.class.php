<?php
class As_HttpdRequest
{
	private $remote = null;
	private $remoteHost = null;
	private $remotePort = null;
	
	private $requestData = null;
	private $requestArry = array();
	
	public function getRequestArry()
	{
		return $this->requestArry;
	}
	
	public function setGetArray($newArray)
	{
		$this->requestArry['get'] = $newArray;
	}
	
	public function __construct($data)
	{
		$this->requestData = $data;
		$this->remote = stream_socket_get_name(STDIN, true);
		$this->remoteHost = $this->remote;
		$remoteArray = preg_split('/\:/', $this->remote, 2);
		if (count($remoteArray) == 2)
		{
			$this->remoteHost = $remoteArray[0];
			$this->remotePort = $remoteArray[1];
		}

		$data = explode("\r\n\r\n", $data, 2);

		$header = explode("\r\n", $data[0]);

		$requestLine = explode(' ', $header[0]);
		unset($header[0]);
		/* make sure it's a valid HTTP request */
		if (!isset($requestLine[2]) || strpos($requestLine[2], "HTTP/") === false) {
			return;
		}

		$request['method'] = $requestLine[0];
		$request['protocol'] = $requestLine[2];

		$request['cookie'] = array();
		$request['connection_close'] = false;

		/* Here we're going through the headers one by one
		 * looking for the few that we actually care about */

		foreach ($header as $headerLine) 
		{			
			$headerLine = trim($headerLine);
			if (stripos($headerLine, 'User-Agent:') === 0) 
			{
				/* pass user-agent string on to use
				 * with the $_SERVER variable */
				$headerLine = substr($headerLine, 11);
				$request['user_agent'] = trim($headerLine);
			} 
			else if (stripos($headerLine, 'Accept-Language:')  === 0) 
			{
				/* pass accept-language string on to
				 * use with the $_SERVER variable */
				$headerLine = substr($headerLine, 16);
				$request['language'] = trim($headerLine);
			} 
			else if (stripos($headerLine, 'Cookie:')  === 0) 
			{
				/* parse any cookies */
				$headerLine = substr($headerLine, 7);
				$request['cookie'] = As_HttpdChild::parseQuery(trim($headerLine), '; ');
			} 
			else if (stripos($headerLine, 'Connection: close')  === 0) 
			{
				/* see if they want us to close the connection */
				$request['connection_close'] = true;
			} 
			else if (stripos($headerLine, 'Content-Type:')  === 0) 
			{
				/* get content-type */
				$headerLine = substr($headerLine, 13);
				$request['content_type'] = trim($headerLine);
			}
		}
		/* save requested URI for $_SERVER */
		$request['uri'] = $requestLine[1];

		/* split any GET querries from the requested path */
		$path_query = explode('?', $requestLine[1]);
		$request['file'] = $path_query[0];

		/* save query string for $_SERVER */
		$request['query_string'] = '';
		if (isset($path_query[1])) {
			$request['query_string'] = $path_query[1];
		}

		// initialize file, post, and get data arrays
		$request['get'] = array();
		$request['post'] = array();
		$request['files'] = array();

		/* determine if there are POST or GET queries, and parse them */
		if (isset($path_query[1]) && strpos($path_query[1], '=') !== false) {
			$request['get'] = As_HttpdChild::parseQuery($path_query[1]);
		}
		// check for included request body
		if (isset($data[1]) && isset($request['content_type'])) {
			if (strpos($request['content_type'], 'application/x-www-form-urlencoded') !== false) {
				// normal form
				$request['post'] = As_HttpdChild::parseQuery($data[1]);
			} elseif (strpos($request['content_type'], 'multipart/form-data') !== false) {
				// rfc 2388 covers multipart/form-data
				$boundary = explode(';', $request['content_type']);
				$boundary = explode('=', $boundary[1]);
				$boundary = trim($boundary[1]);

				$this->parse_multi_part($data[1], $boundary, $request['post'], $request['files']);
			}
		}

		$this->requestArry = $request;
		
	}
}

class As_HttpdResponse
{
	private $responseArry = array();
	
	public function getResponseArry()
	{
		return $this->responseArry;
	}
	
	
	public function __construct($httpdRequest)
	{
		$response['connection_close'] = true;
		
		$request = $httpdRequest->getRequestArry();
		
		/* if the request we got is invalid respond as such */
		if (!$request) 
		{
			$response['protocol'] = 'HTTP/1.1';
			$response['status'] = 'malformed';
			return $response;
		}

		if ($request['protocol'] == 'HTTP/1.1') 
		{
			/* If they're using HTTP version 1.1 then respond with 1.1 */
			$response['protocol'] = 'HTTP/1.1';
		} else 
		{
			/* If they're using any other version drop to 1.0 compatibility */
			$response['protocol'] = 'HTTP/1.0';
		}

		/* extremely basic security check, remove any ../ from requested file
		 * so they can't ascend into forbidden directories */
		$response['translated_file'] = str_replace('../', '', $request['file']);

		/* remove leading/trailing slash(es) */
		$response['translated_file'] = trim($response['translated_file'], '/');

		$response['translated_file'] = getcwd() . '/' . $response['translated_file'] ;

		/* load index.php for directories */
		if (is_readable($response['translated_file']) && is_dir($response['translated_file'])) 
		{
			$response['translated_file'] = rtrim($response['translated_file'], '/').'/index.php';
		}

		/* Get a file's extension by grabbing the characters
		 * from after the last period to the last character. */
		$response['file_type'] = substr($response['translated_file'], (strrpos($response['translated_file'], '.') + 1));

		/* we're working with a lot of files that may change */
		clearstatcache();

		/* see if requested file exists and can be served */
		if (basename($response['translated_file']) == '--status') 
		{
			$response['status'] = 'status';
		} 
		else if (file_exists($response['translated_file']) && !is_readable($response['translated_file'])) 
		{
			$response['status'] = 'forbidden';
		}
		else if (!file_exists($response['translated_file']) && file_exists(getcwd() . '/index.php')) 
		{
			// do lightvc rewriting
			$response['translated_file'] = getcwd() . '/index.php';
			$response['file_type'] = 'php';
			$response['status'] = 'ok';
			$httpdRequest->setGetArray(array('url' => ltrim($request['uri'], '/')));
		}  
		else if (!is_dir($response['translated_file'])) 
		{
			$response['status'] = 'ok';
		}
		$this->responseArry = $response;
	}
	
}

class As_HttpdChild
{

	private static $done = false;

	public static function parseQuery($queryLine, $delimiter='&') 
	{
	/* take a query line from a POST, GET or COOKIE and return an
	 * array holding each query name and value */

		if ($delimiter !== '&') 
		{
			$queryLine = str_replace($delimiter, '&', $queryLine);
		}
		parse_str(trim($queryLine), $result);

		return $result;
	}
	

	public static function parseMultiPart(&$body, $boundary, &$post, &$files) 
	{
		/* This function will parse posted multipart form data
		 * into temporary files or post queries which will be
		 * passed back by reference */

		// split the multiple parts into an array
		$body = preg_split("/(\r\n)?--$boundary(--)?(\r\n)?/", $body, -1, PREG_SPLIT_NO_EMPTY);
		// cycle through the parts and parse each one
		foreach($body as $part) 
		{

			$part = explode("\r\n\r\n", $part);
			if (count($part) < 2) 
			{
				$part[1] = '';
			}

			// separate the part's headers into an array
			$part[0] = explode("\r\n", $part[0]);
			foreach($part[0] as $val) 
			{
					$arr = explode(':', $val, 2);
					if (count($arr) > 1) 
					{
						$mime_header[strtolower($arr[0])] = trim($arr[1]);
					}
			}

			// separate all attributes of the content disposition into an array
			if (isset($mime_header['content-disposition'])) 
			{
				$attribute = explode(';', $mime_header['content-disposition']);

				foreach ($attribute as $val) 
				{
					$arr = explode('=', trim($val));
					if (count($arr) > 1) 
					{
						$cont_disp[strtolower($arr[0])] = trim($arr[1], '"');
					}
				}
			}

			// check and see if this part is a file
			if (!empty($cont_disp['filename'])) 
			{
				// generate a temporary name and get the size
				$tmp_name = '/tmp/php'.uniqid(getmypid());
				$size = strlen($part[1]);

				// add it and it's associated data to the files array that we're going to need
				$files[$cont_disp['name']]['name'] = $cont_disp['filename'];
				$files[$cont_disp['name']]['tmp_name'] = $tmp_name;
				$files[$cont_disp['name']]['size'] = $size;
				if (isset($mime_header['content-type'])) {
					$files[$cont_disp['name']]['type'] = $mime_header['content-type'];
				}

				// save the uploaded file to the tmp directory		
				// we will delete the file when execution of this
				// request is finished.
				if ($fp = fopen($tmp_name, 'w')) {
					fwrite($fp, $part[1], $size);
					fclose($fp);
				}
			} 
			else
			{
				if (isset($cont_disp['name'])) 
				{
					$post[$cont_disp['name']] = $part[1];
				}
			}
		}
	}

	public function __construct()
	{
		
	}
	
	public function serveRequest()
	{

		$data = '';
		
		stream_set_blocking(STDIN, 0);
		while (($chunk = fgetc(STDIN)) != '')
		{
			$data .= $chunk;
		}
		
		$request = new As_HttpdRequest($data);
		$response = new As_HttpdResponse($request);
		
		fwrite(STDERR, print_r($request, true));
		fwrite(STDERR, print_r($response, true));

		$this->sendResponse($response, $request);
		
		fclose(STDIN);
		fclose(STDOUT);
		self::$done = true;
		
		exit;
	}
	
	function sendResponse($httpResponse, $httpRequest) 
	{
	/* handle the client's request */
		$request = $httpRequest->getRequestArry();
		$response = $httpResponse->getResponseArry();
		if ($response['status'] == 'ok' && $response['file_type'] == 'php') {

			$content = $this->loadPage($response, $request);

			// add up the content-length
			$response['content_length'] = intval(strlen($content));
			$headers = $this->buildHeaders($response);

			if (@fwrite(STDOUT, $headers.$content) === false) {
				// error case
				exit();
			}
		} elseif ($response['status'] == 'ok') {
			/* if the requested resource is a normal file and can be returned */

			$response['content_length'] = filesize($response['translated_file']);
			$headers = $this->buildHeaders($response);

			if (@fwrite(STDOUT, $headers) === false) {
				// error
				exit();
			} else {
				$this->socketWriteFile($response['translated_file'], $this->cdata);
			}
		} elseif ($response['status'] == 'forbidden') {
			/* if the requested resource isn't readable */

			$content = "<html><head>\r\n";
			$content .= "<title>403 Forbidden</title>\r\n";
			$content .= "</head><body>\r\n";
			$content .= "<h1>Forbidden</h1>\r\n";
			$content .= "<p>You do not have permission to access $request[file].</p>\r\n";
			$content .= "<p><i>".$this->_cfg['sysname']."</i></p>\r\n";
			$content .= "</body></html>";

			$response['content_length'] = strlen($content);
			$headers = $this->buildHeaders($response);

			if (@fwrite(STDOUT, $headers.$content) === false) {
				exit();
			}
		} elseif ($response['status'] == 'not found') {
			/* if the requested resource doesn't exist */

			$content = "<html><head>\r\n";
			$content .= "<title>404 Not Found</title>\r\n";
			$content .= "</head><body>\r\n";
			$content .= "<h1>Not Found</h1>\r\n";
			$content .= "<p>The requested URL $request[file] was not found.</p>\r\n";
			$content .= "<p><i>".$this->_cfg['sysname']."</i></p>\r\n";
			$content .= "</body></html>";

			$response['content_length'] = strlen($content);
			$headers = $this->buildHeaders($response);

			if (@fwrite(STDOUT, $headers.$content) === false) {
				exit();
			}
		} else {
			/* if we had an invalid request */
			$content = "<html><head>\r\n";
			$content .= "<title>400 Bad Request</title>\r\n";
			$content .= "</head><body>\r\n";
			$content .= "<h1>Bad Request</h1>\r\n";
			$content .= "<p>You sent a malformed header. Goodbye.</p>\r\n";
			$content .= "<p><i>".$this->_cfg['sysname']."</i></p>\r\n";
			$content .= "</body></html>";

			$response['content_length'] = strlen($content);
			$headers = $this->buildHeaders($response);

			if (@fwrite(STDOUT, $headers.$content) === false) {
				exit();
			}
		}

	}

	private function loadPage($response, $request) 
	{
		/* Interpret a seperate php file and return it in a variable. */

		/* copy globals, work around for not being able to use $GLOBALS directly due to recursion */
		$_SERVER = array();
		$_REQUEST = array();

		/* pass HTTP_SERVER_VARS array to subscript */
		$_SERVER['HTTP_HOST']				= 'localhost';
		$_SERVER['HTTP_USER_AGENT']			= (isset($request['user_agent']) ? $request['user_agent'] : '');
		$_SERVER['HTTP_ACCEPT_LANGUAGE']	= (isset($request['language']) ? $request['language'] : '');
		$_SERVER['SERVER_SOFTWARE']			= 'phpserve-httpd php/'.phpversion();
//		$_SERVER['BIBIVU-HTTPD']			= true;
		$_SERVER['SERVER_NAME']				= $_SERVER['HTTP_HOST'];

		// FIXME, pull these out of socket
		$_SERVER['SERVER_ADDR']				= 'localhost';
		$_SERVER['SERVER_PORT']				= '8080';
		$_SERVER['REMOTE_ADDR']				= 'localhost';
		$_SERVER['REMOTE_PORT']				= '8080';

		$_SERVER['SERVER_PROTOCOL']			= $response['protocol'];
		$_SERVER['DOCUMENT_ROOT']			= getcwd();
		$_SERVER['SCRIPT_FILENAME']			= $response['translated_file'];
		$_SERVER['REQUEST_METHOD']			= $request['method'];
		$_SERVER['QUERY_STRING']			= $request['query_string']; 
		$_SERVER['REQUEST_URI']				= $request['uri'];
		$_SERVER['SCRIPT_NAME']				= substr($response['translated_file'], strlen(getcwd()));	
		$_SERVER['PHP_SELF']				= $_SERVER['SCRIPT_NAME'];

		/* pass cookie array to subscript */
		$_COOKIE = $request['cookie'];
		$_REQUEST = array_merge($_REQUEST, $_COOKIE);

		/* pass post array to subscript */
		$_POST = $request['post'];
		$_REQUEST = array_merge($_REQUEST, $_POST);

		/* pass get array to subscript */
		$_GET = $request['get'];
		$_REQUEST = array_merge($_REQUEST, $_GET);


		/* pass files array to subscript */
		$_FILES = $request['files'];

		/* set error reporting to the php.ini default */
		restore_error_handler();
		$default_error_level = get_cfg_var('error_reporting');
		$error_level = error_reporting($default_error_level);

		register_shutdown_function(array('As_HttpdChild', 'shutdown'), $request, $response);

		/* evaluate the requested script and cache it to a variable. */
		ob_start();
		$result = include($response['translated_file']);
		$page = ob_get_contents();
		while(ob_get_level()>0){
			ob_end_clean();
		}
		
		/* set error reporting to the php.ini default */
		restore_error_handler();
		$default_error_level = get_cfg_var('error_reporting');
		$error_level = error_reporting($default_error_level);

		/* See if there is anything returned from running the script. */
		if (strlen($result) > 1) {
			/* if so, print it out */
			$page .= $result;
		}

		/* remove temporary files that were uploaded by client */
		foreach($_FILES as $val) {
			/* make sure that this script can still access the file */
			if (is_writable($val['tmp_name'])) {
				unlink($val['tmp_name']);
			}
		}

		return $page;
	}
	
	public static function shutdown($request, $response)
	{
		if (self::$done)
		{
			return;
		}
		else
		{
			$headersList = headers_list();
			fwrite(STDERR, print_r($headersList, true));
			die;
			
			$page = ob_get_contents();
			while(ob_get_level()>0){
				ob_end_clean();
			}
			
			$_FILES = $request['files'];
			foreach($_FILES as $val) {
				/* make sure that this script can still access the file */
				if (is_writable($val['tmp_name'])) {
					unlink($val['tmp_name']);
				}
			}
			// add up the content-length
			$response['content_length'] = intval(strlen($page));
			$headers = self::buildHeaders($response);

			if (@fwrite(STDOUT, $headers.$page) === false) {
				return;
			}
			fclose(STDIN);
			fclose(STDOUT);
			self::$done = true;
			return;
		}
	}
	
	public static function buildHeaders($response) 
	{
		/* compile an appropriate list of headers to send to client */

		$httpStatusMap=array(
						'ok'			=> '200 OK',
						'redirect'		=> '302 Found',
						'forbidden'		=> '403 Forbidden',
						'not found'		=> '404 Not Found',
						'default'		=> '400 Bad Request',
						);

		/* Status line */
		if (!in_array($response['status'], array_keys($httpStatusMap))) {
			$response['status']='default';
		}
		$headers = $response['protocol'].' '.$httpStatusMap[$response['status']]."\r\n";

		/* Date line, Sat, 06 Sep 2014 23:50:08 GMT*/
		$headers .= 'Date: '. gmdate("D, d M Y H:i:s T") . "\r\n";

		/* Server line */
		$headers .= "Server: phpserve-httpd - web://cp \r\n";
		$headers .= 'X-Powered-By: PHP/'.phpversion()."\r\n";

		/* Add content length */
		$headers .= 'Content-length: '.$response['content_length']."\r\n";

		/* Connection close header */
		if ($response['connection_close'] && $response['protocol'] == 'HTTP/1.1') {
			$headers .= "Connection: close\r\n";
		}

		/* MIME type */
		if ($response['status'] !== 'ok') {
			$headers .= "Content-type: text/html\r\n";
		} else {
			$headers .= 'Content-type: '.self::getMimeType($response['translated_file'])."\r\n";
		}

		// Not that this doesn't work on the CLI builds of php, only the CGI builds,
		// 	So that the CLI builds cannot ever emit useful header information / cookies / redirects / etc.
		$headersList = headers_list();
		foreach ($headersList as $header)
		{
			$headers .= $header . "\r\n";
		}

		$headers .= "\r\n";
		return $headers;
	}
	
	function socketWriteFile($file, $client) 
	{
		/* send a file line by line. */

		$fp = fopen($file, 'rb');
		while($fp && !feof($fp)) {
			if (@fwrite(STDOUT, fread($fp, 1)) === false) {
				exit();
			}
		}
		if ($fp)
		{
			fclose($fp);
		}
	}
	static function getMimeType($fileName) 
	{
		return Awb_File::getMimeContentType($fileName);
	}
	
}