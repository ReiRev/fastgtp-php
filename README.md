# fastgtp-php

A PHP client for [fastgtp](https://github.com/ReiRev/fastgtp), which puts Go engines and KataGo's analysis engine behind an HTTP API.

It needs PHP 8.2 or later and any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client. With [Guzzle](https://docs.guzzlephp.org/) installed, as in Laravel, `Client::create()` builds one for you.

## Install

The package is not on Packagist yet. Add the repository to your `composer.json`, then require it:

```bash
composer config repositories.fastgtp vcs https://github.com/ReiRev/fastgtp-php
composer require reirev/fastgtp:dev-main
```

## Use

```php
use FastGtp\Client;

$client = Client::create('http://localhost:8000');
```

Headers passed to `create()` go with every request. Use them for the credentials of a proxy in front of the server, for example a Cloudflare Access service token:

```php
$client = Client::create('https://fastgtp.example.com', [
    'CF-Access-Client-Id' => $clientId,
    'CF-Access-Client-Secret' => $clientSecret,
]);
```

To use another PSR-18 client, pass it with PSR-17 request and stream factories:

```php
$client = new Client('http://localhost:8000', $httpClient, $requestFactory, $streamFactory);
```

### Analyze with KataGo

`analyze()` sends a query to KataGo's analysis engine and waits until it finishes, reading it again as often as the server's `Retry-After` asks. The query is KataGo's own format, without `id`. See KataGo's [analysis engine documentation](https://github.com/lightvector/KataGo/blob/master/docs/Analysis_Engine.md).

```php
use FastGtp\QueryStatus;

$query = $client->analyze('default', [
    'rules' => 'japanese',
    'komi' => 6.5,
    'boardXSize' => 19,
    'boardYSize' => 19,
    'moves' => [['B', 'Q16'], ['W', 'D4']],
    'analyzeTurns' => [0, 1, 2],
    'maxVisits' => 200,
], timeoutSeconds: 120);

if ($query->status === QueryStatus::Succeeded) {
    foreach ($query->responses as $response) {
        // Winrates and scores are from Black's point of view.
        printf("turn %d: %.1f%%\n", $response['turnNumber'], $response['rootInfo']['winrate'] * 100);
    }
} else {
    // KataGo rejected the query, for example an illegal move.
    echo $query->error['error'];
}
```

`'default'` is the name of a KataGo on the server; `listKataGos()` lists them. A query that does not finish in time is deleted, which stops it, and `analyze()` throws `TimeoutException`.

To poll yourself, use `createQuery()`, `getQuery()` and `deleteQuery()`.

### Play a game

```php
use FastGtp\Color;

$session = $client->createSession();
$client->setBoard($session->id, 19, 6.5);
$client->play($session->id, Color::Black, 'Q16');
$move = $client->generateMove($session->id, Color::White);  // $move->vertex, or $move->isResignation()
$client->undo($session->id);
$client->deleteSession($session->id);
```

`loadSgf()` sets up the board from an SGF game record instead.

### Errors

An error response from fastgtp is thrown as `ProblemException`. Its `is()` takes the name of a problem type in fastgtp's [`docs/problems.md`](https://github.com/ReiRev/fastgtp/blob/main/docs/problems.md):

```php
use FastGtp\ProblemException;

try {
    $client->play($session->id, Color::White, 'Q16');
} catch (ProblemException $e) {
    if ($e->is('illegal-move')) {
        // ...
    }
    // $e->status, $e->detail, $e->field, $e->retryAfter
}
```

A response with a status outside 2xx, such as a proxy's redirect to its login page, is a `ProblemException` too, and so is a body that is not JSON, which keeps its 2xx status. Errors from the HTTP client itself, such as a refused connection, are thrown as they are. An empty, `.` or `..` id throws `InvalidArgumentException`.

## Development

```bash
composer update
composer test
composer format-check
```

The integration tests need a running fastgtp server with a KataGo named `default`. CI starts one with GNU Go and fastgtp's fake analysis engine:

```bash
FASTGTP_URL=http://127.0.0.1:8000 composer test-integration
```

They check every response against the server's `/openapi.json`, so they fail when the client and fastgtp's spec disagree.

## License

[Apache License 2.0](LICENSE).
