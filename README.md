# php-webdav-client

... is under construction !

WebDav Client written in PHP

## Example

````
$client = new WebDav($WEBDAV_LOCATION, $WEBDAV_USERNAME, $WEBDAV_PASSWORD);

// File Operation

$client->get($remoteFile);
$client->upload($localFile, $remoteFile);
$client->create($remoteFile, $content);

// Folder Operation

$client->list($remoteFolder);
$client->mkdir($remoteFolder);

// Common Operation

$client->delete($remoteFileOrFolder);
````
