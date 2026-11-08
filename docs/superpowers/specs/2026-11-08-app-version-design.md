# App version — design

Date: 2026-11-08. Status: approved. Ticket: TOR-20.

## Intent

A release is a pushed tag like `1.2.0`. After a deploy it is not visible which release is running. The panel shows the tag so the user can tell.

## Goals

- The user menu (top right) shows "Version 1.2.0" to every logged-in user.
- Without a release version (local development) it shows "Version dev".

## Non-goals

- The version in the REST API or the MCP server.
- A link to the release, a hint that a newer version exists.

## Decisions

- **Source:** `.github/workflows/release.yml` passes `APP_VERSION=${{ github.ref_name }}` as a build argument; the `prod` stage of `docker/Dockerfile` turns it into `ENV APP_VERSION`. `config('app.version')` reads `env('APP_VERSION', 'dev')`.
- **Menu item:** a disabled `Action` `version` in `userMenuItems()` with an information icon, sorted before "Abmelden".
