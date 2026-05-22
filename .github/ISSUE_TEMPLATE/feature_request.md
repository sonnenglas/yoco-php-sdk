---
name: Feature request
about: Suggest a new feature, endpoint, or improvement
title: "[feature] "
labels: enhancement
assignees: ''
---

## Use case

<!--
Describe the problem you are trying to solve. Concrete user-facing scenarios
are more compelling than abstract API additions — "I need to issue partial
refunds from an admin dashboard" beats "please add refunds".
-->

## Current workaround

<!--
If you are solving this today without the SDK (raw HTTP calls, forked code,
manual webhook parsing, etc.), describe it here. This helps us understand the
gap and decide whether the feature belongs in the SDK or in your application.
-->

## Proposed API

<!-- Sketch the call site you would like to write. Pseudo-code is fine. -->

```php
<?php

use Sonnenglas\Yoco\Client;

$client = new Client(secretKey: 'sk_test_...');

// Your proposed API here.
```

## Yoco API endpoint reference

<!--
Link to the Yoco developer documentation page describing the endpoint or
capability this feature would wrap.

- Yoco developer docs: https://developer.yoco.com/online/
-->

- Endpoint:
- Docs link:

## Alternatives considered

<!-- Other approaches you considered and why you rejected them. -->

## Additional context

<!-- Screenshots, error messages, related libraries, etc. -->
