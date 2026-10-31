# fastgtp-php

A PHP client for [fastgtp](https://github.com/ReiRev/fastgtp), an HTTP API for Go engines and KataGo analysis.

## Layout

- `src/Client.php`: the entry point. It creates sessions and hands out `Session` handles, which hold an id and send one request per method.
- `src/Http.php`: internal; sends the requests and turns error responses into `ProblemException`.
- `src/`: also the read-only value classes the handles return, such as `Board` and `Move`.
- `tests/Unit/`: tests against a fake PSR-18 client (`FakeHttp`).
- `tests/Integration/`: tests against a running fastgtp server. `SpecCheckingHttp` checks every response against the server's `/openapi.json`.

## Commands

```bash
composer update
composer test
composer format-check
FASTGTP_URL=http://127.0.0.1:8000 composer test-integration
```

## Rules

- **Public repository.** Do not write host names, IP addresses, home directory paths, people's names or details of anyone's servers or network in code, commits, issues or pull requests.
- **fastgtp's spec is the source.** Methods, field names and errors follow fastgtp's `openapi.yaml` and `fastgtp/katago/openapi.yaml`. Change fastgtp first; the integration tests check the client against the server's document.
- **Small pull requests.** Keep each pull request under about 400 added lines.
- **Comments** explain why, not what. Write comments, docs and pull request text in plain English.
- **Formatting.** Format with `composer format` (Pint, PER preset).
