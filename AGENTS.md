# AI Agent Notes

This repo was extended with AI assistance for planning, tradeoff review, and testing ideas.

## Working conventions

- Keep the existing PHP/SQLite structure simple.
- Prefer minimal procedural changes over introducing frameworks.
- Use SQL migration files instead of editing `schema.sql`.
- Keep opaque share tokens for recipient access.
- Use human-readable IDs as document references only.
- Add tests following the existing `tests/test.php` structure.

## Feature decisions

### Scheduled publishing
- Added nullable `publish_at`
- Future scheduled documents display a "Not yet available" message

### Human-readable IDs
- Added `public_id`
- IDs are readable but not used as security credentials

### Search
- Implemented case-insensitive partial title matching with SQLite `LIKE`

## Validation

Verified with:

- `docker compose up`
- `docker compose exec app php tests/test.php`

## AI usage

AI assistance was used for:
- evaluating implementation approaches
- reviewing tradeoffs
- brainstorming edge cases
- validating test coverage

One suggestion I intentionally rejected was replacing secure share tokens entirely with human-readable IDs, because readable IDs are easier to guess and should not be the sole access-control mechanism.