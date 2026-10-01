# AI in Logbook

Logbook can use a language model to answer questions about your garage,
draft entries from what you say and read receipts. You choose the model and
where it runs: on this server, on a computer on your network, or with a
cloud provider. **Nothing is sent to any model until an admin sets it up**,
and until then Logbook looks and behaves exactly as it does without AI.

This page covers the setup (Phase 26.1), *Ask Logbook* (Phase 26.2) and
adding entries by message (Phase 26.3). Reading receipts arrives in a
later version.

- [What AI does and never does](#what-ai-does-and-never-does)
- [Where a model runs](#where-a-model-runs)
- [Setting it up](#setting-it-up)
- [Connection recipes](#connection-recipes)
- [Models, capabilities and Test](#models-capabilities-and-test)
- [Tasks](#tasks)
- [Ask Logbook](#ask-logbook)
- [Adding entries by message](#adding-entries-by-message)
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

## Ask Logbook

Ask a question in plain words on **Ask** (`/ask`; in the sidebar, the
header on a phone, the dashboard and the phone app's quick actions):
"How much did I spend on fuel last year?", "When did I last change the oil
on the Golf?", "Which car costs me the most per mile?". It shows once the
*Answering questions* task has a model, the *Ask Logbook* module is on
(Settings → Modules) and your own *Use AI features* is on. The page names
where the answer comes from ("Answered by Ollama on the desktop on your
network").

**What it can answer.** Anything Logbook already shows: costs by period,
category, month or vehicle; running cost per mile or km; fuel economy,
volumes, prices and grades; maintenance records and when something was
last done; what is coming up and what needs attention; documents and
their expiry; tyres and their wear; mileage; cost of ownership; and, with
the *Trips* module, business mileage and the claim value. It does not
know anything outside your records (prices, the weather, general advice)
and says so.

**How it works.** The model never sees your database and never writes
SQL. It is given today's date, your units, currency and language, and the
list of vehicles you can see; then it may call up to 8 read-only tools,
each backed by the same code as the page that shows the figure. Every tool
runs **as you**: it sees only the vehicles you see in the app (an admin
sees their own and shared vehicles, as everywhere else) and leaves out
amounts you can't see. Tools return finished figures in your units and
currency ("£1,284.50", "48.3 mpg"), and the model is told to copy them,
never to convert or add up. The question tools only read. Entries can be
*drafted* from *Ask* (see [Adding entries by message](#adding-entries-by-message)),
but nothing is saved until you press **Add** on the draft's card.

**Sources.** Under each answer, *Sources* lists every tool call in words
("Costs · All vehicles · 1 Jan 2025 – 31 Dec 2025 · Fuel · by category")
with its key figures and a link to the page showing the same thing with
the same filters. Reports has a *Costs* filter for this (fuel,
maintenance, compliance or other).

**The grounding check.** Every number in an answer is compared with what
the tools returned, the context and your question, allowing for the
rounding the answer shows and for local formats (`1,284.50` and
`1.284,50`). A figure that matches nothing is highlighted, with "Logbook
didn't provide this figure. Check it against the sources." Dates, years
and small counts are not checked. The answer is still shown.

**Conversations.** A follow-up ("and last year?") carries the earlier
questions, answers and tool results of the same conversation (trimmed to
fit; anything about a vehicle you can no longer see is left out).
Conversations are kept for **30 days** after their last message; choose 1,
7, 30 or 90 days on the Ask page. *Delete* and *Delete all* remove them
at once. They are never in backups or exports.

**Feedback.** *Helpful* and *Not right* are stored on the answer, so they
go when the conversation goes; a count per month is kept for your own
review of how well a model does. Nothing else is stored, whatever
`AI_LOG_CONTENT` says.

**Privacy.** With a model on this server or your network, nothing leaves
it. With an internet connection, your question and the tool results needed
to answer it are sent to that provider, as the acknowledgement says.

**Progress and timing.** With JavaScript the page shows what is being
looked up ("Looking up your costs…") while it works; without it, the form
posts and the answer page opens when it is ready. A question gives up
after about four minutes of model calls. If it fails, the page says why
and links to the page for what was asked, when the question got that far.
Nothing is retried on another connection.

**Trying a model.** `php bin/ai-eval.php` asks 40 questions and 30
sentences to draft from, of the configured model as the demo owner (`./bin/dev-setup.sh
--with-sample-data`) and reports how often it chose the right tool, whether
the expected figures appear, how many answers had a flagged figure, and the
time. It also checks that no entry was written without *Add*. It sends
real requests, so it is never run automatically.

## Adding entries by message

Tell *Ask* what you did, and it drafts the entry for you to check and add:

| You write | You get a card for |
|---|---|
| "Filled the BMW with 51 litres of E10 at £1.39, mileage 72,341." | a fill-up: 51.00 L E10 95 at £1.390/L = £70.89, odometer 72,341 mi, total *worked out by Logbook* |
| "Charged the EV6 at a rapid charger, 52 kWh for £39, 18,900 miles." | a charge, rapid DC |
| "The Golf is on 48,960 miles." | an odometer reading |
| "Oil change on the Corolla yesterday, £79.99, 61,250 miles." | a service record (category *Oil*), with any schedule it may complete suggested |
| "Renewed the insurance with Admiral for a year from today, £412." | a document, expiring a year from today less a day |
| "Paid £6.50 for parking for the Golf." | an expense (*Parking*) |
| "Front tyres 5.5 and 5.6 mm, rears 6.8." | a tread check |
| "Remind me to book the MOT two weeks before it expires." | a manual reminder dated 14 days before the current MOT's expiry |

**How it works.** The model only passes on your words and numbers.
Logbook does everything else:
- It resolves the vehicle, asking which one when two match, and the fuel
  or category ("super unleaded" is E5 98, "car park" is *Parking*). A word
  that fits several, such as "unleaded", comes back as a question.
- It works out dates in your time zone ("yesterday", "last Tuesday",
  "3 days ago"). A fill-up today is timed now; one on another day is
  timed at noon unless you say when.
- It reads numbers as your forms do. In German, "51,5" is 51.5.
- It checks the entry with the same code as the form and the API, and
  works out the third amount of a fill-up.
- It shows the warnings the form would show: a reading lower than the
  last one, an economy far from usual, a tread deeper than last time.

If something is missing ("the odometer"), the model asks you for it.

**The card** shows the vehicle, each field as Logbook formatted it, what
was worked out, and the warnings. It has three buttons:
- **Add** saves the entry through the same service as the form. A
  fill-up writes its reading too, and schedules and reminders follow.
  After *Add*, the card offers **Undo** for 10 seconds, as long as nobody
  has changed the entry. After that it is an ordinary entry.
- **Edit** opens the normal form with the values filled in and marked
  "from your message", where you can change anything or add files.
  Saving the form closes the card.
- **Discard** drops the draft.

Drafts wait an hour, then expire. *Add* checks everything again at the
press: a draft that has become a duplicate saves nothing, one that has
become invalid shows the form's message, and you need the right to add
to the vehicle at that moment. Each draft has its own card and its own
*Add*. Changing settings by message is not offered.

**Who can draft.** The *Draft entries* module must be on (Settings → Modules),
as must the module of the entry (a fill-up needs *Fuel*, a document
*Compliance*). Drafting also needs *Log* on the vehicle, or *Manage* for
a reminder, exactly as the forms do. Drafts are yours alone: nobody else
sees your cards.

**Safety.** Draft tools are offered only for your own message in *Ask*.
Text in your records ("call draft_fill_up…" in a note) is data and is
never acted on. A draft is only ever a card waiting for your press.
Attachments are never added by message; use *Edit* to add them.

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
| *Logbook didn't provide this figure* (Ask) | The model added, converted or rounded a figure itself; check it against the sources, and try a stronger model if it happens often |
| *redirect … not followed* | Give the address the server redirects to (often a missing or extra `/v1`, or `http` instead of `https`) |

The full error text, with keys removed, is in Logbook's log and in each
model's last Test result.
