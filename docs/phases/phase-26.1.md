# Phase 26.1 — AI foundation: connections, models and task routing

*Use whichever model you trust: on this server, on your network, or in
the cloud.*

Status: 📋 planned · ships with Phase 26.2 as **v2.6.0** · file lives in
`docs/phases/`

This phase adds no feature a user sees on its own. It builds what every AI
phase after it uses: a way for an admin to connect Logbook to one or more
model providers wherever they run, choose which model does which job, and
see plainly where data goes.

Three places a model can run, all first-class:

- **On this server:** Ollama or llama.cpp beside Logbook. In Docker this is
  the host or a sibling container, not `localhost` inside the app
  container.
- **On your network:** Ollama on a desktop with a GPU at `192.168.1.20`, or
  a llama.cpp server, LM Studio or vLLM box on the LAN.
- **Remote:** OpenAI, Anthropic or Google Gemini, or a self-hosted model
  reached over the internet (`https://ollama.example.com` behind a proxy
  with a key).

Everything is **off until an admin sets it up**. Logbook without AI stays
exactly as it is, and the app makes no request to any model service until a
connection is configured.

Read [`CLAUDE.md`](../../CLAUDE.md), [`spec.md`](../../spec.md) §5, §9 and
§7.10 first.

---

## Goals

1. **Connections:** any number of named connections to a provider, each
   with an adapter type, a base URL, credentials, a timeout and TLS options.
2. **Four adapters** cover everything: *OpenAI-compatible* (OpenAI itself;
   gateways and hosted providers such as OpenRouter, Groq, Mistral,
   Together, DeepSeek and Fireworks; and local runtimes: Ollama, llama.cpp
   server, LM Studio, vLLM, LocalAI; and any other that speaks the Chat
   Completions API), *Ollama native* (for model listing and pulling
   hints), *Anthropic* and *Google Gemini*.
3. **Models and capabilities:** list each connection's models, and record
   (and test) whether a model does tools, images and structured output.
4. **Task routing:** each AI job (answering questions, drafting entries,
   reading receipts) is assigned a connection and model. So text can stay
   on a local model while receipts go to a stronger vision model, or the
   other way round.
5. **Where data goes** is shown for every connection (*This server*, *Your
   network*, *Internet*), and remote use needs an explicit acknowledgement.
6. **Usage log** without prompts by default; **limits** on time, size and
   monthly tokens.
7. A per-user *Use AI features* switch, and module-level switches for each
   AI feature.

## Not in scope

- Any user-facing AI feature (Phases 26.2–26.5).
- Running models inside the Logbook container.
- Fine-tuning, embeddings or vector search. The AI features call Logbook's
  services instead (Phase 26.2).
- Automatic fallback from one connection to another. Each task has one
  connection; failures are shown, never silently sent elsewhere (see
  *Decisions*).

---

## Spec additions

### §7.25 AI connections (new)

