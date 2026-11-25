<?php

spl_autoload_register(function ($class) {
    $prefix = 'IMSGlobal\\LTI\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

set_error_handler(function ($severity, $message, $file, $line) {
    if ($severity & (E_DEPRECATED | E_USER_DEPRECATED | E_WARNING | E_NOTICE)) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    return false;
});

use IMSGlobal\LTI\ToolProvider\ContentItem;
use IMSGlobal\LTI\ToolProvider\ContentItemImage;
use IMSGlobal\LTI\ToolProvider\ContentItemPlacement;
use IMSGlobal\LTI\ToolProvider\DataConnector\DataConnector;
use IMSGlobal\LTI\ToolProvider\DataConnector\DataConnector_pdo;
use IMSGlobal\LTI\ToolProvider\MediaType\Message;
use IMSGlobal\LTI\ToolProvider\MediaType\ResourceHandler;
use IMSGlobal\LTI\ToolProvider\MediaType\SecurityContract;
use IMSGlobal\LTI\ToolProvider\MediaType\ToolProfile;
use IMSGlobal\LTI\ToolProvider\MediaType\ToolProxy as ToolProxyMedia;
use IMSGlobal\LTI\ToolProvider\ToolProxy;

function assert_true($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "ok: {$message}\n");
}

$item = new ContentItem('LtiLinkItem', new ContentItemPlacement(100, 80, 'iframe', 'win'), 'https://example.test/item');
$item->setUrl('https://example.test/launch');
$item->setMediaType(ContentItem::LTI_LINK_MEDIA_TYPE);
$item->setTitle('Title');
$item->setText('');
$json = json_decode(json_encode($item), true);
assert_true($json['@type'] === 'LtiLinkItem', 'content item keeps @type');
assert_true($json['@id'] === 'https://example.test/item', 'content item keeps @id');
assert_true($json['url'] === 'https://example.test/launch', 'content item keeps url');
assert_true(!array_key_exists('text', $json), 'empty text stays off the JSON document');

$image = new ContentItemImage('https://example.test/img', 10, null);
$imageJson = json_decode(json_encode($image), true);
assert_true($imageJson['@id'] === 'https://example.test/img', 'image keeps @id');
assert_true($imageJson['height'] === 10, 'image keeps height');
assert_true(!array_key_exists('width', $imageJson), 'omitted width stays off the JSON document');

$profileMessage = (object) [
    'type' => 'basic-lti-launch-request',
    'path' => '/launch',
    'capabilities' => ['User.id'],
    'constants' => ['label' => 'Hello'],
    'variables' => ['User.id' => 'User.id'],
];
$message = new Message($profileMessage, ['User.id']);
$messageJson = json_decode(json_encode($message), true);
assert_true($messageJson['message_type'] === 'basic-lti-launch-request', 'message type is encoded');
assert_true($messageJson['parameter'][0]['name'] === 'label', 'message constant is encoded');

$toolProvider = (object) [
    'consumer' => (object) [
        'profile' => (object) [
            'capability_offered' => ['basic-lti-launch-request'],
            '@context' => ['http://purl.imsglobal.org/ctx/lti/v2/ToolProxy'],
            '@id' => 'https://example.test/profile',
        ],
    ],
    'product' => null,
    'vendor' => null,
    'resourceHandlers' => [
        (object) [
            'item' => (object) ['id' => 'lesson', 'name' => 'Lesson', 'description' => 'A lesson'],
            'icon' => '/icon.png',
            'requiredMessages' => [$profileMessage],
            'optionalMessages' => [],
        ],
    ],
    'baseUrl' => '',
    'requiredServices' => [],
    'optionalServices' => [],
];
$handler = new ResourceHandler($toolProvider, $toolProvider->resourceHandlers[0]);
assert_true($handler->resource_type->code === 'lesson', 'resource handler type is set');

$contract = new SecurityContract($toolProvider, 'secret');
assert_true($contract->shared_secret === 'secret', 'security contract keeps the secret');
assert_true($contract->tool_service === [], 'security contract has no services');

$profile = new ToolProfile($toolProvider);
assert_true($profile->lti_version === 'LTI-2p0', 'tool profile version is set');
assert_true(isset($profile->resource_handler[0]), 'tool profile includes the resource handler');

$service = (object) ['endpoint' => 'https://example.test/proxy'];
$mediaProxy = new ToolProxyMedia($toolProvider, $service, 'secret');
$mediaJson = json_decode(json_encode($mediaProxy), true);
assert_true($mediaJson['@type'] === 'ToolProxy', 'tool proxy media type keeps @type');
assert_true($mediaJson['tool_consumer_profile'] === 'https://example.test/profile', 'tool proxy media type keeps the profile id');

$proxy = new ToolProxy(new DataConnector(null, ''));
assert_true($proxy->created === null, 'new tool proxy has no created timestamp');
assert_true($proxy->enabled === false, 'new tool proxy is disabled');

$db = new class {
    public function getAttribute($attribute)
    {
        return 'oci';
    }
};
$pdo = new DataConnector_pdo($db, 'lti_');
$format = (new ReflectionProperty(DataConnector::class, 'dateFormat'))->getValue($pdo);
assert_true($format === 'd-M-Y', 'Oracle PDO connector uses the dateFormat property');

fwrite(STDOUT, "all passed\n");
