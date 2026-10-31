# fastgtp-php

A PHP client for [fastgtp](https://github.com/ReiRev/fastgtp), an HTTP API for Go engines and KataGo analysis.

## Layout

- `src/HttpTransporter.php`: internal; sends the requests and turns a failed request, including the HTTP client's own exceptions, into `FastGtpException`.
- `src/Fields.php`: internal; reads response fields and refuses a missing or mistyped one, which becomes `FastGtpException`.
- `tests/Unit/`: tests against a fake PSR-18 client (`FakeHttp`).
- `tests/Integration/`: tests against a running fastgtp server with GNU Go and KataGo. `SpecCheckingHttp` checks every response against the server's `/openapi.json`.
- `tests/server/`: a Docker image of that server, with a fixed fastgtp commit and KataGo's CPU build. CI uses it too.

## Commands

```bash
composer update
composer test
composer format-check
docker compose -f tests/server/compose.yaml up -d --build --wait
FASTGTP_URL=http://127.0.0.1:8000 composer test-integration
docker compose -f tests/server/compose.yaml down
```

## Rules

- **Public repository.** Do not write host names, IP addresses, home directory paths, people's names or details of anyone's servers or network in code, commits, issues or pull requests.
- **fastgtp's spec is the source.** Methods, field names and errors follow fastgtp's `openapi.yaml` and `fastgtp/katago/openapi.yaml`. Change fastgtp first; the integration tests check the client against the server's document.
- **Small pull requests.** Keep each pull request under about 400 added lines.
- **Comments** explain why, not what. Write comments, docs and pull request text in plain English.
- **Formatting.** Format with `composer format` (Pint, PER preset).