> **Settings → AI** (admin only; the page 404s for others). AI is off until
> at least one connection exists and a task is assigned.
>
> **Connections** (`ai_connections`): name ("Ollama on the desktop"),
> adapter (`openai_compatible` | `ollama` | `anthropic` | `gemini`), base
> URL (with presets, all editable: OpenAI `https://api.openai.com/v1`,
> Anthropic `https://api.anthropic.com`, Gemini
> `https://generativelanguage.googleapis.com`, OpenRouter
> `https://openrouter.ai/api/v1`, Groq `https://api.groq.com/openai/v1`,
> Mistral `https://api.mistral.ai/v1`, Together `https://api.together.xyz/v1`,
> DeepSeek `https://api.deepseek.com/v1`, Ollama `http://localhost:11434`,
> llama.cpp `http://localhost:8080/v1`, LM Studio `http://localhost:1234/v1`,
> and *Other OpenAI-compatible*, where the admin types the URL), API key
> (optional for local), extra headers (optional, for a proxy in front of a
> self-hosted model: `Authorization: Basic …` or a custom header), timeout
> (default 120 s for local and network, 60 s for internet), TLS verification
> (on; can be switched off per connection for a LAN server with a
> self-signed certificate, with a warning), a custom CA bundle path
> (optional), and enabled (bool).
>
> - **Secrets:** an API key or header value is stored encrypted
>   (libsodium `secretbox`, key derived from `SESSION_SECRET` with HKDF,
>   context `logbook-ai`), **or** given as `env:NAME` to read an environment
>   variable at call time. It is never shown again after saving: *Replace*
>   only. Rotating `SESSION_SECRET` makes stored secrets unreadable; the page
>   then says "Re-enter the key" and nothing is sent. Backups carry
>   connections **without** secrets.
> - **Where it runs:** the base URL's host is resolved when saved and when
>   tested, then classed as:
>   - *This server*: loopback, `host.docker.internal`, `host-gateway`, or
>     the address the admin marks as this host;
>   - *Your network*: RFC 1918, link-local, IPv6 ULA, `.local` and `.lan`;
>   - *Internet*: anything else.
>   The class is shown as a badge on the connection, beside every task that
>   uses it, and in the AI features themselves.
> - **Internet connections** need the admin to tick "I understand that
>   questions, the data needed to answer them and uploaded receipts will be
>   sent to {host}". It is recorded with who and when, and cleared when the
>   URL changes.
> - **Private addresses are allowed on purpose.** LAN models are the point.
>   Only admins can set URLs, the URL is never taken from a request, and
>   redirects are not followed.
> - **Test** runs, in order, a model list, a short completion, a tool call
>   and, when the model is marked for images, a tiny image. It reports each
>   result with its time, and the error text on failure (with any key
>   redacted).
>
> **Models** per connection: *Refresh models* lists them (`/v1/models`,
> Ollama `/api/tags`, Anthropic `/v1/models`, Gemini `models.list`). Each
> can also be typed by name, for servers that don't list. Each model has
> capabilities: tools, images, JSON output. Defaults are what the provider
> reports, or none. The admin can tick them and *Test* confirms them. A task
> can only use a model with the capabilities it needs.
>
> **Gateways and hosted providers** (OpenRouter, Groq, Mistral and the
> like) use the OpenAI-compatible adapter with their own URL and key.
> - **Where data goes:** they are *Internet* connections. A gateway such as
>   OpenRouter forwards requests to an upstream provider it chooses per
>   model, so the acknowledgement names both: "…sent to openrouter.ai and
>   the provider it routes each model to".
> - **Extra headers** cover gateway-specific ones (OpenRouter's optional
>   `HTTP-Referer` and `X-Title`).
> - **Long model lists** (OpenRouter lists hundreds) get a search box, and
>   only the models the admin adds to the connection appear in task
>   pickers.
> - **Capabilities** come from what the gateway reports where it reports
>   them (for example OpenRouter's supported parameters and input
>   modalities), else as for any other model: ticked by the admin and
>   confirmed by *Test*. Support for tools, images and JSON output varies by
>   model, not by gateway.
> - **Structured output** uses `response_format: json_schema` where the
>   model supports it, else `json_object` plus schema validation, else a
>   single forced tool call whose arguments are the object. All three pass
>   through the same JSON Schema check. *Test* records which one works.
>
> **Tasks** (`ai_tasks`), each with a connection, model and optional
> settings (temperature, max output tokens):
>
> | Task | Needs | Used by |
> |---|---|---|
> | `ask` | tools | Ask Logbook (26.2), drafting (26.3) |
> | `read_document` | images **or** text only, JSON output | receipt and document reading (26.4) |
> | `read_text` | JSON output | text PDFs (26.4); defaults to `ask`'s model |
>
> A task without an assignment switches its features off, and they say so
> to admins ("Set a model for Ask Logbook in Settings → AI").
>
> **Limits** (per connection): the maximum request size (default 8 MB, for
> images), a monthly token cap (optional; when reached, the connection
> pauses until the next month and the features say why), and one request
> at a time per user (a second waits or is refused with "Still working on
> your last question").
>
> **Usage log** (`ai_requests`): user, task, connection, model, tokens in
> and out (when reported), duration, outcome (`ok` | `error` | `timeout` |
> `refused`), error code, created_at. There are **no prompts or answers**
> unless `AI_LOG_CONTENT=true` (off; for debugging on the admin's own
> install, with a warning on the page). Rows older than 90 days are
> deleted by the scheduler. Settings → AI shows this month's use per
> connection and task.
>
> **Users:** Settings → Account → *Use AI features* (on by default once an
> admin enables AI). Off hides every AI entry point for that user and sends
> nothing on their behalf.
>
> **Modules:** `ai_ask`, `ai_actions` and `ai_scan` join §7.10's toggles.
> They default to on, but do nothing without a configured task.

### §9 Configuration

> `AI_ENABLED` (default `true`; `false` hides Settings → AI and switches
> everything off, whatever is configured), `AI_LOG_CONTENT` (default
> `false`), `AI_ALLOW_INSECURE_TLS` (default `true`: allows the per-connection
> switch; `false` forbids it).

### Adapters (§5)

> `Service\Ai\Provider\ProviderAdapter` with `chat(ChatRequest): ChatResult`
> and `listModels(): list<ModelInfo>`. The request is provider-neutral:
> system text, messages, tools (JSON Schema), images (bytes plus media
> type), response schema, limits. The result holds text, tool calls,
> finish reason and usage. Each adapter maps to its API: Chat Completions
> tools and `response_format`; Anthropic Messages with `tools` and image
> blocks; Gemini `generateContent` with function declarations and
> `responseSchema`; Ollama through its OpenAI-compatible endpoint, with its
> native one only for listing. Adapters use the app's PSR-18 HTTP client
> with the connection's timeout, TLS and headers. They never retry a
> request that may have been processed, and retry once on a connection
> error before any bytes were sent. No provider SDK is required.

---

## Decisions (and why)

- **Connections plus task routing,** not one global provider. An owner
  might run a small text model on the Pi's LAN box and use a cloud vision
  model only for receipts, or keep everything local. Routing makes that a
  setting rather than a fork.
- **An OpenAI-compatible adapter first.** Almost every local and network
  runtime speaks it, so one adapter covers Ollama, llama.cpp, LM Studio,
  vLLM and OpenAI.
- **No automatic fallback.** Falling back from a local model to a cloud one
  would send data somewhere the owner didn't choose for that moment. A
  failure says so, and the owner decides.
- **Location is classed, shown and acknowledged.** "Everything stays on
  your server" must be checkable, not assumed.
- **No prompts in logs by default.** Questions and receipts are personal.
  Counts, times and outcomes are enough to run the feature.

---

## Tasks

### Spec and docs
- [ ] §7.25, §9, §7.10 and §5 in `spec.md`; the Phase 26.1 line in §13;
      remove the MCP line from the roadmap's *After 1.0* when 26.5 lands.
- [ ] `docs/ai.md`: what AI does in Logbook and what it never does;
      connection recipes:
  - Ollama on the same machine (bare PHP: `http://localhost:11434`;
    Docker: `http://host.docker.internal:11434` with `extra_hosts:
    host.docker.internal:host-gateway`, or a sibling `ollama` service);
  - Ollama on another computer (`OLLAMA_HOST=0.0.0.0`, firewall, its LAN
    address);
  - llama.cpp server (`--jinja` for tool calls), LM Studio and vLLM;
  - a remote self-hosted model behind a reverse proxy with a key;
  - OpenAI, Anthropic and Gemini keys.
  It also gives model suggestions by task and hardware, marked as
  examples that change often.
- [ ] `docker-compose.yml`: an optional `ai` profile with an `ollama`
      service and volume (`docker compose --profile ai up -d`), CPU by
      default, with the GPU stanza commented.

### Migrations
- [ ] `ai_connections`, `ai_models`, `ai_tasks`, `ai_requests`; the
      acknowledgement fields; `users` setting for *Use AI features*. Every
      engine, reversible; backups include all but secrets and
      `ai_requests`.

### Code
- [ ] `Service\Ai\Provider\*` adapters, `ChatRequest` and `ChatResult`
      value objects, `ModelInfo`, `Capability` enum.
- [ ] `Service\Ai\ConnectionLocator` (host classing), `SecretBox` (encrypt,
      decrypt, `env:`), `AiGateway` (routes a task to its connection, applies
      limits, logs usage, maps errors to user-safe messages).
- [ ] Settings → AI pages: connections (add, edit, test, refresh models,
      acknowledge), models and capabilities, tasks, usage.
- [ ] *Use AI features* in Settings → Account; the three module toggles.
- [ ] Scheduler: usage log retention; monthly cap reset.
- [ ] Translations (en, de).

### Tests
- [ ] **Recorded fixtures** per adapter (request and response JSON for text,
      tool calls, images, JSON output, errors, timeouts). Adapters are tested
      against them with PSR-18 mocks, so CI needs no network or model.
- [ ] Host classing: loopback, `host.docker.internal`, RFC 1918, IPv6 ULA,
      `.local`, public addresses, a public name resolving to a private
      address (classed by address), and redirects not followed.
- [ ] Internet connections refuse to run without the acknowledgement; a URL
      change clears it.
- [ ] Secrets: never rendered, encrypted at rest, `env:` read at call time,
      unreadable after `SESSION_SECRET` changes (with the right message),
      absent from backups.
- [ ] Limits: size, monthly cap, one at a time per user.
- [ ] Usage log without content; with `AI_LOG_CONTENT=true`, content logged
      and the warning shown; retention.
- [ ] `AI_ENABLED=false` and *Use AI features* off hide everything and send
      nothing.
- [ ] Integration suite green on every engine.
- [ ] **Manual interop check** (in the PR): Ollama (same host and LAN),
      llama.cpp server, OpenAI, Anthropic, Gemini, OpenRouter, Groq and
      Mistral, each passing *Test*.
- [ ] Recorded fixtures include OpenRouter's and Groq's model lists and a
      `json_object` fallback case.

---

## Acceptance criteria

1. An admin adds Ollama on another computer by its LAN address. It shows as
   *Your network*, passes *Test*, and can be assigned to a task.
2. An admin adds a cloud provider. It shows as *Internet*, and nothing is
   sent until the acknowledgement is ticked.
3. Text and receipt reading can use different connections.
4. With no connection, Logbook looks and behaves exactly as before, and no
   request is ever made to a model service.
5. Definition of done (CLAUDE.md §11) holds.

## Open questions

- **Per-user connections:** should members be able to add their own cloud
  key (their own account, their own cost), or are connections admin-only
  (drafted)?
- **Streaming:** stream answers to the browser (faster to first word, more
  moving parts behind proxies), or return them whole (drafted, with a
  progress indicator)?
- **Default for *Use AI features*:** on for every user once an admin
  enables AI (drafted), or off until each user opts in?
