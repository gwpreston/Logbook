# Phase 26.5 — MCP server + v2.9 release

*Use Logbook from Claude Desktop, or any assistant that speaks MCP.*

Status: ✅ complete (manual interop check and tag pending) · releases **v2.9.0** · file lives in `docs/phases/`

Phases 26.2 and 26.3 define Logbook's tools: read tools over its services,
and draft tools for new entries. This phase exposes the **same tools** over
the Model Context Protocol, so an MCP client (Claude Desktop, an IDE agent,
a local assistant) can ask Logbook questions with its own model. It runs on
the owner's machine or anywhere else, and needs no connection configured in
Settings → AI.

The client is authorised with a Phase 18.2 API key, so it sees exactly what
the key's user sees. Writes follow the API's rules, not the chat's. A
read-and-write key may log fill-ups and readings; everything else is a
draft that the user confirms in Logbook.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §7.20,
§7.26 and §7.27, and Phases 18.2, 26.2 and 26.3 first.

---

## Goals

1. An MCP endpoint (Streamable HTTP transport) at `{APP_BASE_PATH}/mcp`.
2. The Phase 26.2 read tools, unchanged, as MCP tools.
3. The two API writes (`log_fill_up`, `add_reading`) for `read_write` keys;
   the other Phase 26.3 draft tools create **pending drafts** the user
   confirms in Logbook.
4. A few MCP resources (the vehicle list, the user's units) and prompts
   ("Monthly summary", "Before a service").
5. Setup guides for Claude Desktop and other clients, over the LAN and
   remotely.

## Not in scope

- The stdio transport (a local process). A small `bin/mcp-stdio.php`
  bridge could come later for clients that only speak stdio.
- OAuth for MCP clients. API keys are used, sent as a bearer token.
- File reading over MCP (Phase 26.4's scanning stays in Logbook's own UI).
- Any use of the Settings → AI connections. The client brings its own
  model.

---

## Spec addition (§7.28 MCP server)

> - **Endpoint:** `POST|GET {APP_BASE_PATH}/mcp`, Streamable HTTP as in the
>   MCP specification version current at implementation (recorded in
>   §4). It sits outside the session and CSRF groups, like the API.
>   `MCP_ENABLED` (default `true`) and the `API_ENABLED` switch both apply,
>   and 404 when off.
> - **Auth:** `Authorization: Bearer lbk_…` (a Phase 18.2 key). Scope
>   `read` sees the read tools; `read_write` also sees the write and draft
>   tools. Failed keys get the API's throttling. `Origin` is checked against
>   `API_CORS_ORIGINS` when present, as the MCP spec advises for HTTP
>   servers.
> - **Tools:** the Phase 26.2 read tools with the same schemas, results
>   (raw values, display strings, links as absolute URLs using `APP_URL`)
>   and access rules. Tool descriptions are written for a model and
>   translated to the key user's locale.
> - **Writes:**
>   - `log_fill_up` and `add_reading` call the API's write path directly
>     (validation, duplicate-safe retries, warnings returned). MCP clients
>     ask their user before calling a tool, and these are the same writes an
>     automation can already make.
>   - `draft_service_record`, `draft_document`, `draft_expense`,
>     `draft_tyre_check` and `draft_reminder` create a Phase 26.3 draft,
>     marked as from MCP and kept **7 days**. They return "Draft saved. Open
>     {link} to add it." Logbook lists pending MCP drafts on `/ask` and on the
>     dashboard (*Drafts to review*), each as a draft card with *Add*, *Edit*
>     and *Discard*.
> - **Resources:** `logbook://vehicles` (id, name, registration, fuel type,
>   status), `logbook://me` (units, currency, locale, time zone, tax year),
>   and `logbook://vehicles/{id}/summary`.
> - **Prompts:** `monthly_summary` (last month's costs, fuel, mileage and
>   anything needing attention, per vehicle), `before_service` (a vehicle's
>   last services, what is due, and tyre state), and `sale_checklist` (what
>   the sale pack would show and any gaps).
> - **Logging:** each call is logged in `ai_requests` as task `mcp`, with the
>   key's name and no content (Phase 26.1's rules).

