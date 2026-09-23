# Thinkfree Office for Nextcloud

The **Thinkfree Office for Nextcloud** app enables users to open, view, and edit office documents directly from [Nextcloud](https://nextcloud.com) using **Thinkfree Office**.

---

## Overview

This integration allows seamless editing and collaboration on documents, spreadsheets, and presentations within Nextcloud through Thinkfree Office.  
All files remain securely stored in your Nextcloud environment.

---

## Features

- Create, view, and edit documents, spreadsheets, and presentations
- Real-time co-editing with comments and change tracking
- Full compatibility with Microsoft Office and OpenDocument formats

**Supported file formats**

| Type | Formats |
|------|----------|
| Editable | `.doc`, `.docx`, `.rtf`, `.xls`, `.xlsx`, `.ppt`, `.pptx` |
| View only | `.txt`, `.csv` |
| Coming soon | `.odt`, `.ods`, `.odp` |

---

## Prerequisites

Before setting up the integration, you need access to a **Thinkfree Office Server** that is reachable from both:
- The **Nextcloud server**, and
- **User browsers** (clients)

You can:
- [Request a free trial package and license](https://thinkfree.com/thinkfree-office/pricing/free-license/), or
- Deploy Thinkfree Office on your own server or private cloud.

After deploying your Thinkfree Office server, install the **Nextcloud Adapter** to enable communication between Thinkfree Office and Nextcloud.

**Download Nextcloud Adapter:**  
[Download](https://drive.google.com/file/d/1hY9l6jZE6rkFyriwRp7h0pU-1yWUxGW_/view?usp=sharing)

---

## Installation

### 1. Install the Thinkfree Office App in Nextcloud
1. Log in to Nextcloud as an **administrator**.
2. Go to **Profile Menu → Apps**.
3. Find **Thinkfree Office** in the list of available applications.
4. Click **Install**.

---

## Configuration

Once installed, open the Thinkfree Office configuration page in Nextcloud:

```
Settings → Administration → Thinkfree
```

### Configure the Thinkfree Office Server Address

Enter the URL of your Thinkfree Office server:
```
https://[your-thinkfree-server-address]/
```

> Replace `[your-thinkfree-server-address]` with your actual Thinkfree Office server address.  
> The server must be accessible from both the Nextcloud instance and end-user browsers.

If you do not have your own Thinkfree Office deployment, you can use the **default public test server**.

### Configure the JWT Secret

Set the **JWT Secret** value, which is used to securely authenticate and encrypt communication between Nextcloud and Thinkfree Office.

Once saved, the **“Open in Thinkfree Office”** action will automatically appear in the Nextcloud file context menu for supported file types.

---

## Usage

1. In Nextcloud Files, right-click on a document and select **Open in Thinkfree Office**.
2. A new browser tab opens and loads the file using your configured Thinkfree Office server.
3. Edit collaboratively in real time.
4. When finished, all changes are saved securely back to Nextcloud.

---

## How It Works

1. When a document is opened, Nextcloud sends a secure request (signed with the JWT secret) to the Thinkfree Office server.
2. The document is downloaded from Nextcloud and opened for editing in Thinkfree Office.
3. Upon completion, Thinkfree Office synchronizes all changes back to Nextcloud.
4. No data is stored outside your environment (if self-hosted).

---

## Additional Resources

- Full setup guide:  
  [How to set up Thinkfree Office for Nextcloud](https://cs.thinkfree.com/en/support/solutions/articles/158000282045-how-to-set-up-thinkfree-office-for-nextcloud)

- Product information:  
  [Thinkfree Office Website](https://www.thinkfree.com)

## Maintainer

Goochul Im ([@goochulim-thinkfree-com](https://github.com/goochulim-thinkfree-com)) — goochul.im@thinkfree.com, Thinkfree Inc.

---

Thinkfree Inc. All rights reserved.
