# Thinkfree Office for Nextcloud

The **Thinkfree Office for Nextcloud** app enables users to open, view, and edit office documents directly from [Nextcloud](https://nextcloud.com) using **Thinkfree Office**.

---

## Overview

This app allows seamless editing and collaboration on documents, spreadsheets, and presentations within Nextcloud through Thinkfree Office.  
All files remain securely stored in your Nextcloud environment.

The connector implements the standard **WOPI** protocol. It makes Nextcloud act as
a WOPI host, so no adapter, plugin, or shared secret has to be installed or
configured on either side — only the address of your Thinkfree Office server.

---

## Features

- Create, view, and edit documents, spreadsheets, and presentations
- Real-time co-editing with comments and change tracking
- Full compatibility with Microsoft Office and OpenDocument formats

---

## Requirements

- **Nextcloud 31 – 35**
- **Thinkfree Office Server v17 or later**

You can:
- [Request a free trial package and license](https://thinkfree.com/thinkfree-office/pricing/free-license/), or
- Deploy Thinkfree Office on your own server or private cloud.

---

## Installation

### Install the Thinkfree Office App in Nextcloud

1. Log in to Nextcloud as an **administrator**.
2. Go to **Profile Menu → Apps**.
3. Find **Thinkfree Office** in the list of available applications.
4. Click **Install**.

---

## Configuration

There is a single setting, and it applies to every user on the instance.

```
Settings → Administration → Thinkfree Office
  → Server settings → "Thinkfree Office Server Address"
```

Enter the URL of your Thinkfree Office server:

```
https://office.example.com
```

Click **Save**. The connector immediately fetches the discovery document from
that address and reports the result:

| Result | Meaning |
|---|---|
| **WOPI integration enabled** | The server answered and the integration is ready to use. |
| Saved, but the server could not be reached | The address was stored, but users will not see the **Open in Thinkfree Office** entry. The message explains what failed. |

---

## Usage

1. In Nextcloud Files, right-click on a document and select **Open in Thinkfree Office**.
2. A new browser tab opens and loads the file using your configured Thinkfree Office server.
3. Edit collaboratively in real time.
4. When finished, all changes are saved securely back to Nextcloud.

---

## How It Works

1. Nextcloud fetches the discovery document from the Thinkfree Office server to
   learn which formats it supports and which editor URL to use. The result is
   cached, so this does not happen on every page load.
2. When a user opens a file, Nextcloud issues a short-lived access token and opens
   Thinkfree Office in a new browser tab, pointing it back at Nextcloud.
3. Thinkfree Office calls the Nextcloud WOPI endpoints to read the file
   information, download the content, and save changes.
4. Documents are only ever exchanged between your Nextcloud instance and the
   Thinkfree Office server you configured.

### Security

Access tokens are signed with your Nextcloud instance secret and are scoped to a
single file, a single user, and a limited lifetime. There is no shared secret to
configure or keep in sync.

Incoming WOPI requests are additionally verified against the **proof key** your
Thinkfree Office server publishes in its discovery document, which confirms that
a request genuinely originates from that server. This verification is always on.

---

## Troubleshooting

**The "Open in Thinkfree Office" entry is missing.**  
Open the administration settings and save the server address again. The result
message shown there explains what failed.

**The editor opens but the document does not load.**  
Thinkfree Office could not reach Nextcloud. Check that the Nextcloud address is
resolvable and reachable *from the Thinkfree Office server*, not only from your
browser. For example, if Nextcloud runs in a container and you set the Thinkfree
Office address to `localhost`, consider using `http://host.docker.internal:[port]`
instead.

---

## Additional Resources

- Product information:  
  [Thinkfree Office Website](https://www.thinkfree.com)

---

Thinkfree Inc. All rights reserved.
