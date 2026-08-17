# Customy Data SDK for PHP

Dependency-free PHP 8.1+ SDK for governed `track`, `identify`, `group`, `page`,
`screen` and `alias` collection.

```php
$data = new CustomyDataClient(
    'https://data.customy.ai',
    'cdw_your_source_write_key',
    redactFields: ['password', 'cardNumber'],
);

$data->track(
    'Product Viewed',
    ['sku' => 'A-1'],
    ['anonymousId' => 'anon_123'],
);
```

The write key is the only tenant authority. The SDK rejects forged tenant
scope before and after `beforeSend`, applies recursive redaction after the
hook, keeps `messageId` stable across retries, bounds its queue and restores
pending events after partial batch failures. It writes to Customy Data only;
Customy Analytics consumes governed read models.