---

## Decisions (and why)

- **The same tools, not new ones.** The tools are already tested for
  correctness, access and grounding. MCP is another way to reach them.
- **API keys, not OAuth.** Keys already exist, are per user and scoped,
  and are what self-hosters use for Home Assistant. OAuth can come if
  clients need it.
- **Direct writes only where the API already allows them.** Logging a
  fill-up is the API's job today. Richer entries stay drafts, because MCP
  clients vary in how clearly they show a write to the user.
- **No Settings → AI dependency.** An owner who uses Claude Desktop can use
  Logbook's tools without configuring any model in Logbook.

---

## Tasks

### Spec and docs
- [x] §7.28 and §9 (`MCP_ENABLED`) in `spec.md`; the Phase 26.5 line in
      §13; remove the MCP line from the roadmap's *After 1.0*.
- [x] `docs/mcp.md`: keys and scopes; configuring Claude Desktop (a remote
      MCP server by URL, with a bearer header, or through a local bridge
      when a client can't send headers); reaching it on the LAN; exposing
      it remotely behind the reverse proxy (TLS, and exempting `/mcp` from
      forward auth as `/api/` is).

### Dependencies
- [x] The official MCP PHP SDK if it fits Slim and PSR-7/15 without a
      framework, else a small Streamable HTTP implementation of what is
      needed (initialise, tools, resources, prompts, JSON-RPC over POST,
      optional SSE for responses). The choice goes in §4.

### Code
- [x] `Action\Mcp\Endpoint` and the MCP adapter over `ToolRegistry`
      (Phase 26.2) and the draft tools (Phase 26.3).
- [x] Resources and prompts.
- [x] Pending MCP drafts on `/ask` and the dashboard; 7-day expiry.
- [x] Translations (en, de) for tool descriptions and prompts.

### Tests
- [x] Protocol: initialise, list tools, call a tool, list and read
      resources, list and get prompts, errors as JSON-RPC errors, with a
      conformance check against the SDK's or the specification's examples.
- [x] Auth: no key, a bad key, a read key calling a write tool → refused;
      throttling; `Origin` check.
- [x] Tools return the same figures as Phase 26.2 for the demo data;
      access rules hold.
- [x] `log_fill_up` is duplicate-safe; drafts appear in Logbook, apply,
      expire.
- [x] Works under `APP_BASE_PATH`; `MCP_ENABLED=false` and
      `API_ENABLED=false` → 404.
- [x] Integration suite green on every engine.
- [ ] **Manual interop check** (in the PR): Claude Desktop and one other MCP
      client, over the LAN and through the reverse proxy.

### Release
- [x] `CHANGELOG.md` **2.9.0**: the MCP server. Upgrade notes: no
      migration beyond the draft source flag; new optional variable.
- [x] Bump `VERSION`, update the README (status, documentation table gains
      `docs/mcp.md`).
- [ ] Tag `v2.9.0` once merged.

---

## Acceptance criteria

1. Claude Desktop, given the URL and a read key, answers "How much did I
   spend on tyres last year?" from Logbook's `costs` tool.
2. With a read-and-write key, it logs a fill-up, and a service record from
   it waits as a draft until the user presses *Add* in Logbook.
3. A key never sees more than its user.
4. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **stdio bridge:** worth shipping `bin/mcp-stdio.php` now for clients that
  can't send headers, or wait until one is needed?
  **Decided 2026-10-01 (#87):** wait. `docs/mcp.md` shows `mcp-remote`
  (`npx mcp-remote <url> --header "Authorization: Bearer …"`), which needs
  Node on the client machine only. The PHP bridge is in spec §12. Checking
  showed why it matters: Claude's custom connectors connect from
  Anthropic's servers (public HTTPS only), and their request-header
  option is still a limited beta.
- **Direct writes:** should `log_fill_up` and `add_reading` also become
  drafts over MCP, for consistency with the chat?
  **Decided 2026-10-01 (#88):** no, direct writes for `read_write` keys,
  through the API's write path. Every other kind is a draft.
- *(Found while starting.)* **Which protocol versions?** The current MCP
  revision, `2026-07-28`, is stateless and has no `initialize`; clients on
  `2025-11-25` and earlier still send it.
  **Decided 2026-10-01 (#89):** dual-era. `2026-07-28` statelessly, and
  `initialize` for `2025-11-25` and `2025-06-18`, also stateless (no
  session id, which that transport makes optional) (spec §7.28).
- *(Found while starting.)* **The MCP PHP SDK or our own?** `mcp/sdk`
  (v0.8.1) is experimental until 1.0, adds five dependencies and registers
  tools by attribute.
  **Decided 2026-10-01 (#90):** our own small implementation, tested
  against the specification's JSON schemas (spec §4, §7.28).
- *(Found while starting.)* **What switches MCP off for a user?** Draft
  tools needed `ai_actions` (#78), and *Use AI features* (#67) is per
  user.
  **Decided 2026-10-01 (#91):** only `MCP_ENABLED`, `API_ENABLED` and the
  key; each tool needs its own module. The AI modules and *Use AI
  features* don't apply (spec §7.28).

## What changed while starting

- `/ask` answers 404 unless Ask is available (AI set up, `ai_ask`, *Use
  AI features*), so MCP drafts are reviewed on the dashboard's *Drafts to
  review* in every case and on `/ask` when Ask is available. Their buttons
  don't need Ask.
- The draft tools' descriptions and results tell the model about a card
  in the chat. Over MCP every tool has its own description (`mcp.tool.*`,
  en and de), and the draft results point to the dashboard instead.
- The roadmap's *After 1.0* list had no MCP line left to remove.
- `GET`/`DELETE` answer 405, responses are always JSON (no SSE), and
  results are `cacheScope: "private"` with `ttlMs: 0`.

## What changed while building

- **The key check is shared.** `ApiKeyAuthenticator` (throttle, key,
  active user, access policy, display preferences) came out of
  `ApiAuthMiddleware`, so the API and `/mcp` let a key in the same way. The
  API's own behaviour is unchanged (its tests pass as they were).
- **Draft tools say whether a user can draft, AI aside**
  (`DraftTool::canDraft`): the kind's module, and some vehicle the user
  may add it to. Ask still also needs `ai_actions`.
- **`log_fill_up` and `add_reading`** run the Ask draft tool in a
  rolled-back transaction (resolution and validation), then write the
  validated body through the same `DraftWriter` → `ApiWriter` path *Add*
  uses, in its own transaction. A tool's question back (`choose_vehicle`,
  `ask_user`, `needs`, `invalid`, `duplicate`) is returned as it is, with
  a line saying what to do next.
- **MCP drafts have their own buttons route**, `POST /drafts/{id}/{action}`.
  It sits in the signed-in group (CSRF) and outside the AI block, so it
  works with `AI_ENABLED=false`. It serves MCP drafts only and sends the
  user back to the page that listed the draft (`back`: dashboard or
  `/ask`). The card markup is shared (`DraftCards`, out of `AskPage`).
- **The usage log** marks a tool's own refusal `error` / `tool_error`, and
  a protocol error `refused` / `rpc_<code>`. `tools/list` and the other
  lists aren't logged.
- **Refusals before the message is read** (401, 403 `Origin`, 429) are
  JSON-RPC errors without an id, with Logbook's own codes outside the
  reserved range (-31401, -31403, -31429).
- **Settings → API keys** shows the MCP address beside the API's.
- **Conformance:** the specification's schemas (`2026-07-28`,
  `2025-11-25`) and its `2026-07-28` examples are copied into
  `tests/Fixtures/mcp` with their licence. `tests/Support/McpClient.php`
  checks every response against them with `justinrainbow/json-schema`,
  now an explicit dev dependency. A spike first confirmed it rejects
  broken copies (no `resultType`, a negative `ttlMs`, an unknown
  `cacheScope`).
- **Docs:** `docs/mcp.md`; the forward-auth examples (`docs/sso.md`,
  `docker/nginx/forward-auth-example.conf`) exempt `/mcp`;
  `docs/configuration.md`, `docs/api.md` and `docs/ai.md` point to it.
- The *Sale checklist* prompt links to the vehicle's sale pack page.
