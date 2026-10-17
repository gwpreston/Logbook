# Logbook documentation

Every guide to Logbook, grouped by what you're doing: installing it, using
it, connecting other tools to it, or working on it.

**For:** anyone looking for the guide to something. New to Logbook? Start
with the [README](../README.md), then [Getting started](getting-started.md).

- [Install and run](#install-and-run)
- [Use](#use)
- [Integrate](#integrate)
- [Contribute](#contribute)

## Install and run

| Guide | For |
|---|---|
| [deployment.md](deployment.md) | Docker and bare-PHP installs, reverse proxies and subpaths, the phone app, background jobs, backups, the update check, upgrading |
| [configuration.md](configuration.md) | Every environment variable and its default |
| [reading-files.md](reading-files.md) | Reading text from photos and scanned PDFs (OCR): Tesseract on your server or PaddleOCR as a service, installing either, checking it works, privacy and limits |
| [reverse-proxies.md](reverse-proxies.md) | nginx, Apache, Caddy and Traefik in front of Logbook, at the root or a subpath, with HTTPS: tested examples, what to set, what Logbook trusts from a proxy, common failures |
| [proxmox-lxc.md](proxmox-lxc.md) | Proxmox VE: Logbook in an LXC container, with Docker or PHP 8.4 natively; size, proxy, backups, updates |
| [sso.md](sso.md) | Single sign-on with Authelia, Authentik or Keycloak: setting up the client, linking accounts, groups, switching passwords off, the break-glass link; header sign-in behind a forward-auth proxy (nginx, Traefik, Caddy, the Authentik outpost) and how to deploy it safely |
| [demo-mode.md](demo-mode.md) | Running a public demo that resets itself: seeding an empty database, the reset schedule, what visitors can and cannot do, why it cannot wipe a real instance, stopping being a demo |
| [notification-channels.md](notification-channels.md) | Email, ntfy, Gotify, Telegram, Discord, Pushover, Mattermost, Slack and webhooks; adding a channel |
| [CHANGELOG.md](../CHANGELOG.md) | What changed in each release, with upgrade notes |

## Use

The feature guides follow the nine groups in [features.md](features.md).

| Guide | For |
|---|---|
| [getting-started.md](getting-started.md) | The first run: the first admin, preferences, the first vehicle and fill-up, email, the household, modules and the first backup |
| [features.md](features.md) | Everything Logbook does, in nine groups, each linking to its guide |
| [users-and-sharing.md](users-and-sharing.md) | 1 · Several people on one install: admins, invitations, sharing a vehicle, costs, reminders per person, moving someone out |
| [stations.md](stations.md) | 2 · Fuel stations: your station names as stations, choosing one on a fill-up with what you paid last time, what you paid at each, favourites, your private places and straight-line distances, merging duplicates, chargers, who sees and changes what; live fuel prices (UK Fuel Finder), Cheapest near me and effective cost, Was it worth it?, price alerts, adding a provider adapter |
| [issues.md](issues.md) | 3 · Issues: faults you've noticed and not fixed yet, open, watching and fixed, fixing from either side, look-again reminders, recommended work, the API, Ask and CSV |
| [parts.md](parts.md) | 3 · Parts and modifications: what was fitted and what came off, warranties, distance on the vehicle, reverting, and build spend kept out of running costs |
| [mot-history.md](mot-history.md) | 4 · MOT history from DVSA: getting free credentials, enabling it, what is sent, fetching, the review card, mileage and recalls, *Look up*, the weekly refresh and keep-alive, licence and attribution |
| [reports.md](reports.md) | 5 · True cost per mile or km: what a vehicle really costs to run, what the figure is made of, and why it changed from one year to the next |
| [finance.md](finance.md) | 5 · Finance and lease agreements: entering one from the paperwork, the schedule, what each figure means, estimates and your lender's quote, mileage against the allowance, ending an agreement and selling with finance owing, costs counted once, who can see it |
| [trips.md](trips.md) | 5 · Trips and mileage claims: logging, saved journeys, the business and private split, mileage rates, the claim report and what the figures mean |
| [sale-pack.md](sale-pack.md) | 5 · The sale pack: what a buyer sees, what they never see, saving it as a PDF |
| [incidents.md](incidents.md) | 6 · Incidents, damage and insurance claims: logging, photos kept as taken, linking repairs so costs count once, the claim and repair estimates, reading insurer letters, archiving a car as written off, the claims history for insurance quotes and what the sale pack shows |
| [import.md](import.md) | 9 · Importing CSV files and Fuelio exports: columns, units, what is skipped and why |
| [import-tracktor.md](import-tracktor.md) | 9 · Bringing a whole Tracktor garage across: exporting, the uploads folder, what comes across and what doesn't |

Reminders and notifications (group 7) are in
[notification-channels.md](notification-channels.md), and backups and the
phone app (group 9) in [deployment.md](deployment.md).

## Integrate

| Guide | For |
|---|---|
| [ai.md](ai.md) | AI: connecting a model on this server, your network or the internet (Ollama, llama.cpp, LM Studio, vLLM, OpenAI, Anthropic, Gemini, OpenRouter), where data goes, keys, tasks, limits and which model to pick; *Ask Logbook*: what it answers, sources, the grounding check, conversations and privacy; adding entries by message; reading receipts, documents and insurer letters |
| [api.md](api.md) | The REST API: keys, values, paging, edits, attachments, webhooks and errors, with Home Assistant, Shortcuts, Grafana and Node-RED examples ([OpenAPI file](api/openapi.json)) |
| [mcp.md](mcp.md) | The MCP server: Claude Desktop, Claude Code and other assistants, keys and scopes, on your network or behind your reverse proxy, drafts to review |

## Contribute

| Guide | For |
|---|---|
| [CONTRIBUTING.md](../CONTRIBUTING.md) | Issues, the contribution terms, the development setup and `bin/dev-setup.sh`, tests, assets, docs and screenshots, the repository layout and releasing |
| [translations.md](translations.md) | Adding or improving a language |
| [phases/](phases/) | How Logbook is built, one phase at a time, with the open questions and known issues ([ROADMAP.md](../ROADMAP.md) lists them) |
