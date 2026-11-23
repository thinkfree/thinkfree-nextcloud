# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 3.1.0 – 2026-11-18

### Added

- Link previews: links to a Thinkfree Office document (`/apps/thinkfree/edit/{fileId}`, `/view/{fileId}`) now open the document inline in view mode wherever Nextcloud renders link previews, such as Talk, Text and the Assistant chat. Users who cannot access the file see no file name.
- Smart Picker entry "Thinkfree Office document" to insert a link to a file chosen from Nextcloud.
- Admin templates: administrators can upload a Word, Excel and PowerPoint template, or pick one from their Nextcloud files. Every new document of that type is then created from the template, for example a presentation with the company theme. Templates are validated by their content and limited to 20 MB.
- Built-in blank templates for Simplified Chinese, Traditional Chinese and Spanish.
- Clicking the name of a `.docx`, `.xlsx` or `.pptx` file in the Files app opens it in Thinkfree Office directly. Other supported formats, such as PDF and CSV, keep opening in the Nextcloud viewer and can still be opened from the "Open in Thinkfree Office" menu entry.
- A newly created document opens in the editor right away.

### Changed

- Creating a new document now requires a CSRF token.

### Fixed

- The file action is no longer offered in the trash bin, where files cannot be opened.

## 3.0.1 – 2026-10-14

### Added

- Host pages `/apps/thinkfree/edit/{fileId}` and `/apps/thinkfree/view/{fileId}`. They issue a new access token and reopen the editor, so the web office can restart a session after the token has expired.
- `HostEditUrl` and `HostViewUrl` in CheckFileInfo.

### Changed

- WOPI requests are verified in a single middleware. Failures now return the status code required by the WOPI specification: 401 for an invalid token or account, 404 for a missing file and 500 for a proof key mismatch. Previously, every failure returned 401.

## 3.0.0 – 2026-10-07

### Added

- WOPI integration with Thinkfree Office. Documents open in a new tab from the "Open in Thinkfree Office" file action and from the "New" menu.
- WOPI host operations: CheckFileInfo, GetFile, PutFile, PutRelativeFile, DeleteFile, EnumerateAncestors, EnumerateChildren, CreateChildFile, CheckContainerInfo, GetRootContainer, CheckEcosystem and GetEcosystem.
- Access tokens are signed with a key derived from the instance secret, and every request from the web office is verified with WOPI proof keys.
- The web office address is checked when it is saved, and the settings page explains why a connection failed.
- Administrators are notified, and a setup warning is shown in the admin overview, while no web office address is configured.
- Support for Nextcloud 33 and the Files app API v4.

### Changed

- The web office address is configured once by the administrator. The personal settings page has been removed.
- The editor is opened with a form POST, and the access token is sent in the request body instead of the URL.
- PutFile and PutRelativeFile check the file size against the user's free space and return 413 when it is exceeded.
- Supported Nextcloud versions: 31 to 35.

### Removed

- The previous custom (non-WOPI) integration.
