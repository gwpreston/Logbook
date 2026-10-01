# AI in Logbook

Logbook can use a language model to answer questions about your garage,
draft entries from what you say and read receipts. You choose the model and
where it runs: on this server, on a computer on your network, or with a
cloud provider. **Nothing is sent to any model until an admin sets it up**,
and until then Logbook looks and behaves exactly as it does without AI.

This page covers the setup (Phase 26.1). The features themselves arrive in
later versions: *Ask Logbook*, drafting entries and reading receipts.

- [What AI does and never does](#what-ai-does-and-never-does)
- [Where a model runs](#where-a-model-runs)
- [Setting it up](#setting-it-up)
- [Connection recipes](#connection-recipes)
- [Models, capabilities and Test](#models-capabilities-and-test)
- [Tasks](#tasks)
- [Limits and the usage log](#limits-and-the-usage-log)
- [Keys and secrets](#keys-and-secrets)
- [Which model?](#which-model)
- [Troubleshooting](#troubleshooting)

## What AI does and never does

- **It uses your own entries.** A model sees only what a feature sends it
  for that request. There is no training on your data, no embeddings and no
  copy of your garage kept anywhere else.
- **One model per job, no fallback.** Each task has one connection. If it
  fails, Logbook says so; it never quietly tries another model somewhere
  else.
- **Where data goes is shown.** Every connection is labelled *This server*,
  *Your network* or *Internet*. An internet connection sends nothing until
  an admin has agreed, by name of the host, to send data there.
- **No prompts in logs.** The usage log keeps counts, times and outcomes,
  never questions or answers (unless you switch on `AI_LOG_CONTENT` to
  debug your own install).
- **Admins only.** Only admins add connections and keys. Members use the
  admins' connections, and each member can switch AI features off for
  themselves.
- **Nothing runs inside Logbook.** Models run in their own server
  (Ollama, llama.cpp, LM Studio, vLLM) or with a provider. Logbook only
  calls them.

## Where a model runs

Logbook works out where a connection runs from what its address
**resolves to**, not from how the name looks, and checks again on every
request:

| Label | What counts |
|---|---|
| **This server** | `localhost` and loopback addresses, `host.docker.internal`, `host-gateway`, and any address you list under *This server's addresses* on Settings → AI |
| **Your network** | Private addresses (10.x, 172.16–31.x, 192.168.x), link-local, IPv6 ULA, Tailscale's 100.64.0.0/10, and names ending `.local`, `.lan`, `.internal` or `.home.arpa` that don't resolve publicly |
| **Internet** | Anything else |

A name with any public address is *Internet*. A name such as
`ollama.example.com` that resolves to `192.168.1.20` is *Your network*. If a
network name starts resolving to a public address, the connection becomes
*Internet* and stops until it is acknowledged.

## Setting it up

As an admin, open **Settings → AI**:

1. **Add a connection.** Pick a preset (Ollama, OpenAI, Anthropic, Gemini,
   OpenRouter, Groq, Mistral, Together, DeepSeek, llama.cpp, LM Studio or
   *Other OpenAI-compatible*); it fills in the type and address, which you
   can change. Add a key if the server needs one.
2. **Agree to send data**, if it is an *Internet* connection. The page
   names the host. Changing the address asks again.
3. **Refresh models**, or add one by name for servers that don't list
   them. Only models you **add** are offered to tasks.
4. **Tick what each model can do** (tools, images, JSON output) and run
   **Test**, which confirms it.
5. **Give each task a model** under *Tasks*.

Once a task has a model, every user sees *Use AI features* in Settings and
the AI modules appear on Settings → Modules.

## Connection recipes

### Ollama on the same machine

- **Bare PHP install:** `http://localhost:11434`.
- **Docker, Ollama installed on the host:** `http://host.docker.internal:11434`.
  The bundled `docker-compose.yml` already maps `host.docker.internal` to
  the host (`extra_hosts: host.docker.internal:host-gateway`). On Linux,
  Ollama listens on 127.0.0.1 by default, which a container can't reach;
  set `OLLAMA_HOST=0.0.0.0` for the Ollama service (see below).
- **Docker, Ollama as a sibling container:** start it with the `ai`
  profile and pull a model into it:

  ```bash
  docker compose --profile ai up -d
  docker compose exec ollama ollama pull llama3.2:3b
  ```

  Then add Ollama at `http://ollama:11434`. It resolves to a private Docker
  address, so it shows as *Your network*; to label it *This server*, add
  that network (for example `172.18.0.0/16`) under *This server's
  addresses*. The service runs on the CPU; `docker-compose.yml` has a
  commented GPU stanza for NVIDIA cards.

### Ollama on another computer

On the computer with the GPU:

1. Make Ollama listen on the network: set `OLLAMA_HOST=0.0.0.0` (on Linux,
   `systemctl edit ollama` and add `Environment="OLLAMA_HOST=0.0.0.0"`; on
   macOS, `launchctl setenv OLLAMA_HOST 0.0.0.0` and restart Ollama; on
   Windows, a user environment variable).
2. Allow port 11434 through its firewall, from Logbook's address only if
   you can.
3. In Logbook, add Ollama at its LAN address, e.g.
   `http://192.168.1.20:11434`. It shows as *Your network*.

If Test says a model is not found, run `ollama pull <model>` on that
computer. Ollama's chat endpoint cannot be forced to call a particular
tool, so JSON output uses its JSON Schema mode.

### llama.cpp server, LM Studio and vLLM

All three speak the OpenAI-compatible API; use that type.

- **llama.cpp:** `llama-server -m model.gguf --host 0.0.0.0 --port 8080`,
  then `http://<address>:8080/v1`. Tool calls need its Jinja chat
  templates, on by default in current builds (`--jinja`); older builds need
  the flag. For images, load the model's projector (`--mmproj`). It reads
  `tool_choice` only as `auto`, `none` or `required`, so JSON output uses
  its JSON Schema mode.
- **LM Studio:** start the server in the Developer tab (default port
  1234), then `http://<address>:1234/v1`.
- **vLLM:** `vllm serve <model> --port 8000`, then `http://<address>:8000/v1`.
  For tool calls start it with `--enable-auto-tool-choice` and the
  `--tool-call-parser` for your model.

### A self-hosted model behind a reverse proxy

For `https://ollama.example.com` with authentication at the proxy:

- Put the credentials in **Extra headers**, one per line, for example
  `Authorization: Basic dXNlcjpwYXNz` or `X-Api-Key: …`.
- If the proxy uses a certificate from your own CA, give the CA bundle's
  path on the server rather than switching TLS verification off.
- Logbook never follows redirects: give the final address.

If the name resolves publicly it is an *Internet* connection and needs the
acknowledgement, even though the model is yours.

### OpenAI, Anthropic and Gemini

Create a key with the provider and add it on the connection, or set it in
the environment and type `env:OPENAI_API_KEY` (any variable name) instead.

| Provider | Address | Notes |
|---|---|---|
| OpenAI | `https://api.openai.com/v1` | |
| Anthropic | `https://api.anthropic.com` | JSON output uses structured outputs; older models a tool call |
| Google Gemini | `https://generativelanguage.googleapis.com` | The key is sent in a header, never in the address |

### OpenRouter, Groq, Mistral and other hosted providers

Use the OpenAI-compatible type with the provider's address and key
(presets fill these in). A gateway such as OpenRouter passes each request
to a provider it chooses per model, so its acknowledgement names that too.
OpenRouter's optional `HTTP-Referer` and `X-Title` go in *Extra headers*.
It lists hundreds of models; use the search box, and add only the ones you
want. Tools, images and JSON output vary by model, not by gateway.

## Models, capabilities and Test

Each model has three capabilities:

- **Tools:** it can call Logbook's functions (needed to answer questions);
- **Images:** it can read photos (receipts);
- **JSON output:** it can return a structured answer (reading documents).

Where a provider reports them (OpenRouter, Anthropic, Ollama, llama.cpp)
they are filled in; otherwise tick them. **Test** lists the models, then
for the chosen model asks a short question, a tool call, an image (when
ticked) and JSON output (when ticked), with the time and any error for
each. A tool call or image that fails clears its tick; for JSON it records
which of three ways works (a JSON Schema, plain JSON mode, or a tool call).
Tests count in the usage log like any other request.

## Tasks

| Task | Needs | Used by |
|---|---|---|
| Answering questions | tools | *Ask Logbook*, drafting entries |
| Reading receipts and documents | JSON output (and images, unless your documents are all text) | reading receipts and invoices |
| Reading text PDFs | JSON output | text PDFs; without its own model it uses the one for questions, if that has JSON output |

So text can stay on a small local model while receipts go to a stronger
vision model, or the other way round.

**Temperature** and **Longest answer** are optional. Leave temperature
empty for reasoning models (OpenAI's GPT-5 family, Claude and Gemini with
thinking): some refuse it. A reasoning model also spends part of the
longest answer on thinking, so keep that generous or empty. A model without what a task needs
can't be chosen for it. A task without a model switches its features off.

## Limits and the usage log

Per connection:

- **Largest request** (8 MB by default): bigger requests, usually photos,
  are refused before anything is sent.
- **Monthly token cap** (optional): once this calendar month's tokens (in
  `APP_TIMEZONE`) reach it, the connection pauses until the 1st.
- **Timeout:** 120 seconds for this server and your network (local models
  can be slow to load), 60 for the internet.

Each user has **one AI request at a time**; a second is refused with
"Still working on your last question".

The **usage log** records who, which task, connection and model, tokens,
time and outcome. Settings → AI shows this month per connection and per
task. Rows are deleted after 90 days by the scheduled task.

## Keys and secrets

- A key or header value is **never shown again** after saving, not even
  masked. Type a new one to replace it, or tick *Remove*.
- Keys typed in full are encrypted with a key derived from
  `SESSION_SECRET`. Without a `SESSION_SECRET`, only `env:NAME` keys can be
  saved. **Changing `SESSION_SECRET` makes saved keys unreadable**: the page
  says *Re-enter the key* and nothing is sent until you do.
- `env:NAME` keys are read from the environment each time they are used.
- **Backups** carry connections, models and tasks, but never keys (not
  even `env:` references) or the usage log. After a restore, enter each
  key again.

## Which model?

These are **examples only**. Models change every few months; check what
is current, and try a model with **Test** before relying on it.

| Hardware | Answering questions (tools) | Reading receipts (vision and JSON) |
|---|---|---|
| A Raspberry Pi or small NAS | a cloud model, or a network box | a cloud model |
| 8 GB RAM, CPU only | a 3B model such as `llama3.2:3b` (slow but usable) | a cloud model |
| 16 GB RAM, or a GPU with 8 GB | a 7–8B model such as `qwen2.5:7b` or `llama3.1:8b` | `qwen2.5vl:7b` |
| A GPU with 16 GB or more | a 12–14B model | `gemma3:12b` or a larger vision model |
| Cloud | a small fast model (GPT-5 mini, Claude Haiku, Gemini Flash) | the same, or a larger one for hard-to-read receipts |

## Troubleshooting

| Message | What to check |
|---|---|
| *didn't answer in N seconds* | A local model loading for the first time can take a minute; raise the timeout, or use a smaller model |
| *can't be reached* | The address and port; from Docker, `localhost` is the container itself (use `host.docker.internal` or the LAN address); the other computer's firewall and `OLLAMA_HOST` |
| *refused the key* | The key, or the proxy's header; an `env:` variable that is set for the web server, not only your shell |
| *doesn't have the model* | The model name; for Ollama, `ollama pull` it on that computer |
| *on the internet, and an admin hasn't agreed* | Tick the acknowledgement on the connection's page; it is asked again when the address changes |
| *needs to be entered again* | `SESSION_SECRET` changed, or a backup was restored: type the key again |
| *answered in a way Logbook couldn't use* | The model doesn't support what was asked (tools, JSON); run Test and untick what fails, or choose another model |
| *redirect … not followed* | Give the address the server redirects to (often a missing or extra `/v1`, or `http` instead of `https`) |

The full error text, with keys removed, is in Logbook's log and in each
model's last Test result.
