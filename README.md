# CHWEB Social (cw-social)

Publishes your pages on **Facebook** (a link post to the page) and
**Instagram** (the page's cover image with your text) through the Meta Graph
API. You tick a switch while editing the page; the plugin does the rest.

Sharing a new article usually means doing the same thing three times: write
it, then open Facebook, then open Instagram — and by the third window the
wording has drifted. The alternative most sites reach for is a feed
auto-poster, which fires the moment a page is saved, including the save where
you fixed the typo. This plugin sits in between: the page itself carries the
decision and the state, nothing goes out twice, and a page scheduled for next
Monday goes out next Monday.

## What it posts

| Channel | Post |
|---|---|
| Facebook | A link post. Facebook fetches title and preview image from the page itself. |
| Instagram | The cover image with the text. Instagram does not make links clickable, so the address is appended as plain text. |

Which pages qualify is configuration, not guesswork: the children of the
routes you name (`/news`, `/events`) that use the page templates you name
(`item`, `event`). Everything else never shows a share tab.

## Setup

1. **Create a Meta app** at [developers.facebook.com](https://developers.facebook.com/),
   type *Business*, with an account that administers the Facebook page.
2. **Get a page access token:** Graph API Explorer → your app → a user token
   with `pages_manage_posts` and `pages_read_engagement`, plus
   `instagram_basic` and `instagram_content_publish` if you want Instagram →
   query `/me/accounts` with it → copy the page token of the target page →
   extend it to a long-lived token in the Access Token Debugger.
3. **Connect Instagram** (optional): the account must be a **Business or
   Creator** account and must be linked to the Facebook page under *Linked
   accounts*. The plugin reads its ID by itself.
4. **Configure the plugin:** Admin → Plugins → *CHWEB Social*. Page ID,
   token, the **public site URL** (never localhost, never a staging domain),
   the routes and page templates, Instagram on or off, plugin enabled.
5. **Make sure Grav's scheduler runs.** That is how the plugin posts: Admin →
   Tools → Scheduler shows the cron line to install, and the job
   *cw-social-share* appears there once the plugin is enabled.
6. **Test:** `bin/plugin cw-social test` prints the page name and, if
   enabled, the connected Instagram account.

## Using it

While editing a page, the **Share** tab carries two switches — *Share on
Facebook*, *Share on Instagram* — and an optional text of your own. Below
them a **preview** shows what each post will look like. It follows every
change to title, summary, date, cover image and text **before you save**.

The post goes out once the page is published and its date has arrived, on the
next run of the scheduler job (every 15 minutes by default). The same run can
be triggered by hand:

```bash
bin/plugin cw-social share            # dry run: what would go out
bin/plugin cw-social share --force    # actually post
```

A page carrying `draft: true` (or `entwurf: true`) in its front matter is left
alone for as long as that flag is there.

Afterwards the preview shows, per channel, *shared on … · view post*. The post
IDs are stored invisibly in the page front matter (`cw_social.fb_post_id`,
`ig_post_id`, `ig_permalink` and timestamps) **and** in a journal under
`user/data/cw-social/protokoll.json`. Either one is enough to keep a page from
going out twice, which matters when `user/pages` is read-only — on a
Git-deployed site the front matter cannot be written at all. If you do want it
to go out again — as a reminder shortly before an event, say — the **Share
again** switch sends it on the next run and then resets itself to No.

### What has to be true before anything is posted

Three things, checked on every run, in the scheduler and on the command line
alike. If one of them is missing, nothing is posted and the reason is in the
Grav log and in the preview:

* **`site_url` is set.** There is deliberately no fallback to the host that
  happens to be calling. A copy of the site on a staging domain that carries
  the token would otherwise publish posts linking to that staging domain.
* **The page ID and the token are there.**
* **`user/data/cw-social/` is writable.** That is where the journal lives, and
  the journal is what keeps a failed run from posting the same page again.

Each page and channel is tried at most **three times** (`max_attempts`). After
that the channel is left alone and the reason stays in the log. Without that
brake, an image Instagram will never accept keeps every run waiting for it.

## Without a scheduler

On an installation with no cron, `auto_share: true` lets the plugin check
during a page request instead — throttled to once every few minutes and under
a lock, so two simultaneous requests cannot both post. It is **off by
default**: it makes a visitor's request slower, and it cannot wait as long as
Instagram sometimes needs.

## What Instagram requires

* **An image on the page**: the cover image, or else the first image found.
  The plugin serves it to Instagram as JPEG through the public site URL —
  Instagram rejects PNG and WebP, so they are converted.
* **An aspect ratio** between 4:5 (0.80) and 1.91:1, at least 320 pixels wide.
  The plugin measures the image itself and does not even open a media
  container for one that cannot work; the reason goes to the Grav log and that
  channel is not tried again for this page.
* The plugin waits up to nine seconds for Instagram to process the image. If
  it takes longer, the next run picks it up again.

## Staging and development

Nothing may ever be posted from a workstation or a staging domain. Two things
see to that, and the first needs no setting at all: **without `site_url`
nothing is posted**, and a copy of the site normally has no `site_url` of its
own. Where a copy did inherit the live configuration, put this in
`user/env/<host>/config/plugins/cw-social.yaml`:

```yaml
scheduler: false
auto_share: false
```

The tabs and switches stay visible, nothing goes out.

## Why a scheduler job instead of a save hook

Posting is not hooked into the save. That way sharing works no matter *how* a
page comes into being — the classic admin, the API, a file upload, a Git sync
— and a publication date in the future is actually waited for instead of being
posted the moment somebody presses save.

## The preview field

The preview is a single Web Component in `admin-next/fields/`, loaded by
Admin 2.0 like any other field file. It evaluates no code of its own, so it
also works where Admin 2.0 runs with a Content Security Policy. One
consequence worth knowing: after updating the plugin, the admin needs one
reload before a new preview takes effect.

It reads the neighbouring fields out of the form, because Admin 2.0 hands a
Web Component only its own value. When it cannot recognise them — a changed
admin markup would do it — it says so and falls back to previewing the saved
state instead of showing nothing.

The endpoint behind it (`POST /cw-social/vorschau`) requires `api.pages.read`
and honours the page's own access rules.

## Requirements

Grav ≥ 1.7, tested on Grav 1.7 and 2.0. PHP 7.4 or newer with cURL and GD.

## Licence

MIT — see [LICENSE](LICENSE). © CHWEB GmbH — https://chweb.ch
