# Reading text from images (OCR)

How Logbook turns a photo or a scanned PDF into text on your own server,
and how to set it up.

**For:** whoever installs and runs Logbook.

Logbook can already read a receipt or invoice with an AI model (see
[ai.md](ai.md#reading-receipts-and-documents)). From 3.17 it can also read the
text itself with an OCR engine, without sending the picture anywhere. This
release adds the engine and a check in Settings. Nothing you see when
scanning changes yet: later releases use it to scan with a text-only model,
to check what a vision model read, and to fill a fill-up with no AI at all.

- [The two engines](#the-two-engines)
- [Docker](#docker)
- [Bare PHP](#bare-php)
- [PaddleOCR](#paddleocr)
- [Checking it works](#checking-it-works)
- [Settings](#settings)
- [What it does to a picture](#what-it-does-to-a-picture)
- [Limits and privacy](#limits-and-privacy)

## The two engines

| | Tesseract | PaddleOCR |
|---|---|---|
| Runs | on the Logbook server, as a program | as a separate service, reached over HTTP |
| Install | in the Docker image already; one package on Debian or Ubuntu | the compose file's `ocr` profile, or your own container |
| Size | about 40 MB with English and German | about 2 GB with its models |
| Good at | clear prints, receipts and A4 pages; runs on a Raspberry Pi | photos, curved or creased receipts, small print; much slower on a Pi |

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

Then set `OCR_URL=http://paddleocr:8080` for the Logbook container and
restart it. The first start downloads its models (a few hundred MB), so
the first scan can take a minute.

To run it elsewhere, start PaddleX's basic serving for the general OCR
pipeline (`paddlex --serve --pipeline OCR --port 8080`) and point
`OCR_URL` at it. Logbook calls `POST {OCR_URL}/ocr`. PaddleX's
"high-stability" Triton image speaks a different protocol and won't work.

Keep the service on your own network: it has no sign-in of its own.

## Checking it works

**Settings → AI** (or **Settings → System** when AI is turned off) shows a
*Reading text from images* card with:

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
| `OCR_URL` | *(empty)* | the PaddleOCR service's address |
| `OCR_TIMEOUT` | `30` | seconds one page may take |

See [configuration.md](configuration.md#ai) for every setting.

## What it does to a picture

Before reading, Logbook makes a copy of the picture that OCR finds easier.
It doesn't change your attachment or what an AI model is sent:

1. turned the right way up, from the photo's orientation;
2. made greyscale, with the contrast stretched so faded print darkens;
3. straightened, if it was photographed at an angle of up to 10°;
4. enlarged if the print is small, so letters are about 30 pixels high.

On a faded, tilted, small receipt this is the difference between reading
nothing and reading every line. `php bin/ai-eval.php --ocr` measures it on
your install (see [ai.md](ai.md)).

## Limits and privacy

- A PDF's first three pages are read, rendered at 300 dpi.
- Each page gives up after `OCR_TIMEOUT` seconds.
- Pictures over 50 megapixels are refused.
- Each person runs one scan or AI request at a time.
- Tesseract runs on your server, and nothing leaves it. PaddleOCR gets the
  prepared picture, so run it where you'd keep the photo.
- What OCR reads is never stored. It is used for one request and
  discarded.
