# engine-receiver

Receives published content from the Content Engine. Endpoint, signature check,
idempotency, storage and a minimal render — the whole of §6.3.

```sh
composer require persistance/engine-receiver
php artisan migrate
```

Then one value in `.env`:

```
ENGINE_RECEIVER_SECRET=the-same-secret-the-channel-holds
```

The endpoint is live at `POST /engine/webhook`. Point a webhook channel at it,
send a `ping`, and you are connected.

## What it guarantees

- **Nothing unsigned gets in.** The signature and its timestamp are checked by
  middleware inside this package, not by code you write — a receiver that can
  forget the check eventually forgets it.
- **A repeat is never a second publication.** `delivery_id` is a unique column;
  the second arrival answers 409, which the contract defines as success.
- **`public_url` goes back.** The engine stores it on the unit and matches
  search-console data against it later. Override `engine-receiver.public_url`
  with a closure if your URLs are not `/{locale}/{slug}`.

The contract itself is `product/webhook-publish-adapter-spec.md` in the engine.
