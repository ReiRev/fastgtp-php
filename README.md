# fastgtp-php

A PHP client for [fastgtp](https://github.com/ReiRev/fastgtp), which puts Go engines and KataGo's analysis engine behind an HTTP API.

It needs PHP 8.2 or later and any [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client, such as Guzzle.

## Install

The package is not on Packagist yet. Add the repository to your `composer.json`, then require it with an HTTP client:

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/ReiRev/fastgtp-php" }]
}
```

```bash
composer require reirev/fastgtp:dev-main guzzlehttp/guzzle
```

## Connect

```php
use FastGtp\FastGtpClient;

$client = new FastGtpClient('http://localhost:8000');
```

Without a second argument, the client finds an installed PSR-18 client. Pass one to set a timeout, and pass headers that every request must carry, for example the credentials of a proxy in front of the server:

```php
$client = new FastGtpClient(
    'https://fastgtp.example.com',
    new GuzzleHttp\Client(['timeout' => 30]),
    ['Authorization' => 'Bearer ' . $token],
);
```

## Play a game

Each session is one engine process on the server.

```php
use FastGtp\Color;

$session = $client->createSession();
echo $session->engine()->name;              // "GNU Go"

$session->setBoard(9, 6.5);
$session->play(Color::Black, 'E5');
$move = $session->generateMove(Color::White);
echo $move->vertex;                         // such as "C3", "PASS" or "RESIGN"

echo $session->board()->sgf;
$session->undo();
$session->loadSgf('(;GM[1]SZ[9];B[ee];W[cc])', 1);   // only the first move

$session->delete();                         // stops the engine
```

A session lives on the server until it is deleted, so a web app can keep its id and come back to it in a later request. `session()` sends nothing; it only makes a handle.

```php
$id = $client->createSession()->id;          // store it, for example in the user's session
// ... a later request:
$client->session($id)->play(Color::Black, 'D4');
```

## Analyze with KataGo

This needs a server with fastgtp's KataGo extension turned on. A query is a query of [KataGo's analysis engine](https://github.com/lightvector/KataGo/blob/master/docs/Analysis_Engine.md), without `id`.

```php
use FastGtp\KataGo\QueryStatus;

$katago = $client->katago('default');

$result = $katago->analyze([
    'rules' => 'japanese',
    'komi' => 6.5,
    'boardXSize' => 19,
    'boardYSize' => 19,
    'moves' => [['B', 'Q16'], ['W', 'D4']],
    'analyzeTurns' => [2],
    'maxVisits' => 100,
], 60);

if ($result->status === QueryStatus::Succeeded) {
    $response = $result->responses[0];
    echo $response['rootInfo']['winrate'];  // Black's, from 0 to 1
    echo $response['moveInfos'][0]['move'];
} else {
    echo $result->error['error'];           // such as "Illegal move 1: E5"
}
```

`analyze()` waits until the query has finished, as often as the server's `Retry-After` asks. If it has not finished in time, it deletes the query and throws `FastGtp\KataGo\TimeoutException`.

The responses are arrays as KataGo writes them, one for each analyzed turn. fastgtp reports winrates, scores and ownership from Black's point of view. `$client->katagos()` lists the KataGos the server runs, and `$katago->version()` and `$katago->models()` describe one.

To avoid holding a request open while KataGo works, queue the query, keep its id and read it later:

```php
$id = $katago->createQuery($query)->id;
// ... a later request:
$query = $client->katago('default')->query($id);
$result = $query->get();
if ($result->isFinished()) {
    $query->delete();                       // once the result is kept; deleting earlier stops it
} else {
    // ask again in $result->retryAfter seconds
}
```

## Errors

Every error response and every failed request throws `FastGtp\FastGtpException`. For an error response, its fields come from the server's problem details, and `is()` tells the [problem types](https://github.com/ReiRev/fastgtp/blob/main/docs/problems.md) apart:

```php
use FastGtp\FastGtpException;

try {
    $session->play(Color::White, 'E5');
} catch (FastGtpException $e) {
    if (!$e->is('illegal-move')) {
        throw $e;
    }
    // the point is taken, or the move is not allowed
}

try {
    $session = $client->createSession();
} catch (FastGtpException $e) {
    if (!$e->is('too-many-sessions')) {     // queue-full is the same for createQuery()
        throw $e;
    }
    // try again in $e->retryAfter seconds
}
```

`$e->status` is the HTTP status, or null when no response arrived (then `getPrevious()` holds the HTTP client's exception) and for `TimeoutException`. A response that is not what fastgtp sends also throws `FastGtpException`. Mistakes in the call itself throw PHP's own exceptions: `InvalidArgumentException` for an empty, `.` or `..` id, and `JsonException` for a query that cannot be written as JSON, such as one with `NAN`.

## Development

```bash
composer update
composer test
composer format-check
```

The integration tests run against a real server. `tests/server` builds one in Docker, with GNU Go, KataGo's CPU build and a fixed fastgtp commit:

```bash
docker compose -f tests/server/compose.yaml up -d --build --wait
FASTGTP_URL=http://127.0.0.1:8000 composer test-integration
docker compose -f tests/server/compose.yaml down
```

Set `FASTGTP_PORT` to use another port than 8000.

## License

[Apache License 2.0](LICENSE).
