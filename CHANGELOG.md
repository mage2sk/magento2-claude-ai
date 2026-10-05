# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.9.3] - 2026-10-05

### Security
- The chat send, stream and upload endpoints now reject any request without a valid admin form key (403) before doing any work. Previously the form key check was skipped for these endpoints.
- The Chats list on the chat page only shows the signed-in admin's own conversations, the same rule the chat loader uses. Other admins' first messages are no longer listed.
- Checkpoint Restore and Training Delete now send a POST with the admin form key after an admin confirmation dialog. The controllers accept POST only, so opening their URLs with GET returns 404. ACL checks are unchanged.

### Fixed
- Chat page and header assistant panel: the message box has an accessible name, the reply feed is announced to screen readers as a live log, and closing the panel with Esc returns focus to the launcher button.
- Training Examples, Activity Log, Checkpoints and Conversations tables have padded header cells.
- Edit Training Example labels are linked to their fields, and all required fields show the required marker.
- With an API key saved but the module switched off, the dashboard and chat pages no longer ask for an API key; the dashboard says the module is turned off.
- Confirmation Threshold only shows when Require Confirmation Before Writes is Yes, rejects negative numbers, and its help text matches the rule (confirmation is asked above the threshold).

### Changed
- The API client sends requests through a single transport method, so request building, error handling and timeouts are covered by unit tests with a mocked transport.
