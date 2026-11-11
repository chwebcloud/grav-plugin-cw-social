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
5. **Test:** `bin/plugin cw-social test` prints the page name and, if
   enabled, the connected Instagram account.

## Using it

While editing a page, the **Share** tab carries two switches — *Share on
Facebook*, *Share on Instagram* — and an optional text of your own. Below
them a **preview** shows what each post will look like. It follows every
change to title, summary, date, cover image and text **before you save**.

The post goes out once the page is published and its date has arrived, either
through the built-in worker (throttled, five minutes by default) or from the
command line:

```bash
bin/plugin cw-social share --force
```

A page carrying `draft: true` (or `entwurf: true`) in its front matter is left
alone for as long as that flag is there.

Afterwards the preview shows, per channel, *shared on … · view post*. The post
IDs are stored invisibly in the page front matter (`cw_social.fb_post_id`,
`ig_post_id`, `ig_permalink` and timestamps), which is what keeps a later edit
from posting the page a second time. If you do want it to go out again — as a
reminder shortly before an event, say — the **Share again** switch sends it on
the next save and then resets itself to No.

## What Instagram requires

* **An image on the page**: the cover image, or else the first image found.
  The plugin serves it to Instagram as JPEG through the public site URL —
  Instagram rejects PNG and WebP, so they are converted.
* **An aspect ratio** between 4:5 (portrait) and 1.91:1 (landscape), at least
  320 pixels wide. A panorama is refused; the error then appears in the Grav
  log and the ID in the tab stays empty.
* The plugin waits up to nine seconds for Instagram to process the image. If
  it takes longer, the next run picks it up again.

## Staging and development

Nothing may ever be posted from a workstation or a staging domain. Put this
in `user/env/<host>/config/plugins/cw-social.yaml` there:

```yaml
auto_share: false
```

The tabs and switches stay visible, the worker stays quiet. Only the live
environment posts — and only when `site_url` is the live address, otherwise
the posts point nowhere.

## Why a worker instead of a save hook

The worker checks at runtime rather than hooking into the save. That way
sharing works no matter *how* a page comes into being — the classic admin, the
API, a file upload, a Git sync — and a publication date in the future is
actually waited for instead of being posted the moment somebody presses save.

## Requirements

Grav ≥ 1.7, tested on Grav 1.7 and 2.0. PHP with cURL and GD.

## Licence

MIT — see [LICENSE](LICENSE). © CHWEB GmbH — https://chweb.ch
