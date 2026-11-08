# Tory

Tory is a household inventory that answers one question: where is that thing? This file defines the words we use for it. The app's interface is German, so each term gives its German word and the English name used in code.

## Language

### Places

**Location** (Lagerort):
A named area that groups spots, such as "Keller", "Garage" or "Ferienwohnung". It exists for orientation; things never lie directly in a location, and a location has no code or label of its own.
_Avoid_: Storage location, area, site, room

**Spot** (Lagerplatz):
The concrete place where items lie, such as "Regal 3, Fach 2" or "Kiste Weihnachten". Every spot belongs to exactly one location and can move to another one; its name need not be unique.
_Avoid_: Storage location, Lagerort (for this meaning), bin, slot

**Code**:
The permanent four-character identifier of a spot, such as `K7XM`. It is what tells two equally named spots apart and what a spot's label points to.
_Avoid_: Number, Nr., ID

**Label** (Etikett):
The printed tag on a spot, carrying its code, name, a QR code and the address the QR code leads to. It leaves out the location, so it stays correct when the spot moves.
_Avoid_: Sticker, tag

### People

**Administrator**:
A user who may manage other users: invite them, change roles, lock and unlock them. There is always at least one; an administrator cannot lock or demote themselves, so the household always keeps at least one.
_Avoid_: Admin (in the UI), owner

**User** (Nutzer):
A person who can log in and sees the whole household inventory. Everyone who is not an administrator is a plain user.
_Avoid_: Mitglied, Member (in the UI)

**Invite link** (Einladungslink):
A single-use address, valid for 24 hours, through which an invited person chooses a password and so joins. A new link replaces the old one.
_Avoid_: Registrierungslink, Token

**Locked** (gesperrt):
A user who can no longer log in or use any connection, but whose data stays. An administrator can unlock them again.
_Avoid_: Deleted, deactivated, banned

### Things

**Item** (Gegenstand):
A single inventoried thing with photos, a title and a description. An item always lies on exactly one spot; its location follows from that spot.
_Avoid_: Object, article, Artikel

**Import**:
The 1–20 photos of one thing on their way to becoming an item, together with the texts the AI suggested for them.
_Avoid_: Upload, capture, Erfassung

**Bulk import** (Bulkimport):
Many photos of several things on their way to becoming imports. The AI groups them, the user reviews the groups and confirms, and each group becomes an import.
_Avoid_: batch, Sammelimport

**Search term** (Suchbegriff):
A word or phrase attached to an item or import so that it can be found by words that its title and description do not contain.
_Avoid_: Tag, keyword

**Note** (Bemerkung):
Free text the user writes on an item, such as "ohne Netzteil" or "Dongle fehlt". The AI never writes or changes it, and search does not use it.
_Avoid_: Comment, Kommentar, remark

**API token**:
A long-lived secret that one device of the mobile app uses to act as a user on the REST API. It is named after the device and never expires.
_Avoid_: Session, API key (that is a provider's credential)

**Provider** ("Eigene KI"):
An AI service configured in the app, with its vendor, model(s) and credentials. It can serve object recognition, search or both. At most one is active for each role, and they may differ.
_Avoid_: Model, vendor (for the configured service)

**Claude connection** (Claude-Zugang):
One program (Claude in the browser, the app, Desktop or Code) that a user has allowed to act as them on Tory's MCP server. It lasts until the user ends it with "Trennen".
_Avoid_: Session, login, API token (that is the mobile app's secret)
