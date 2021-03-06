# Configuration

Create an `wishlist.php` file under your `/config` directory with the following options available to you. You can also use multi-environment options to change these per environment.

```php
<?php

return [
    '*' => [
        'pluginName' => 'Wishlist',
        'allowDuplicates' => false,
        'purgeInactiveLists' => false,
        'purgeInactiveListsDuration' => 'P3M',
    ]
];
```

### Configuration options

- `pluginName` - If you want to change the plugin name in the control panel.
- `allowDuplicates` - Whether to allow duplicates in lists.
- `purgeInactiveLists` - Whether to purge inactive lists after a certain duration.
- `purgeInactiveListsDuration` - If purging inactive lists is enabled, after this duration they will be purged.

## Control Panel

You can also manage configuration settings through the Control Panel by visiting Settings → Wishlist.
