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

use IMSGlobal\LTI\ToolProvider\DataConnector\DataConnector;
use IMSGlobal\LTI\ToolProvider\DataConnector\DataConnector_mysqli;
use IMSGlobal\LTI\ToolProvider\ResourceLink;
use IMSGlobal\LTI\ToolProvider\ToolConsumer;

final class ProbeConnector extends DataConnector_mysqli
{
    public function escape($value, $addQuotes = true)
    {
        if (is_null($value)) {
            return 'null';
        }
        $value = str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $value);
        if ($addQuotes) {
            $value = "'{$value}'";
        }

        return $value;
    }

    public function loadContext($context)
    {
        return true;
    }

    public function sql(ResourceLink $resourceLink)
    {
        return $this->saveResourceLinkSql($resourceLink, 'NULL', 'NULL', '2026-11-25 00:00:00');
    }
}

function prop($object, $name, $value)
{
    $property = new ReflectionProperty($object, $name);
    $property->setAccessible(true);
    $property->setValue($object, $value);
}

function assert_true($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "ok: {$message}\n");
}

$keyMethod = new ReflectionMethod(DataConnector::class, 'getConsumerKey');
$keyMethod->setAccessible(true);
assert_true($keyMethod->invoke(null, null) === null, 'null consumer key is returned without strlen()');
$long = str_repeat('k', 256);
$hashed = $keyMethod->invoke(null, $long);
assert_true(strpos($hashed, 'sha512:') === 0, 'long consumer key is hashed');
assert_true($keyMethod->invoke(null, 'vermetten') === 'vermetten', 'short consumer key is unchanged');

$idMethod = new ReflectionMethod(DataConnector::class, 'normalizeRecordId');
$idMethod->setAccessible(true);
assert_true($idMethod->invoke(null, null) === null, 'null record id normalises to null');
assert_true($idMethod->invoke(null, '') === null, 'empty record id normalises to null');
assert_true($idMethod->invoke(null, 0) === null, 'zero record id normalises to null');
assert_true($idMethod->invoke(null, 3294) === 3294, 'int record id is kept');
assert_true($idMethod->invoke(null, '3294') === 3294, 'numeric string record id is kept');

$connector = new ProbeConnector(null, '');
$link = new ResourceLink();
prop($link, 'dataConnector', $connector);
prop($link, 'ltiResourceLinkId', 'link-1');
$link->setRecordId(3294);
$link->setSetting('custom_label', '100% complete %s ) AND (');

$link->setContextId(null);
$link->setConsumerId(null);
$sql = $connector->sql($link);
assert_true(strpos($sql, 'WHERE (resource_link_pk = 3294)') !== false, 'missing owner updates by resource_link_pk');
assert_true(strpos($sql, 'AND (resource_link_pk') === false, 'missing owner does not emit an empty comparison');
assert_true(strpos($sql, '(consumer_pk = )') === false, 'consumer comparison is not empty');
assert_true(strpos($sql, '(context_pk = )') === false, 'context comparison is not empty');
assert_true(strpos($sql, '100% complete %s ) AND (') !== false, 'percent signs in settings stay literal');

$owned = new ResourceLink();
prop($owned, 'dataConnector', $connector);
prop($owned, 'ltiResourceLinkId', 'link-2');
$owned->setRecordId(3294);
$owned->setContextId(15);
$contextSql = $connector->sql($owned);
assert_true(strpos($contextSql, 'WHERE (context_pk = 15) AND (resource_link_pk = 3294)') !== false, 'context update keeps both ids');

$consumerLink = new ResourceLink();
prop($consumerLink, 'dataConnector', $connector);
prop($consumerLink, 'ltiResourceLinkId', 'link-3');
$consumerLink->setRecordId(3294);
$consumer = new ToolConsumer(null, $connector);
$consumer->setRecordId(7);
prop($consumerLink, 'consumer', $consumer);
$consumerSql = $connector->sql($consumerLink);
assert_true(strpos($consumerSql, 'WHERE (consumer_pk = 7) AND (resource_link_pk = 3294)') !== false, 'consumer update keeps both ids');

fwrite(STDOUT, "all passed\n");
