<?php
return [
    'routes' => [
		['name' => 'api#index', 'url' => '/api', 'verb' => 'GET'],
        ['name' => 'settings#get', 'url' => '/settings/get', 'verb' => 'GET'],
        ['name' => 'settings#set', 'url' => '/settings/set', 'verb' => 'POST'],
        ['name' => 'document#create', 'url' => '/new_document', 'verb' => 'GET'],

		// Standard WOPI host endpoints, called by the web office server.
		['name' => 'wopiEditor#launch', 'url' => '/wopi/launch/{fileId}', 'verb' => 'GET'],
		['name' => 'wopi#checkFileInfo', 'url' => '/wopi/files/{fileId}', 'verb' => 'GET'],
		['name' => 'wopi#getFile', 'url' => '/wopi/files/{fileId}/contents', 'verb' => 'GET'],
		['name' => 'wopi#putFile', 'url' => '/wopi/files/{fileId}/contents', 'verb' => 'POST'],
		['name' => 'wopi#postFile', 'url' => '/wopi/files/{fileId}', 'verb' => 'POST']
    ]
];
