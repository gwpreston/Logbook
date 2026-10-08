# MCP server

Logbook speaks the [Model Context Protocol](https://modelcontextprotocol.io)
(MCP), so an assistant can use your logbook with **its own model**: Claude
Desktop, Claude Code, an IDE agent or a local assistant. It can answer
"How much did I spend on tyres last year?" from Logbook's figures, log a
fill-up, and draft a service record for you to add.

It uses the same tools as [Ask Logbook](ai.md), but needs **no AI
connection** in Settings → AI: the assistant brings the model. It is
authorised with an [API key](api.md#keys), so it sees exactly what the
key's user sees. `spec.md` §7.28 has the rules.

- [What it offers](#what-it-offers)
- [Keys and scopes](#keys-and-scopes)
- [The address](#the-address)
- [Claude Desktop](#claude-desktop)
- [Claude Code and other clients](#claude-code-and-other-clients)
- [On your network](#on-your-network)
- [From anywhere, behind your reverse proxy](#from-anywhere-behind-your-reverse-proxy)
- [Drafts to review](#drafts-to-review)
- [Privacy](#privacy)
- [Troubleshooting](#troubleshooting)

---

## What it offers

**Tools.** Every key gets the read tools: `find_vehicles`, `costs`,
`cost_per_distance`, `maintenance`, `vehicle_summary`, `fuel_stats`,
`last_done`, `mileage`, `ownership`, `coming_up`, `documents`, `tyres`,
`trips_summary`, `incidents`, `issues`, `finance`, `stations`,
`cheapest_fuel` and `needs_attention`. Each
returns raw values beside display strings in your units, language and
currency, and a link to the page in Logbook that shows the same.

A **read and write** key also gets:

| Tool | What it does |
|---|---|
| `log_fill_up` | Logs a fill-up (or a charge) **now**, through the API's write path: the same validation, derived amount and warnings as the form. Calling it again with the same fill-up finds it already logged and writes nothing. |
| `add_reading` | Logs an odometer reading now, likewise. |
| `draft_service_record`, `draft_document`, `draft_expense`, `draft_tyre_check`, `draft_reminder`, `draft_incident`, `draft_issue` | **Drafts** the entry and saves nothing. You add it in Logbook ([Drafts to review](#drafts-to-review)). |

A tool shows only while its module is on (no fuel module, no `fuel_stats`
or `log_fill_up`) and while you can add that kind of entry to some vehicle.
`cheapest_fuel` (the cheapest listed fuel near one of your places, a
station, or `near: "here"` with the `lat` and `lng` your client sends,
ranked by effective cost; [stations.md](stations.md#cheapest-near-me))
shows only while an admin has enabled a fuel price provider. A position
sent to it is used for that answer only and never saved.
The AI modules and your *Use AI features* setting don't apply: no Logbook
model is involved.

**Resources** (JSON): `logbook://vehicles` (your vehicles, with their ids),
`logbook://me` (your language, time zone, currency, units and tax-year
start) and `logbook://vehicles/{id}/summary`.

**Prompts:** *Monthly summary* (last month per vehicle), *Before a service*
(a vehicle's last services, what is due, its tyres) and *Sale checklist*
(what the sale pack would show, and the gaps), for clients that offer
prompts.

Tool descriptions and prompts follow the key user's language (English or
German).

## Keys and scopes

Make a key on **Settings → API keys** (or with `bin/api-key.php`), one per
assistant so you can revoke it on its own. Its name is what the usage log
shows (Settings → AI, task *MCP clients*).

- **Read only** for questions. The assistant can't write anything.
- **Read and write** to log fill-ups and readings, and to draft other
  entries for you.

A member's key sees only their own vehicles and those shared with them, at
their share level, exactly as on the pages.

## The address

```
<APP_URL><APP_BASE_PATH>/mcp
```

for example `https://logbook.example.com/mcp`, or
`https://example.com/logbook/mcp` behind a subpath. Settings → API keys
shows it. It is the Streamable HTTP transport, protocol versions
`2026-07-28`, `2025-11-25` and `2025-06-18`, and answers each request with
plain JSON (no streams).

`MCP_ENABLED=false`, or `API_ENABLED=false`, turns it off (a 404).

## Claude Desktop

Claude Desktop can reach Logbook in two ways.

### A custom connector by URL

**Customize → Connectors → Add custom connector**, with the address above.
Choose **No sign-in**, and under **Request headers** add `Authorization`
with the value `Bearer lbk_…` (the word `Bearer`, a space, and your key).

Two things to know:

- Claude's connectors connect **from Anthropic's servers**, not from your
  computer, so Logbook must be reachable from the internet over HTTPS
  ([below](#from-anywhere-behind-your-reverse-proxy)). A LAN address won't
  work this way.
- *Request headers* is a beta that not every account has yet. Without it,
  use the bridge.

### Through the `mcp-remote` bridge (works on your LAN too)

[`mcp-remote`](https://github.com/geelen/mcp-remote) runs on your computer,
next to Claude Desktop, and passes the key on. It needs Node 18 or later on
that computer (not on the Logbook server). In Claude Desktop, **Settings →
Developer → Edit Config**, and add:

```json
{
  "mcpServers": {
    "logbook": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://logbook.example.com/mcp",
        "--header",
        "Authorization:${LOGBOOK_AUTH}"
      ],
      "env": {
        "LOGBOOK_AUTH": "Bearer lbk_your_key_here"
      }
    }
  }
}
```

The key goes in `env`, with no space after the colon in `args`: some
clients (Claude Desktop on Windows among them) mangle spaces inside `args`.
For a plain-HTTP address on your own network, add `"--allow-http"` to
`args`. Restart Claude Desktop, and *logbook* is among its tools.

Then ask: *"How much did I spend on tyres last year?"* Claude calls
`costs`. Clients ask before calling a tool, so you see a write before it
happens.

## Claude Code and other clients

Claude Code:

```bash
claude mcp add --transport http logbook https://logbook.example.com/mcp \
  --header "Authorization: Bearer lbk_your_key_here"
```

Any client that takes a Streamable HTTP server with headers works the same
way. Most use this shape (VS Code, Cursor and others name the keys slightly
differently):

```json
{
  "logbook": {
    "type": "http",
    "url": "https://logbook.example.com/mcp",
    "headers": { "Authorization": "Bearer lbk_your_key_here" }
  }
}
```

A client that can only start a local command (stdio) uses `mcp-remote` as
for Claude Desktop.

**Browser-based clients** send an `Origin` header. Logbook refuses any
origin not listed in `API_CORS_ORIGINS` (a 403), which protects a
LAN install from web pages that try to reach it. List the client's origin
there to allow it; CORS then covers `/mcp` as it covers the API.

## On your network

On a LAN the address is your server's, for example
`http://192.168.1.20:8080/mcp`:

- Use the `mcp-remote` bridge with `--allow-http`, or a client that talks
  HTTP directly. Claude's connectors by URL can't reach a LAN address.
- Plain HTTP sends the key unencrypted across your network. That is what
  the API does on a LAN too; use HTTPS if anyone else shares the network.
- Tailscale or another VPN works the same way, with its address.

## From anywhere, behind your reverse proxy

Expose Logbook as in [deployment.md](deployment.md), with TLS. For `/mcp`:

- **Pass the `Authorization` header** through to PHP, as for the API
  ([deployment.md](deployment.md)).
- **Exempt `/mcp` from forward auth** (Authelia, Authentik), as `/api/`
  is: an MCP client can't sign in at a login page. The examples in
  [sso.md](sso.md) and
  [`docker/nginx/forward-auth-example.conf`](../docker/nginx/forward-auth-example.conf)
  do this.
- Answers are ordinary JSON, so there is nothing to configure for
  streaming. A slow question is one ordinary request.
- Guessing keys is throttled per address (as for the API): after 20 bad
  keys in 10 minutes an address waits 10 minutes.

## Drafts to review

`draft_*` tools save nothing. Logbook keeps the draft for **7 days** and
shows it under *Drafts to review* on the **dashboard** and the
**Insights** page (with or without Ask), as a card with **Add**, **Edit**
and **Discard**. *Add*
writes it exactly as the form would, checked again at that moment; *Undo*
is offered for 10 seconds after. The assistant's answer includes the link
to the card on Insights.

Fill-ups and readings are written at once instead, because they are the
same writes an automation can already make with the API, and MCP clients
ask before calling a tool.

## Privacy

The assistant's model sees what Logbook's tools return to it: your
figures, vehicles and entries. With Claude Desktop or another cloud
assistant, that goes to its provider like anything else you discuss with
it. Use a read-only key, or a member account with fewer vehicles, if you
want it to see less. Logbook logs each tool call, resource read and prompt
(the key's name, the outcome and how long it took) and never the content.

## Troubleshooting

| Answer | Why |
|---|---|
| 401 | No key, a mistyped one, or a revoked one. The header is `Authorization: Bearer lbk_…`; check that your proxy passes it on. |
| 403 | The request carried an `Origin` that isn't in `API_CORS_ORIGINS` (a browser-based client). |
| 404 | `MCP_ENABLED` or `API_ENABLED` is `false`, or the address misses the base path. |
| 405 | Something sent `GET`; the endpoint takes `POST` only. Point the client at the Streamable HTTP transport, not SSE. |
| 429 | Too many bad keys from that address; wait 10 minutes. |
| No write tools | The key is read only, or you can't add that kind of entry to any vehicle. |
