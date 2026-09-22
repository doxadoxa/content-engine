# Native page acceptance fixture

`verify.php` exercises the application's actual proposal, approval, native HTTP publication, duplicate-delivery reconciliation, public verification and guarded recovery paths. It runs only in local/testing console environments against the two named Docker fixtures. Model responses are explicitly synthetic; HTTP delivery and database history are real. It creates a clearly labelled paused test project and leaves its evidence available for review.

Run from the application's directory after migrating the local database and starting the matching receiver fixture:

```sh
docker compose exec -T app php packages/native-page-fixture/verify.php wordpress < /private/tmp/avyo-wordpress-fixture-connection.json
docker compose exec -T app php packages/native-page-fixture/verify.php cleaningpoint < /private/tmp/cleaningpoint-page-fixture-connection.json
```

Connection documents contain fixture-only secrets; keep them private and pass them through standard input. Do not commit or print them. See the WordPress package for fixture setup. Cleaning Point's representative custom receiver is exercised on the separate local site at port 8094 using an isolated fixture database, not the production site.

Each journey checks a service page and an article, including a text change and title change, and restores the source by guarded recovery. A failed public check remains failed in history even if a later reviewed revision succeeds. The harness cannot establish production compatibility, actual form submissions, layout quality, customer purchases or search results. Retained zero-second synthetic review events are not human-effort evidence.

The private-network exception used here is limited to the named loopback fixture ports in local/testing console execution. It cannot be enabled by a user-supplied website URL or a production web request.
