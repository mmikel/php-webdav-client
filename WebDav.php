<?php

/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 **/

/**
 *
 * References
 *
 *  - https://docs.nextcloud.com/server/12.0/developer_manual/client_apis/WebDAV/index.html
 **/

class WebDav {

	protected $_remoteUrl;

	protected $_httpResponseCode;
	protected $_httpResponseHeader;
	protected $_httpResponseText;

	protected $_httpRequestCookie;
	protected $_httpRequestAuthorization;

	public function __construct($location, $username, $password) {
		$this->_httpResponseHeader = [];

		$header = [];
		$this->_httpRequestAuthorization = 'Basic ' . base64_encode($username . ':' . $password);

		$this->sendHttpRequest('HEAD', $location, null, $header);

		if ($this->_httpResponseCode != 200 ) {
			throw new \Exception('Unexpected response code. ' . $this->_httpResponseCode);
		}

		$this->_remoteUrl = $location;
	}

	/**
	 * List files in the remote folder
	 */

	public function list($folderPath = '') {
		$itemCollection = [];
		$remotePath = parse_url($this->_remoteUrl, PHP_URL_PATH);
		$remotePath .= $folderPath;

		$location = $this->_remoteUrl . $folderPath;
		$this->sendHttpRequest('PROPFIND', $location);

		$doc = new \DOMDocument;
		@$doc->loadXML($this->_httpResponseText);
		$nodeList = $doc->getElementsByTagName('href');

		for ($i = 1; $i < $nodeList->length; $i++) {
			$nodePath = $nodeList->item($i)->textContent;
			if (substr($nodePath, 0, strlen($remotePath)) == $remotePath) {
				$nodePath = substr($nodePath, strlen($remotePath));
			}
			if ($nodePath == '') {
				continue;
			}
			$itemCollection[] = $nodePath;
		}

		return $itemCollection;
	}

	/**
	 * Copy a local file to the remote host
	 **/

	public function upload($localFile, $remoteFile) {
		if (is_file($localFile) == false) {
			throw new \Exception("File not found. '$localFile'");
		}

		$httpRequestText = file_get_contents($localFile);
		$httpRequestUrl = $this->_remoteUrl . '/' . $remoteFile;

		$this->sendHttpRequest('PUT', $httpRequestUrl, $httpRequestText);
	}

	/**
	 * Create file on the remote host
	 **/

	public function create($remoteFile, $content) {
		$httpRequestUrl = $this->_remoteUrl . '/' . $remoteFile;
		$this->sendHttpRequest('PUT', $httpRequestUrl, $content);
	}

	/**
	 * Delete file on remote host
	 **/

	public function delete($remoteFile) {
		$httpRequestUrl = $this->_remoteUrl . '/' . $remoteFile;
		$this->sendHttpRequest('DELETE', $httpRequestUrl);
	}

	/**
	 * Get file from remote host
	 **/

	public function get($remoteFile) {
		$httpRequestUrl = $this->_remoteUrl . '/' . $remoteFile;
		$this->sendHttpRequest('GET', $httpRequestUrl);
		return $this->_httpResponseText;
	}

	/**
	 * Create folder on remote host
	 */

	public function mkdir($remoteFolder) {
		$httpRequestUrl = $this->_remoteUrl . '/' . $remoteFolder;
		$this->sendHttpRequest('MKCOL', $httpRequestUrl);
	}

	/**
	 * Get file properties on remote host
	 */

	public function prop($remoteFile) {
		$httpRequestUrl = $this->_remoteUrl . '/' . $remoteFile;
		$this->sendHttpRequest('PROPFIND', $httpRequestUrl);

		$doc = new \DOMDocument;
		@$doc->loadXML($this->_httpResponseText);

		return [
			'href' => $doc->getElementsByTagName('href')->item(0)->textContent,
			'modified' => strtotime($doc->getElementsByTagName('getlastmodified')->item(0)->textContent),
			'length' => $doc->getElementsByTagName('getcontentlength')->item(0)->textContent,
			'type' => $doc->getElementsByTagName('getcontenttype')->item(0)->textContent,
		];
	}

	protected function sendHttpRequest($pMethod, $pUrl, $pData = null, $pHeader = null, $pOption = null) {

		$this->_httpResponseCode = null;
		$this->_httpResponseHeader = [];
		$this->_httpResponseText = null;

		$option = [
			'http' => [
				'method' => $pMethod,
			],
		];

		$header = [
			[ 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' ],
			[ 'Accept-Languange' => 'en-US,en;q=0.5' ],
			[ 'Authorization' => $this->_httpRequestAuthorization ],
		];

		// additional header

		if (is_null($pHeader) == false) {
			foreach ($pHeader as $pHeaderItem) {
				$header[] = $pHeaderItem;
			}
		}

		// cookie

		if (is_null($this->_httpRequestCookie) == false) {
			$cookieItem = [];
			foreach ($this->_httpRequestCookie as $cookieName => $cookieValue) {
				$cookieItem[] = $cookieName . '=' . $cookieValue;
			}
			$headerCookieValue = implode('; ', $cookieItem);
			$header[] = [ 'Cookie' => $headerCookieValue ];
		}

		$header[] = [ 'Cache-Control' => 'no-cache' ];
		$header[] = [ 'Upgrade-Insecure-Requests' => '1' ];
		$header[] = [ 'Pragma' => 'no-cache' ];

		// post data

		if (is_null($pData) == false) {
			// assume binary data
			$header[] = [ 'Content-Type' => 'application/octet-stream' ];
			$header[] = [ 'Content-Length' => strlen($pData) ];
			$option['http']['content'] = $pData;
		}

		// http request header

		$headerLine = [];
		foreach ($header as $headerItem) {
			foreach ($headerItem as $headerName => $headerValue) {
				$headerLine[] = $headerName . ': ' . $headerValue;
			}
		}
		$headerText = implode("\r\n", $headerLine) . "\r\n";

		$option['http']['header'] = $headerText;

		$context = stream_context_create($option);

		$this->_httpResponseText = @file_get_contents($pUrl, false, $context);

		if ($this->_httpResponseText === false) {
			$error = error_get_last();
			throw new \Exception($error['message'], $error['type']);
		}

		// response header

		foreach ($http_response_header as $responseHeader) {

			if (preg_match("/^([^:]+): (.+)$/", $responseHeader, $responseMatch) == false) {

				// HTTP/1.0 200 OK
				if (preg_match("/^HTTP\/[0-9]\.[0-9] ([0-9]+)/", $responseHeader, $codeMatch)) {
					$this->_httpResponseCode = (int) $codeMatch[1];
				}

				continue;
			}

			$headerName = $responseMatch[1];
			$headerValue = $responseMatch[2];

			$this->_httpResponseHeader[] = [ $headerName => $headerValue ];

			// Handle cookie
			if ($headerName == 'Set-Cookie') {
				if (preg_match("/^([^=]+)=([^;]+)/", $headerValue, $cookieMatch)) {
					$this->_httpRequestCookie[$cookieMatch[1]] = $cookieMatch[2];
				}
			}
		}
	}
}
