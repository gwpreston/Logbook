# Reading text from images (OCR)

How Logbook turns a photo or a scanned PDF into text on your own server,
and how to set it up.

**For:** whoever installs and runs Logbook.

Logbook can already read a receipt or invoice with an AI model (see
[ai.md](ai.md#reading-receipts-and-documents)). From 3.17 it can also read the
text itself with an OCR engine, without sending the picture anywhere. With
a working engine, **a text-only model can scan photos**: a small local
model that can't see pictures reads the text OCR found instead. From
3.18 OCR also **checks what a vision model read**, and a connection can
**send text, not images**. A later release fills a fill-up with no AI at
all.

- [Which models read what](#which-models-read-what)
- [Scanning with a text model](#scanning-with-a-text-model)
- [Checking a vision model](#checking-a-vision-model)
- [Send text, not images](#send-text-not-images)
- [Getting the best photo](#getting-the-best-photo)
- [The two engines](#the-two-engines)
- [Docker](#docker)
- [Bare PHP](#bare-php)
- [PaddleOCR](#paddleocr)
- [Checking it works](#checking-it-works)
- [Settings](#settings)
- [What it does to a picture](#what-it-does-to-a-picture)
- [Limits and privacy](#limits-and-privacy)

## Which models read what

Logbook picks the way to read each file from what you have set up. There
is nothing to choose:

| File | A vision model on *Reading receipts and documents* | A text model only, and OCR working | Neither |
|---|---|---|---|
| Photo | the vision model sees the picture, and OCR checks it | OCR reads it here, then the text model reads the text | can't be read: the form opens empty with the photo attached |
| Scanned PDF | the vision model sees its pages, and OCR checks them | OCR reads it here, then the text model | as above |
| PDF with text | the *Reading text PDFs* model reads its text | the same | the same |

- *The text model* is the *Reading receipts and documents* model when it
  is a text-only model, otherwise the *Reading text PDFs* model (or, with
  none, the one for *Answering questions*, if it has JSON output).
- *OCR working* means Settings → AI → *Reading text from images* says
  **Ready**. A Tesseract that is installed but can't run counts as not
  working.
- A vision model, when you have one, is used for pictures, unless its
  connection has *Send text, not images* on, or the picture is a V5C
  going to the internet (below). Then it is given OCR's text instead.

## Scanning with a text model

What happens to a photo when there is no vision model:

1. OCR reads it on this server (Tesseract), or on your PaddleOCR service.
2. If it found too little (under 30 words, or text it was mostly unsure
   of), nothing is sent. The form opens empty with your photo attached and
   says: "Couldn't make out the text in this photo. Try a flatter,
   brighter photo."
3. Otherwise its text, line by line and numbered, goes to the text model,
   marked as OCR output that may have misread characters. Runs of 11
   digits (a V5C's reference number, and many phone numbers) are removed
   first: with spaces or punctuation between the groups (`1234 5678 901`,
   `1234-5678-901`), OCR's look-alikes (`1234 5678 9O1`), or wrapped over
   two or three lines. Numbers in another column of the same line (a date
   beside a mileage) are kept. The picture itself is never sent.
4. Every value the model gives back must point to words in that text.
   Logbook allows for OCR's usual slips (0 and O, 1, l and I, 5 and S, 8
   and B, and a couple of other differences in a long phrase). A value
   whose words aren't there is left empty, with "Not found in the file's
   text" under its field.
5. A value whose words OCR could hardly read is marked **"Hard to read,
   check this"** under its field.

The form's notice says how it was read: "Read on this server (text
recognition), then by *model* on *connection*". The Scan page says before
you upload that photos are read here first and only their text is sent.

Reading takes OCR's time plus the model's, and counts as one request: you
can't start another scan or question until it's done.

## Checking a vision model

A vision model reads receipts better than OCR does, but now and then it
**invents** a value: a plausible total, or a date that isn't printed.
With OCR working, Logbook reads the same photo with OCR while the model
reads the picture, then looks for each value in OCR's text. Preparing
the photo for OCR (a second or two for a phone photo) happens before
the model is asked; the reading itself runs alongside. Each value is:

- **Found:** its words are there, allowing for OCR's slips, **and** its
  digits are there exactly. Nothing changes.
- **Not found where OCR read clearly:** the value is kept, and marked
  **"Not found on the page, check this carefully."** under its field.
- **Not found where OCR was unsure:** no mark. OCR missing something
  proves nothing.

A mark never empties a field or stops you saving; you decide. If OCR
fails or finds too little text, the scan carries on without the check.

On a fuel receipt Logbook also checks that **the money adds up**: volume
× price per unit must be the total, within a penny and the printed
rounding. If it isn't, the three fields say so: "45.20 L × 142.9p =
£64.59, but the total reads £64.95." This check runs however the receipt
was read.

The usage log counts, for each checked scan, how many values were found,
marked and left unchecked (never the text). `php bin/ai-eval.php --scans
--crosscheck` reports the marks per kind, and how many were on values
that were right.

## Send text, not images

A photo sent to a provider on the internet carries everything printed on
it. Each connection (Settings → AI → the connection → *Edit*) has **Send
text, not images**, off by default. With it on:

- every photo and scanned PDF is read by OCR on this server, and only its
  text goes to the connection's model, with 11-digit runs removed;
- no picture is ever sent on that connection, whatever asks for it (even
  *Test* skips its image step);
- without OCR working, photos can't be read: the Scan page says so, and
  the picture is not sent instead.

It is more private and a little less accurate on faded receipts.

**V5C photos on an internet connection** always go as text when OCR
works, whether or not the option is on. Before anything is sent, OCR
reads the photo and looks for a V5C's markers ("V5C", "Registration
Certificate", "DVLA", "Document reference"); if it finds one, only the
text goes, without the reference number. So on an internet connection a
scan waits for OCR before the model starts. A V5C photo OCR can't read
well enough to spot a marker still goes as a picture, with its
reference number, and the Scan page says so on internet connections.

## Getting the best photo

OCR is more particular than a vision model:

- **Flat and square on.** Lay the receipt on a table and hold the phone
  straight above it. Logbook straightens a tilt of up to 10°, but creases
  and curls cost words.
- **Bright and even.** Daylight or a bright room; no shadow of the phone
  across the print. Thermal receipts fade: scan them soon.
- **Fill the frame**, with a little margin round the edges, and keep it
  sharp: tap to focus.
- **One document per photo.**
- A receipt photographed on a dark or busy background reads worse than
  one on white paper.

If a photo keeps coming back "Hard to read", PaddleOCR (below) copes
better with curved and creased paper.

## The two engines

| | Tesseract | PaddleOCR |
|---|---|---|
| Runs | on the Logbook server, as a program | as a separate service, reached over HTTP |
| Install | in the Docker image already; one package on Debian or Ubuntu | the compose file's `ocr` profile, or your own container |
| Size | about 80 MB with English and German | 1.5 GB (arm64) to 1.9 GB (amd64), plus its models |
| Speed | about a second a page | several seconds a page on a CPU; set `OCR_TIMEOUT=120` |
| Good at | clear prints, receipts and A4 pages; runs on a Raspberry Pi | photos, curved or creased receipts, small print |

`OCR_ENGINE=auto` (the default) uses PaddleOCR when `OCR_URL` is set, and
Tesseract otherwise. Set `OCR_ENGINE=tesseract` or `paddleocr` to choose,
or `off` to turn OCR off. If the engine you chose isn't there, Logbook
doesn't quietly use the other one: OCR is simply not available.

OCR isn't an AI feature: it works with `AI_ENABLED=false` and with no
model set up.

## Docker

The image includes Tesseract with English and German. There's nothing to
do. To turn it off, set `TESSERACT_BINARY=off` (or `OCR_ENGINE=off`).

## Bare PHP

Install Tesseract and the language for your users' locale. English is
always used alongside it.

```bash
sudo apt install tesseract-ocr tesseract-ocr-deu
```

On macOS with Homebrew, `brew install tesseract tesseract-lang`. If
`tesseract` isn't on the web server's `PATH`, set `TESSERACT_BINARY` to
its full path, such as `/opt/homebrew/bin/tesseract`.

Scanned PDFs are rendered with Ghostscript (or Imagick) first, as for AI
scanning. If the PHP Imagick extension is loaded, Logbook uses it to
prepare pictures; otherwise it uses GD, which it always needs.

## PaddleOCR

PaddleOCR runs as its own service. The compose file has an optional `ocr`
profile that builds one for your machine (amd64 or arm64):

```bash
docker compose --profile ocr up -d
```

Then set `OCR_URL=http://paddleocr:8080` (and `OCR_TIMEOUT=120`: it
takes several seconds a page on a CPU) for the Logbook container and
restart it. *Test it* then waits up to `OCR_TIMEOUT` plus 30 seconds, and
many proxies give up after 60: behind nginx raise `proxy_read_timeout`
(and `fastcgi_read_timeout` with php-fpm) to at least that, as for
[Ask Logbook](deployment.md#ask-logbook-behind-a-proxy). The first start downloads its models (a few hundred MB), so
the first scan can take a minute.

To run it elsewhere, start PaddleX's basic serving for the general OCR
pipeline (`paddlex --serve --pipeline OCR --port 8080`) and point
`OCR_URL` at it. Logbook calls `POST {OCR_URL}/ocr`. PaddleX's
"high-stability" Triton image speaks a different protocol and won't work.

Keep the service on your own network: it has no sign-in of its own.

## Checking it works

**Settings → AI** has a *Reading text from images* card; when AI is turned
off, it's under **Settings → Installation**. Its page shows:

- the engine in use, its version and its languages (for PaddleOCR, the
  address);
- *Test it*, which reads a sample receipt that comes with Logbook and
  shows the text it read, how sure it was and how long it took.

If the card says Tesseract wasn't found, check `TESSERACT_BINARY` and that
the web server's user can run it.

## Settings

| Variable | Default | |
|---|---|---|
| `OCR_ENGINE` | `auto` | `auto`, `tesseract`, `paddleocr` or `off` |
| `TESSERACT_BINARY` | `tesseract` | a name on `PATH` or a full path; `off` turns it off |
| `OCR_URL` | *(empty)* | the PaddleOCR service's address: `http://` or `https://` with a host name (`paddle_ocr` with an underscore is fine) |
| `OCR_TIMEOUT` | `30` | seconds one page may take |

An `OCR_ENGINE` other than the four values, or an `OCR_URL` that isn't an
http(s) address with a host, stops Logbook at start with a message naming
the variable (never its value, which may hold a password). A user name and
password in `OCR_URL` (`http://user:pass@paddleocr:8080`) are sent to the
service but never shown: the status page shows only the scheme, host, port
and path.

See [configuration.md](configuration.md#ai) for every setting.

## What it does to a picture

Before reading, Logbook makes a copy of the picture that OCR finds easier.
It doesn't change your attachment or what an AI model is sent:

1. turned the right way up, from the photo's orientation;
2. made greyscale, with the contrast stretched so faded print darkens;
3. straightened, if it was photographed at an angle of up to 10°;
4. enlarged if the print is very small (under 15 pixels high), so
   letters are about 30 pixels high. From 3.18 text 15–20 pixels high is
   no longer enlarged: it made Tesseract read some 0s as 6s, and the eval
   showed it lowered accuracy (`php bin/ai-eval.php --ocr
   --enlarge=min:target` compares rules on your install).

On a faded, tilted, small receipt this is the difference between reading
half of it and nearly all of it. `php bin/ai-eval.php --ocr --degraded`
measures it on your install (see [ai.md](ai.md#reading-receipts-and-documents)).

## Limits and privacy

- A PDF's first three pages are read, rendered at 300 dpi.
- Each page gives up after `OCR_TIMEOUT` seconds.
- Pictures over 50 megapixels are refused.
- Each person runs one scan or AI request at a time.
- Tesseract runs on your server, and nothing leaves it. PaddleOCR gets the
  prepared picture, so run it where you'd keep the photo.
- Scanning with a text model, or on a connection with *Send text, not
  images*, sends the model only the text, with 11-digit runs removed,
  never the picture.
- Checking a vision model sends nothing extra: OCR's text stays here.
- What OCR reads is never stored. It is used for one request and
  discarded.
