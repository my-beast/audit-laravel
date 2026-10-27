# Schemas: the contract between the SDK and the service

`v1/` is what a host sends and what it gets back. The service (`audit/service/schemas`) holds the original; the Laravel
SDK (`audit/laravel/schemas`) holds a copy, and its `SchemaCopyTest` fails when the copy differs. Change a schema only
together with the SDK, and copy the whole folder.

| File | What |
|---|---|
| `v1/events.schema.json` | `POST /v1/events`: a batch of 1-500 events |
| `v1/events-response.schema.json` | Its `200` answer: one result per event, in order |
| `v1/fixtures/events.json` | A valid batch: both test suites send or check it |

## POST /v1/events

```
POST {MYBEAST_AUDIT_URL}/v1/events
Authorization: Bearer sk_….…            the project's server secret
Content-Type: application/json
Content-Encoding: gzip                  optional
```

An event: `{event_id, occurred_at, action, actor: {type: person|operator|system, id}, target: {type, id}?, subject?,
account?, reason?, context?}`. **Ids only, never names or e-mails**: an e-mail address in an id, the reason or the
context refuses the event. Who an id is, the host's own console says (the project's link templates).

| Answer | Means | The sender |
|---|---|---|
| `200` | Each event's result, in order: `kept` (with its `seq` in the chain), `duplicate` (that `event_id` is kept already, with its `seq`), or `refused` (with the `reason`) | Forgets kept and duplicate; keeps a refused one aside, never resends it as it is |
| `401` | No such active server secret | Keeps everything, says so |
| `413` | Too big: more than 500 events, over 2 MB sent or 8 MB unpacked | Sends the same events in smaller batches |
| `415` | A Content-Encoding other than gzip | Fix the sender |
| `422` | Not a batch: not JSON, no `events` list | Fix the sender; nothing was kept |
| `429`, `5xx`, no answer | Busy or down | Keeps everything and tries again later, in the same order |

The service chains the kept events per project in the batch's order: each carries the hash of the one before it
(`prev_hash`, `hash`), every finished UTC day is sealed with a signed checkpoint, and `audit:verify` names the first
event changed, put in or deleted.
