<?php
return [
    'routes' => [
		['name' => 'api#index', 'url' => '/api', 'verb' => 'GET'],
        ['name' => 'settings#get', 'url' => '/settings/get', 'verb' => 'GET'],
        ['name' => 'settings#set', 'url' => '/settings/set', 'verb' => 'POST'],
        ['name' => 'document#create', 'url' => '/new_document', 'verb' => 'GET'],

		// 호스트 페이지. 로그인한 사용자가 열면 새 토큰으로 편집기를 띄운다.
		// CheckFileInfo 의 HostEditUrl·HostViewUrl 로 웹오피스에 알려 주어, 토큰이 만료될 때 다시 연다.
		['name' => 'wopiEditor#edit', 'url' => '/edit/{fileId}', 'verb' => 'GET'],
		['name' => 'wopiEditor#view', 'url' => '/view/{fileId}', 'verb' => 'GET'],

		// Standard WOPI host endpoints, called by the web office server.
		['name' => 'wopiEditor#launch', 'url' => '/wopi/launch/{fileId}', 'verb' => 'GET'],
		['name' => 'wopiFiles#checkFileInfo', 'url' => '/wopi/files/{fileId}', 'verb' => 'GET'],
		['name' => 'wopiFiles#getFile', 'url' => '/wopi/files/{fileId}/contents', 'verb' => 'GET'],
		['name' => 'wopiFiles#putFile', 'url' => '/wopi/files/{fileId}/contents', 'verb' => 'POST'],
		['name' => 'wopiFiles#postFile', 'url' => '/wopi/files/{fileId}', 'verb' => 'POST'],
		['name' => 'wopiFiles#enumerateAncestors', 'url' => '/wopi/files/{fileId}/ancestry', 'verb' => 'GET'],

		['name' => 'wopiEcosystem#getEcosystem', 'url' => '/wopi/files/{fileId}/ecosystem_pointer', 'verb' => 'GET'],
		['name' => 'wopiEcosystem#checkEcosystem', 'url' => '/wopi/ecosystem', 'verb' => 'GET'],
		['name' => 'wopiEcosystem#getRootContainer', 'url' => '/wopi/ecosystem/root_container_pointer', 'verb' => 'GET'],

		['name' => 'wopiContainers#checkContainerInfo', 'url' => '/wopi/containers/{containerId}', 'verb' => 'GET'],
		['name' => 'wopiContainers#postContainer', 'url' => '/wopi/containers/{containerId}', 'verb' => 'POST'],
		['name' => 'wopiContainers#enumerateChildren', 'url' => '/wopi/containers/{containerId}/children', 'verb' => 'GET'],
		['name' => 'wopiContainers#enumerateAncestors', 'url' => '/wopi/containers/{containerId}/ancestry', 'verb' => 'GET'],
    ]
];
