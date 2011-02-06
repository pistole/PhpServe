<?php

/**
 * A simple file class for downloading, streaming, or just getting the mime type.
 * 
 * Probably needs a better name.
 * 
 * @author Anthony Bush
 **/
class Awb_File
{
	public static $mimeTypesFile = '/etc/apache2/mime.types';
	
	/**
	 * Simple mime-type function given filename with features:
	 * 
	 * - Hard-coded list of known mime types
	 * - Failover to system-wide "mime.types" file.
	 * - Failover to built-in PHP functions if available.
	 * - Failover to generic "application/octet-stream" mime type if all above fail.
	 *
	 * @return string the mime type of the file
	 **/
	public static function getMimeContentType($filename)
	{
		$mimeTypes = array(
			
			'txt' => 'text/plain',
			'htm' => 'text/html',
			'html' => 'text/html',
			'php' => 'text/html',
			'css' => 'text/css',
			'js' => 'application/javascript',
			'json' => 'application/json',
			'xml' => 'application/xml',
			'swf' => 'application/x-shockwave-flash',
			'flv' => 'video/x-flv',
			
			// images
			'png' => 'image/png',
			'jpe' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpg' => 'image/jpeg',
			'gif' => 'image/gif',
			'bmp' => 'image/bmp',
			'ico' => 'image/vnd.microsoft.icon',
			'tiff' => 'image/tiff',
			'tif' => 'image/tiff',
			'svg' => 'image/svg+xml',
			'svgz' => 'image/svg+xml',
			
			// archives
			'zip' => 'application/zip',
			'rar' => 'application/x-rar-compressed',
			'exe' => 'application/x-msdownload',
			'msi' => 'application/x-msdownload',
			'cab' => 'application/vnd.ms-cab-compressed',
			
			// audio/video
			'qt' => 'video/quicktime',
			'mov' => 'video/quicktime',
			'm4v' => 'video/mp4',
			'mp4' => 'video/mp4',
			'avi' => 'video/x-msvideo',
			'mp3' => 'audio/mpeg',
			'mp2' => 'audio/mpeg',
			'mpga' => 'audio/mpeg',
			'm4a' => 'audio/mp4',
			'm4p' => 'audio/mp4',
			'm4r' => 'audio/mp4',
			'aac' => 'audio/mp4',
			'ogg' => 'application/ogg',
			
			// adobe
			'pdf' => 'application/pdf',
			'psd' => 'image/vnd.adobe.photoshop',
			'ai' => 'application/postscript',
			'eps' => 'application/postscript',
			'ps' => 'application/postscript',
			
			// ms office
			'doc' => 'application/msword',
			'rtf' => 'application/rtf',
			'xls' => 'application/vnd.ms-excel',
			'ppt' => 'application/vnd.ms-powerpoint',
			
			// open office
			'odt' => 'application/vnd.oasis.opendocument.text',
			'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
		);
		
		// Try to determine mime type from hard-coded array above, then mime type file if it exists, then built-in PHP functions, otherwise just use generic value.
		$mimeTypesFile = self::$mimeTypesFile;
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		
		if (isset($mimeTypes[$ext]))
		{
			return $mimeTypes[$ext];
		}
		
		if (file_exists($mimeTypesFile))
		{
			$lines = file($mimeTypesFile);
			foreach ($lines as $line)
			{
				// Remove comments and process the line if there's anything left
				$line = trim(preg_replace('/#.*/', '', $line));
				if (strlen($line) > 0)
				{
					$split = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
					// If there are any file extensions mapped to the mime type then use them
					if (count($split) > 1)
					{
						$mimeType = array_shift($split);
						if (in_array($ext, $split))
						{
							return $mimeType;
						}
					}
				}
			}
		}
		
		if (function_exists('finfo_open'))
		{
			$finfo = finfo_open(FILEINFO_MIME);
			$mimeType = finfo_file($finfo, $filename);
			finfo_close($finfo);
			return $mimeType;
		}
		
		return 'application/octet-stream';
	}
	
	/**
	 * For forcing a file to download on the client.
	 * 
	 * @param string $path full path to file to be downloaded
	 * @return void
	 **/
	public static function readFile($path)
	{
		header('Content-Type: ' . self::getMimeContentType($path));
		header('Content-Length: ' . filesize($path));
		header('Content-Disposition: attachment; filename=' . basename($path));
		readfile($path);
	}
	
	/**
	 * For streaming files to the client, e.g. MPEG-4 to iPhone.  It supports the
	 * ability to seek in the stream without having to download everything before it.
	 * 
	 * @param string $path full path to file to be streamed
	 * @return void
	 **/
	public static function streamFile($path)
	{
		if (isset($_SERVER['HTTP_RANGE']))
		{
			$filesize = filesize($path);
			$range = $_SERVER['HTTP_RANGE'];
			
			list($unit, $bytes) = explode('=', $range);
			list($start, $end) = explode('-', $bytes);
			
			if (strlen($end) == 0)
			{
				$end = $filesize - 1;
			}
			
			$length = $end - $start + 1;
			
			if ($start < 0 || $end >= $filesize)
			{
				header('HTTP/1.1 416 Requested Range Not Satisfiable');
				exit();
			}
			
			$contentType = self::getMimeContentType($path);
			
			header('HTTP/1.1 206 Partial Content');
			header('Accept-Ranges: bytes');
			header('Content-Range: bytes ' . $start . '-' . $end . '/' . $filesize);
			header('Content-Length: ' . $length);
			header('Content-Type: ' . $contentType);
			header('Content-Expires: ' . date('r', strtotime('+1 day')));
			
			// output specified bytes
			$fp = fopen($path, 'rb');
			if ($fp !== false)
			{
				// works, but you can run out of memory on server very easily if trying to stream large files (e.g. videos)
				// fseek($fp, $start);
				// echo fread($fp, $length);
				// fclose($fp);
				
				fseek($fp, $start);
				
				$remainingLength = $length;
				$maxReadLength = 32768; // 8192 per http://www.php.net/manual/en/function.fread.php
				while ($remainingLength > $maxReadLength)
				{
					// error_log('Reading ' . $maxReadLength . ' bytes');
					echo fread($fp, $maxReadLength);
					$remainingLength -= $maxReadLength;
				}
				
				// last little bit
				if ($remainingLength > 0)
				{
					// error_log('Reading ' . $remainingLength . ' bytes');
					echo fread($fp, $remainingLength);
				}
				
				fclose($fp);
			}
			
			exit();
		}
		else
		{
			header('Accept-Ranges: bytes');
			header('Content-Type: ' . self::getMimeContentType($path));
			header('Content-Length: ' . filesize($path));
			readfile($path);
			exit();
		}
	}
}

?>